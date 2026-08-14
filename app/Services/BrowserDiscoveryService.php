<?php

namespace App\Services;

use App\Dto\HardwareEvidence;
use App\Dto\IdentityIngestionResult;
use App\Enums\ComponentType;
use App\Models\DealOffer;
use App\Models\DealSearch;
use App\Models\PcPart;
use App\Models\Product;
use App\Models\Url;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Throwable;

class BrowserDiscoveryService
{
    public function __construct(
        private readonly PriceCandidateSelector $candidateSelector,
        private readonly PcComponentClassifier $classifier,
        private readonly RetailerProductUrl $productUrls,
        private readonly CatalogTrackingService $tracking,
        private readonly HardwareEvidenceNormalizer $evidenceNormalizer,
        private readonly HardwareIdentityIngestionService $identityIngestion,
    ) {}

    /** @return array{offer: DealOffer, part: PcPart, product: \App\Models\Product, component_type: ComponentType} */
    public function capture(array $payload): array
    {
        $product = $this->productUrls->identify((string) $payload['page_url']);
        if ($product === null) {
            throw ValidationException::withMessages([
                'page_url' => 'The page is not a supported retailer product page.',
            ]);
        }

        $title = trim((string) $payload['title']);
        $componentType = $this->classifier->detect($title);
        if ($componentType === null) {
            throw ValidationException::withMessages([
                'title' => 'The page does not describe a supported PC component.',
            ]);
        }

        $winner = $this->candidateSelector->select((array) $payload['candidates'], $componentType);
        $user = $this->companionUser();
        $evidence = $this->evidenceNormalizer->fromArray([
            'component_type' => $componentType->value,
            'title' => $title,
            'manufacturer' => $payload['manufacturer'] ?? null,
            'model' => $payload['model'] ?? null,
            'mpn' => $payload['mpn'] ?? null,
            'part_number' => $payload['part_number'] ?? null,
            'part_number_type' => filled($payload['mpn'] ?? null) ? 'mpn' : null,
            'seller_type' => $payload['seller_type'] ?? null,
            'condition' => $payload['condition'] ?? null,
            'marketplace' => $payload['marketplace'] ?? null,
            'bundle' => $payload['bundle'] ?? null,
        ], [
            'source' => 'browser_companion',
            'legacy_part_number' => $payload['part_number'] ?? null,
            'sku' => $payload['sku'] ?? null,
        ]);
        $exactTrackedListing = $this->findExactTrackedListing($product, $user);

        // Identity uses its own short transaction so it never inherits locks
        // held by Browser Radar's core tracking transaction.
        $identityResult = $this->ingestIdentitySafely(
            $product,
            $evidence,
            part: $exactTrackedListing['part'] ?? null,
            url: $exactTrackedListing['url'] ?? null,
            seller: isset($payload['seller']) ? (string) $payload['seller'] : null,
        );

        $result = DB::transaction(function () use ($payload, $product, $title, $componentType, $winner, $user, $evidence, $identityResult, $exactTrackedListing): array {
            $searchIdentity = [
                'user_id' => $user->getKey(),
                'query' => 'browser-radar:'.$componentType->value,
            ];
            $createdAt = now();
            DealSearch::query()->insertOrIgnore([[
                ...$searchIdentity,
                'name' => 'Radar del navegador · '.strtoupper($componentType->value),
                'component_type' => $componentType->value,
                'target_price' => null,
                'enabled' => false,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]]);
            $search = DealSearch::query()->where($searchIdentity)->firstOrFail();

            $urlHash = hash('sha256', $product['url']);
            $offers = DealOffer::query()
                ->with('dealSearch')
                ->where('url_hash', $urlHash)
                ->whereHas('dealSearch', fn (Builder $query): Builder => $query->where('user_id', $user->getKey()))
                ->get();

            $targetOffer = $offers->firstWhere('deal_search_id', $search->getKey());
            if (! $targetOffer instanceof DealOffer) {
                $legacyRadarOffer = $offers->first(function (DealOffer $offer): bool {
                    $query = (string) $offer->dealSearch->query;

                    return str_starts_with($query, 'browser-radar:');
                });

                if ($legacyRadarOffer instanceof DealOffer) {
                    $legacyRadarOffer->forceFill(['deal_search_id' => $search->getKey()])->save();
                    $targetOffer = $legacyRadarOffer;
                } else {
                    $targetOffer = DealOffer::query()->firstOrCreate([
                        'deal_search_id' => $search->getKey(),
                        'url_hash' => $urlHash,
                    ], [
                        'store' => $product['store'],
                        'title' => Str::limit($title, 1024, ''),
                        'url' => $product['url'],
                        'price' => $winner['price'],
                        'fetched_at' => now(),
                    ]);
                    if (! $offers->contains('id', $targetOffer->getKey())) {
                        $offers->push($targetOffer);
                    }
                }
            }

            $offers = DealOffer::query()
                ->with('dealSearch')
                ->where('url_hash', $urlHash)
                ->whereHas('dealSearch', fn (Builder $query): Builder => $query->where('user_id', $user->getKey()))
                ->orderBy('deal_offers.id')
                ->lockForUpdate()
                ->get();
            $targetOffer = $offers->firstWhere('deal_search_id', $search->getKey());
            if (! $targetOffer instanceof DealOffer) {
                throw new \LogicException('The browser discovery offer could not be locked.');
            }

            $now = now();
            foreach ($offers as $offer) {
                $offer->forceFill([
                    'store' => $product['store'],
                    'title' => Str::limit($title, 1024, ''),
                    'url' => $product['url'],
                    'image_url' => filled($payload['image_url'] ?? null) ? $payload['image_url'] : $offer->image_url,
                    'price' => $winner['price'],
                    'currency' => 'USD',
                    'source' => 'browser_discovery',
                    'availability' => $payload['availability'] ?? DealOffer::AVAILABILITY_UNKNOWN,
                    'seller' => filled($payload['seller'] ?? null)
                        ? Str::limit((string) $payload['seller'], 255, '')
                        : $offer->seller,
                    'fetched_at' => $now,
                ])->save();
                $offer->recordPriceSnapshot();
            }

            $part = $this->upsertCatalogPart(
                $payload,
                $product,
                $title,
                $componentType,
                $evidence,
                $identityResult?->identity?->getKey(),
                $exactTrackedListing,
            );
            $trackedProduct = $this->tracking->trackBrowserDiscovery(
                $part,
                $user->getKey(),
                $product['slug'],
                [
                    'price' => $winner['price'],
                    'image_url' => $payload['image_url'] ?? null,
                    'availability' => $payload['availability'] ?? DealOffer::AVAILABILITY_UNKNOWN,
                ],
            );

            return [
                'offer' => $targetOffer->fresh('dealSearch'),
                'part' => $part,
                'product' => $trackedProduct,
                'component_type' => $componentType,
            ];
        });

        $retailerUrls = (array) ($result['part']->retailer_urls ?? []);
        $trackedUrlValue = $retailerUrls[$product['slug']] ?? $product['url'];
        /** @var ?Url $trackedUrl */
        $trackedUrl = $result['product']->urls()->where('url', $trackedUrlValue)->first();
        $this->ingestIdentitySafely(
            $product,
            $evidence,
            part: $result['part'],
            url: $trackedUrl,
            offer: $result['offer'],
            seller: isset($payload['seller']) ? (string) $payload['seller'] : null,
        );

        $result['offer']->refresh()->load('dealSearch');
        $result['part']->refresh();
        $result['product']->refresh();

        return $result;
    }

    private function companionUser(): User
    {
        $configuredId = (int) config('deal_hunter.companion_user_id');
        $user = $configuredId > 0
            ? User::query()->find($configuredId)
            : User::query()->orderBy('id')->first();

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'user' => 'Create the local PriceBuddy user before enabling browser discovery.',
            ]);
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{slug: string, store: string, identifier: string, product_key: string, url: string, identifier_type: string, external_identifier: string, listing_key: string, listing_key_hash: string, normalized_url: string}  $product
     * @param  null|array{url: Url, product: Product, part: PcPart}  $exactTrackedListing
     */
    private function upsertCatalogPart(
        array $payload,
        array $product,
        string $title,
        ComponentType $componentType,
        HardwareEvidence $evidence,
        ?int $hardwareIdentityId,
        ?array $exactTrackedListing,
    ): PcPart {
        $uuid = Uuid::uuid5(Uuid::NAMESPACE_URL, 'pricebuddy:'.$product['product_key'])->toString();
        $reusedByExactListing = $exactTrackedListing !== null;
        $reusedByIdentity = false;
        $part = $exactTrackedListing['part'] ?? PcPart::query()->where('opendb_id', $uuid)->first();
        if (! $part instanceof PcPart && $hardwareIdentityId !== null) {
            $identityParts = PcPart::query()
                ->where('hardware_identity_id', $hardwareIdentityId)
                ->orderBy('id')
                ->limit(2)
                ->get();
            // Existing duplicates are never collapsed by choosing an arbitrary
            // first row. A new listing-scoped part keeps that case reviewable.
            $part = $identityParts->count() === 1 ? $identityParts->first() : null;
            $reusedByIdentity = $part instanceof PcPart;
        }
        $part ??= PcPart::query()->firstOrCreate(
            ['opendb_id' => $uuid],
            [
                'hardware_identity_id' => $hardwareIdentityId,
                'component_type' => $componentType->value,
                'name' => Str::limit($title, 1024, ''),
                'source_url' => $product['url'],
            ],
        );
        $part = PcPart::query()->lockForUpdate()->findOrFail($part->getKey());
        $preserveTrustedIdentityFields = $part->hardware_identity_id !== null
            && $part->hardware_identity_id !== $hardwareIdentityId;
        $existingSpecifications = (array) ($part->specifications ?? []);
        $isUnverifiedListingScopedRadarPart = $part->hardware_identity_id === null
            && $part->opendb_id === $uuid
            && array_key_exists('browser_companion', $existingSpecifications);
        $preserveTrustedCatalogFields = $preserveTrustedIdentityFields
            || ($reusedByExactListing && ! $isUnverifiedListingScopedRadarPart);

        $retailerUrls = (array) ($part->retailer_urls ?? []);
        $retailerUrls[$product['slug']] = $reusedByExactListing
            ? (string) $exactTrackedListing['url']->url
            : $product['url'];
        $partNumbers = $preserveTrustedCatalogFields
            ? (array) ($part->part_numbers ?? [])
            : array_values(array_unique(array_filter([
                ...(array) ($part->part_numbers ?? []),
                ...$evidence->mpns,
            ])));
        $specifications = $existingSpecifications;
        $specifications['browser_companion'] = [
            'last_seen_at' => now()->toIso8601String(),
            'retailer_product_key' => $product['product_key'],
            'identifier_type' => $product['identifier_type'],
            'external_identifier' => $product['external_identifier'],
            'mpn' => $payload['mpn'] ?? null,
            'model' => $payload['model'] ?? null,
            'sku' => $payload['sku'] ?? null,
            'legacy_part_number' => $payload['part_number'] ?? null,
        ];

        $part->forceFill([
            'hardware_identity_id' => $part->hardware_identity_id ?? $hardwareIdentityId,
            'component_type' => $reusedByIdentity || $preserveTrustedCatalogFields
                ? $part->component_type
                : $componentType->value,
            'name' => $reusedByIdentity || $preserveTrustedCatalogFields
                ? $part->name
                : Str::limit($title, 1024, ''),
            'manufacturer' => ! $preserveTrustedCatalogFields
                && blank($part->manufacturer)
                && filled($payload['manufacturer'] ?? null)
                ? Str::limit((string) $payload['manufacturer'], 255, '')
                : $part->manufacturer,
            'part_numbers' => $partNumbers,
            'retailer_urls' => $retailerUrls,
            'specifications' => $specifications,
            'source_url' => $part->exists ? $part->source_url : $product['url'],
            'source_updated_at' => now(),
        ])->save();

        return $part;
    }

    /**
     * Resolve an already-tracked retailer listing within the companion user's
     * own products. Exact listing identity and normalized URLs are the only
     * accepted signals; titles and other fuzzy catalog fields are ignored.
     *
     * @param  array{slug: string, store: string, identifier: string, product_key: string, url: string, identifier_type: string, external_identifier: string, listing_key: string, listing_key_hash: string, normalized_url: string}  $listing
     * @return null|array{url: Url, product: Product, part: PcPart}
     */
    private function findExactTrackedListing(array $listing, User $user): ?array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, Url> $urls */
        $urls = Url::query()
            ->whereIn(
                'product_id',
                Product::query()->select('id')->where('user_id', $user->getKey()),
            )
            ->where(fn (Builder $query): Builder => $query
                ->where('url_normalized', $listing['normalized_url'])
                ->orWhereHas(
                    'listing',
                    fn (Builder $listingQuery): Builder => $listingQuery
                        ->where('listing_key_hash', $listing['listing_key_hash']),
                ))
            ->orderBy('id')
            ->get();
        $productIds = $urls
            ->map(fn (Url $url): ?int => $url->product_id === null ? null : (int) $url->product_id)
            ->filter(fn (?int $productId): bool => $productId !== null)
            ->unique()
            ->values();

        if ($productIds->count() > 1) {
            throw ValidationException::withMessages([
                'page_url' => 'This retailer listing is already tracked by multiple products. Resolve those duplicate products before Browser Radar can record it.',
            ]);
        }

        $trackedUrl = $urls->first();
        if (! $trackedUrl instanceof Url) {
            return null;
        }

        $productId = $productIds->first();
        $trackedProduct = is_int($productId) ? Product::query()->find($productId) : null;
        $partId = $trackedProduct?->pc_part_id;
        $trackedPart = is_numeric($partId) ? PcPart::query()->find((int) $partId) : null;

        if (! $trackedProduct instanceof Product || ! $trackedPart instanceof PcPart) {
            throw ValidationException::withMessages([
                'page_url' => 'This retailer listing is attached to a product without a PC part. Repair that product before Browser Radar can record it.',
            ]);
        }

        return [
            'url' => $trackedUrl,
            'product' => $trackedProduct,
            'part' => $trackedPart,
        ];
    }

    /**
     * Identity is additive enrichment: an internal resolver/schema problem
     * must never prevent the already-valid Browser Radar observation.
     *
     * @param  array<string, mixed>  $listing
     */
    private function ingestIdentitySafely(
        array $listing,
        HardwareEvidence $evidence,
        ?PcPart $part = null,
        ?Url $url = null,
        ?DealOffer $offer = null,
        ?string $seller = null,
    ): ?IdentityIngestionResult {
        try {
            return $this->identityIngestion->ingest($listing, $evidence, $part, $url, $offer, $seller);
        } catch (Throwable $exception) {
            Log::warning('Hardware identity enrichment failed without blocking Browser Radar.', [
                'listing_key_hash' => $listing['listing_key_hash'] ?? null,
                'error' => Str::limit($exception->getMessage(), 300, ''),
            ]);

            return null;
        }
    }
}
