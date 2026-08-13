<?php

namespace App\Services;

use App\Models\Product;
use App\Models\RetailerListing;

/** Read-only reporting service. It never creates, links, or merges records. */
final class IdentityReconciliationService
{
    public function __construct(
        private readonly RetailerProductUrl $retailerProductUrl,
        private readonly HardwareEvidenceNormalizer $evidenceNormalizer,
        private readonly HardwareIdentityIngestionService $identityIngestion,
    ) {}

    /** @return list<array<string, mixed>> */
    public function inspectProduct(Product $product): array
    {
        $product->loadMissing(['pcPart.hardwareIdentity', 'urls.listing']);
        $part = $product->pcPart;
        if ($part === null) {
            return [];
        }

        $evidence = $this->evidenceNormalizer->fromPcPart($part);
        $rows = [];
        foreach ($product->urls->sortBy('id') as $url) {
            $identifier = $this->retailerProductUrl->identify($url->url);
            if ($identifier === null) {
                continue;
            }
            $listing = $url->listing;
            if (! $listing instanceof RetailerListing) {
                $listing = RetailerListing::query()
                    ->where('listing_key_hash', $identifier['listing_key_hash'])
                    ->first();
            }
            $resolution = $this->identityIngestion->preview($evidence, $listing);

            $rows[] = [
                'product_id' => $product->getKey(),
                'pc_part_id' => $part->getKey(),
                'url_id' => $url->getKey(),
                'retailer' => $identifier['slug'],
                'identifier_type' => $identifier['identifier_type'],
                'external_identifier' => $identifier['external_identifier'],
                'existing_listing_id' => $listing?->getKey(),
                'existing_identity_id' => $listing instanceof RetailerListing
                    ? $listing->hardware_identity_id
                    : $part->hardware_identity_id,
                'candidate_identity_id' => $resolution->matchedIdentityId,
                'component_type' => $evidence->componentType,
                'evidence' => $evidence->toArray(),
                'resolution' => $resolution->toArray(),
            ];
        }

        return $rows;
    }
}
