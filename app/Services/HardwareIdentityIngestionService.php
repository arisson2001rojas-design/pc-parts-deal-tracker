<?php

namespace App\Services;

use App\Dto\HardwareEvidence;
use App\Dto\IdentityIngestionResult;
use App\Dto\IdentityResolution;
use App\Enums\IdentityResolutionState;
use App\Exceptions\IdentityClaimRaceException;
use App\Models\DealOffer;
use App\Models\HardwareIdentity;
use App\Models\HardwareIdentityClaim;
use App\Models\PcPart;
use App\Models\RetailerListing;
use App\Models\Url;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds semantic identity links without merging or re-parenting tracked data.
 *
 * A verified result may create/link identity records. Every other state only
 * records inspectable evidence on the retailer listing and tracking continues.
 */
final class HardwareIdentityIngestionService
{
    public function __construct(
        private readonly HardwareEvidenceNormalizer $normalizer,
        private readonly HardwareIdentityResolver $resolver,
    ) {}

    /**
     * @param  array{
     *     slug: string,
     *     store: string,
     *     identifier: string,
     *     product_key: string,
     *     url: string,
     *     identifier_type: string,
     *     external_identifier: string,
     *     listing_key: string,
     *     listing_key_hash: string,
     *     normalized_url: string
     * }  $listingData
     */
    public function ingest(
        array $listingData,
        HardwareEvidence $evidence,
        ?PcPart $part = null,
        ?Url $url = null,
        ?DealOffer $offer = null,
        ?string $seller = null,
    ): IdentityIngestionResult {
        $listingData = $this->validatedListingData($listingData);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return DB::transaction(
                    fn (): IdentityIngestionResult => $this->ingestLocked(
                        $listingData,
                        $evidence,
                        $part,
                        $url,
                        $offer,
                        $seller,
                    ),
                    attempts: 3,
                );
            } catch (IdentityClaimRaceException $exception) {
                if ($attempt === 3) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Hardware identity ingestion exhausted its claim-race retries.');
    }

    /**
     * @param  array<string, mixed>  $listingData
     */
    private function ingestLocked(
        array $listingData,
        HardwareEvidence $evidence,
        ?PcPart $part,
        ?Url $url,
        ?DealOffer $offer,
        ?string $seller,
    ): IdentityIngestionResult {
        $now = now();
        RetailerListing::query()->createOrFirst([
            'listing_key_hash' => $listingData['listing_key_hash'],
        ], [
            'retailer_key' => $listingData['slug'],
            'identifier_type' => $listingData['identifier_type'],
            'external_identifier' => $listingData['external_identifier'],
            'canonical_url' => $listingData['url'],
            'normalized_url' => $listingData['normalized_url'],
            'url_hash' => hash('sha256', $listingData['url']),
            'title' => Str::limit((string) ($evidence->title ?? ''), 1024, ''),
            'seller' => $seller,
            'marketplace' => $evidence->marketplace,
            'resolution_state' => IdentityResolutionState::Unverified->value,
            'evidence' => $evidence->toArray(),
        ]);

        $listing = RetailerListing::query()
            ->where('listing_key_hash', $listingData['listing_key_hash'])
            ->lockForUpdate()
            ->firstOrFail();
        $this->assertListingIdentity($listing, $listingData);
        [$part, $url, $offer] = $this->refreshAssociations($part, $url, $offer);

        $candidates = $this->candidateIdentities($evidence, $listing, $part);
        $resolution = $this->resolveEvidence($evidence, $candidates);
        $resolution = $this->guardExistingListingAssociation($resolution, $listing);
        $creatingIdentity = $resolution->state === IdentityResolutionState::Verified
            && $resolution->matchedIdentityId === null
            && $resolution->suggestedAction === 'create_identity';
        $associationConflict = $this->associationConflict(
            $part,
            $url,
            $offer,
            identity: null,
            listing: $listing,
            creatingIdentity: $creatingIdentity,
        );

        $identity = null;
        if ($associationConflict === null && $resolution->state === IdentityResolutionState::Verified) {
            $identity = $resolution->matchedIdentityId === null
                ? null
                : $candidates->firstWhere('id', $resolution->matchedIdentityId);
            if (! $identity instanceof HardwareIdentity && $creatingIdentity) {
                $identity = $this->createOrFindIdentity($evidence);
                $resolution = new IdentityResolution(
                    state: IdentityResolutionState::Verified,
                    matchedIdentityId: $identity->getKey(),
                    candidateIds: [$identity->getKey()],
                    signals: $resolution->signals,
                    reason: $resolution->reason,
                    suggestedAction: 'link_listing',
                );
            }
            if (! $identity instanceof HardwareIdentity
                && $listing->hardware_identity_id === $resolution->matchedIdentityId) {
                $identity = $listing->hardwareIdentity;
            }
            $associationConflict = $this->associationConflict($part, $url, $offer, $identity, $listing);
        }

        if ($associationConflict !== null) {
            $resolution = new IdentityResolution(
                state: IdentityResolutionState::Conflicting,
                candidateIds: $identity instanceof HardwareIdentity ? [$identity->getKey()] : $resolution->candidateIds,
                signals: $resolution->signals,
                conflicts: [$associationConflict],
                reason: 'An existing additive identity link contradicts the proposed association.',
                suggestedAction: 'manual_review',
            );
            $identity = null;
        }

        if ($identity instanceof HardwareIdentity) {
            $this->recordClaims($identity, $evidence);
        }

        $attributes = [
            'canonical_url' => $listingData['url'],
            'normalized_url' => $listingData['normalized_url'],
            'url_hash' => hash('sha256', $listingData['url']),
            'title' => Str::limit((string) ($evidence->title ?? $listing->title ?? ''), 1024, ''),
            'seller' => filled($seller) ? Str::limit($seller, 255, '') : $listing->seller,
            'marketplace' => $evidence->marketplace,
            'resolution_state' => $resolution->state,
            'resolution_reason' => Str::limit($resolution->reason, 65535, ''),
            'evidence' => $evidence->toArray(),
            'decision_trace' => $resolution->toArray(),
            'resolved_at' => $resolution->state === IdentityResolutionState::Verified ? $now : null,
        ];
        if ($identity instanceof HardwareIdentity) {
            $attributes['hardware_identity_id'] = $identity->getKey();
        }
        $listing->forceFill($attributes)->save();

        // A concurrent conflicting CAS must roll the whole transaction back.
        // Persisting only the conflict audit here could leave the identity and
        // its authoritative claims orphaned from the model that won the race.
        $this->linkModels($part, $url, $offer, $identity, $listing);

        return new IdentityIngestionResult($listing->fresh(), $identity?->fresh(), $resolution);
    }

    /**
     * Resolve against existing identities without writing any record.
     */
    public function preview(HardwareEvidence $evidence, ?RetailerListing $listing = null): IdentityResolution
    {
        $candidates = $this->candidateIdentities($evidence, $listing, null);

        return $this->guardExistingListingAssociation(
            $this->resolveEvidence($evidence, $candidates),
            $listing,
        );
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, HardwareIdentity> */
    private function candidateIdentities(
        HardwareEvidence $evidence,
        ?RetailerListing $listing,
        ?PcPart $part,
    ): \Illuminate\Database\Eloquent\Collection {
        $query = HardwareIdentity::query();
        $query->where(function (Builder $query) use ($evidence, $listing, $part): void {
            $hasCondition = false;
            if ($listing?->hardware_identity_id !== null) {
                $query->whereKey($listing->hardware_identity_id);
                $hasCondition = true;
            }
            if ($part?->hardware_identity_id !== null) {
                if ($hasCondition) {
                    $query->orWhere($query->qualifyColumn('id'), $part->hardware_identity_id);
                } else {
                    $query->whereKey($part->hardware_identity_id);
                }
                $hasCondition = true;
            }
            if ($evidence->authoritativeKeyHash() !== null) {
                $method = $hasCondition ? 'orWhere' : 'where';
                $query->{$method}('authoritative_key_hash', $evidence->authoritativeKeyHash());
                $hasCondition = true;
            }
            if ($evidence->variantFingerprint() !== null) {
                $method = $hasCondition ? 'orWhere' : 'where';
                $query->{$method}('variant_fingerprint', $evidence->variantFingerprint());
                $hasCondition = true;
            }
            if ($evidence->mpns !== []) {
                $claimHashes = array_map(
                    fn (string $mpn): string => $this->claimHash($evidence->manufacturer, 'mpn', $mpn),
                    $evidence->mpns,
                );
                $method = $hasCondition ? 'orWhereHas' : 'whereHas';
                $query->{$method}('claims', fn (Builder $claims): Builder => $claims->whereIn('claim_key_hash', $claimHashes));
                $hasCondition = true;
            }
            if (! $hasCondition) {
                $query->whereRaw('1 = 0');
            }
        });

        return $query->orderBy('id')->get();
    }

    private function createOrFindIdentity(HardwareEvidence $evidence): HardwareIdentity
    {
        $hash = $evidence->authoritativeKeyHash();
        if ($hash === null) {
            throw new \LogicException('A hardware identity requires an authoritative manufacturer/MPN key.');
        }

        $primaryMpn = $evidence->mpns[0];
        $attributes = $evidence->attributes;
        $attributes['mpns'] = $evidence->mpns;
        ksort($attributes, SORT_STRING);
        try {
            return HardwareIdentity::query()->create([
                'authoritative_key_hash' => $hash,
                'component_type' => $evidence->componentType,
                'manufacturer' => $evidence->manufacturer,
                'manufacturer_normalized' => $evidence->manufacturer,
                'model' => $evidence->model,
                'model_normalized' => $evidence->model,
                'mpn' => $primaryMpn,
                'mpn_normalized' => $primaryMpn,
                'variant_fingerprint' => $evidence->variantFingerprint(),
                'attributes' => $attributes,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            // Never assume that the winner of the authoritative-key race is
            // compatible. Retry the entire transaction so the resolver sees
            // its critical variant evidence in a fresh snapshot.
            throw new IdentityClaimRaceException(
                'An authoritative hardware identity was concurrently created.',
                previous: $exception,
            );
        }
    }

    private function recordClaims(HardwareIdentity $identity, HardwareEvidence $evidence): void
    {
        foreach ($evidence->mpns as $mpn) {
            $claimHash = $this->claimHash($evidence->manufacturer, 'mpn', $mpn);
            $existing = HardwareIdentityClaim::query()->where('claim_key_hash', $claimHash)->first();
            if ($existing instanceof HardwareIdentityClaim) {
                if ($existing->hardware_identity_id !== $identity->getKey()) {
                    throw new IdentityClaimRaceException('An authoritative MPN claim belongs to another identity.');
                }

                continue;
            }
            try {
                $claim = HardwareIdentityClaim::query()->create([
                    'claim_key_hash' => $claimHash,
                    'hardware_identity_id' => $identity->getKey(),
                    'claim_type' => 'mpn',
                    'value' => $mpn,
                    'normalized_value' => $mpn,
                    'source' => 'identity_ingestion',
                    'verified' => true,
                    'evidence' => ['manufacturer' => $evidence->manufacturer],
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                // On MySQL 5.7 the losing transaction can observe its own
                // snapshot before the concurrent claim commits. Retry the
                // whole resolution in a fresh transaction instead.
                throw new IdentityClaimRaceException(
                    'An authoritative MPN claim was concurrently assigned.',
                    previous: $exception,
                );
            }
            if ($claim->hardware_identity_id !== $identity->getKey()) {
                throw new IdentityClaimRaceException('An authoritative MPN claim was concurrently assigned to another identity.');
            }
        }
    }

    private function evidenceForIdentity(HardwareIdentity $identity): HardwareEvidence
    {
        $identity->loadMissing('claims');
        $attributes = $identity->attributes ?? [];
        $mpns = $identity->claims
            ->where('claim_type', 'mpn')
            ->pluck('normalized_value')
            ->filter()
            ->values()
            ->all();
        $mpns = $mpns !== [] ? $mpns : ($attributes['mpns'] ?? array_filter([$identity->mpn_normalized]));
        unset($attributes['mpns']);

        return $this->normalizer->fromArray([
            'component_type' => $identity->component_type,
            'manufacturer' => $identity->manufacturer_normalized ?? $identity->manufacturer,
            'model' => $identity->model_normalized ?? $identity->model,
            'mpns' => $mpns,
            'attributes' => $attributes,
        ], ['hardware_identity:'.$identity->getKey()]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, HardwareIdentity>  $candidates
     */
    private function resolveEvidence(HardwareEvidence $evidence, \Illuminate\Database\Eloquent\Collection $candidates): IdentityResolution
    {
        return $this->resolver->resolve(
            $evidence,
            $candidates->map(fn (HardwareIdentity $identity): array => [
                'id' => $identity->getKey(),
                'evidence' => $this->evidenceForIdentity($identity),
            ]),
        );
    }

    private function guardExistingListingAssociation(
        IdentityResolution $resolution,
        ?RetailerListing $listing,
    ): IdentityResolution {
        if ($listing?->hardware_identity_id === null || $resolution->state !== IdentityResolutionState::Verified) {
            return $resolution;
        }
        if ($resolution->matchedIdentityId === $listing->hardware_identity_id) {
            return $resolution;
        }

        return new IdentityResolution(
            state: IdentityResolutionState::Conflicting,
            candidateIds: array_values(array_unique([
                ...$resolution->candidateIds,
                $listing->hardware_identity_id,
            ])),
            signals: $resolution->signals,
            conflicts: ['retailer_listing_already_linked_to_different_identity'],
            reason: 'The retailer listing already belongs to another verified hardware identity.',
            suggestedAction: 'manual_review',
        );
    }

    /**
     * @param  array<string, mixed>  $listingData
     * @return array<string, mixed>
     */
    private function validatedListingData(array $listingData): array
    {
        $retailerKey = strtolower(trim((string) ($listingData['slug'] ?? '')));
        $identifierType = strtolower(trim((string) ($listingData['identifier_type'] ?? '')));
        $externalIdentifier = strtoupper(trim((string) ($listingData['external_identifier'] ?? '')));
        $url = trim((string) ($listingData['url'] ?? ''));
        if ($retailerKey === '' || strlen($retailerKey) > 80
            || $identifierType === '' || strlen($identifierType) > 40
            || $externalIdentifier === '' || strlen($externalIdentifier) > 255
            || preg_match('/^[A-Z0-9-]+$/', $externalIdentifier) !== 1
            || ! str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException('Retailer listing identity data is invalid.');
        }

        $listingKey = $retailerKey.':'.$identifierType.':'.$externalIdentifier;
        $listingKeyHash = hash('sha256', $listingKey);
        if (($listingData['listing_key'] ?? null) !== $listingKey
            || ! hash_equals($listingKeyHash, (string) ($listingData['listing_key_hash'] ?? ''))) {
            throw new \InvalidArgumentException('Retailer listing identity hash does not match its normalized tuple.');
        }

        return [
            ...$listingData,
            'slug' => $retailerKey,
            'identifier_type' => $identifierType,
            'external_identifier' => $externalIdentifier,
            'listing_key' => $listingKey,
            'listing_key_hash' => $listingKeyHash,
            'url' => $url,
            'normalized_url' => Url::normalizeForMatch($url),
        ];
    }

    /** @param array<string, mixed> $listingData */
    private function assertListingIdentity(RetailerListing $listing, array $listingData): void
    {
        if ($listing->retailer_key !== $listingData['slug']
            || $listing->identifier_type !== $listingData['identifier_type']
            || $listing->external_identifier !== $listingData['external_identifier']) {
            throw new \LogicException('A retailer listing hash collision was detected.');
        }
    }

    /** @return array{?PcPart, ?Url, ?DealOffer} */
    private function refreshAssociations(?PcPart $part, ?Url $url, ?DealOffer $offer): array
    {
        $lockedPart = $part instanceof PcPart
            ? PcPart::query()->findOrFail($part->getKey())
            : null;
        $lockedUrl = $url instanceof Url
            ? Url::query()->findOrFail($url->getKey())
            : null;
        $lockedOffer = $offer instanceof DealOffer
            ? DealOffer::query()->findOrFail($offer->getKey())
            : null;

        return [$lockedPart, $lockedUrl, $lockedOffer];
    }

    private function associationConflict(
        ?PcPart $part,
        ?Url $url,
        ?DealOffer $offer,
        ?HardwareIdentity $identity,
        RetailerListing $listing,
        bool $creatingIdentity = false,
    ): ?string {
        if ($creatingIdentity && $part?->hardware_identity_id !== null) {
            return 'pc_part_already_linked_to_different_identity';
        }
        if ($creatingIdentity && $listing->hardware_identity_id !== null) {
            return 'retailer_listing_already_linked_to_different_identity';
        }
        if ($identity instanceof HardwareIdentity
            && $part?->hardware_identity_id !== null
            && $part->hardware_identity_id !== $identity->getKey()) {
            return 'pc_part_already_linked_to_different_identity';
        }
        if ($identity instanceof HardwareIdentity
            && $listing->hardware_identity_id !== null
            && $listing->hardware_identity_id !== $identity->getKey()) {
            return 'retailer_listing_already_linked_to_different_identity';
        }
        if ($url?->retailer_listing_id !== null && $url->retailer_listing_id !== $listing->getKey()) {
            return 'url_already_linked_to_different_listing';
        }
        if ($offer?->retailer_listing_id !== null && $offer->retailer_listing_id !== $listing->getKey()) {
            return 'deal_offer_already_linked_to_different_listing';
        }

        return null;
    }

    private function linkModels(
        ?PcPart $part,
        ?Url $url,
        ?DealOffer $offer,
        ?HardwareIdentity $identity,
        RetailerListing $listing,
    ): void {
        if ($identity instanceof HardwareIdentity && $part instanceof PcPart && $part->hardware_identity_id === null) {
            $updated = PcPart::query()
                ->whereKey($part->getKey())
                ->whereNull('hardware_identity_id')
                ->update(['hardware_identity_id' => $identity->getKey()]);
            if ($updated !== 1 && $part->fresh()->hardware_identity_id !== $identity->getKey()) {
                throw new \LogicException('The PC part was concurrently linked to another identity.');
            }
        }
        if ($url instanceof Url && $url->retailer_listing_id === null) {
            $updated = Url::query()
                ->whereKey($url->getKey())
                ->whereNull('retailer_listing_id')
                ->update(['retailer_listing_id' => $listing->getKey()]);
            if ($updated !== 1 && $url->fresh()->retailer_listing_id !== $listing->getKey()) {
                throw new \LogicException('The URL was concurrently linked to another retailer listing.');
            }
        }
        if ($offer instanceof DealOffer && $offer->retailer_listing_id === null) {
            $updated = DealOffer::query()
                ->whereKey($offer->getKey())
                ->whereNull('retailer_listing_id')
                ->update(['retailer_listing_id' => $listing->getKey()]);
            if ($updated !== 1 && $offer->fresh()->retailer_listing_id !== $listing->getKey()) {
                throw new \LogicException('The offer was concurrently linked to another retailer listing.');
            }
        }
    }

    private function claimHash(?string $manufacturer, string $type, string $value): string
    {
        return hash('sha256', implode('|', [$manufacturer ?? '', $type, $value]));
    }
}
