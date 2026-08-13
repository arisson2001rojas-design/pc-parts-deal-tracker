<?php

namespace Database\Factories;

use App\Models\RetailerListing;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RetailerListing> */
class RetailerListingFactory extends Factory
{
    protected $model = RetailerListing::class;

    public function definition(): array
    {
        $retailerKey = 'amazon';
        $identifierType = 'asin';
        $externalIdentifier = strtoupper($this->faker->bothify('B0????????'));
        $canonicalUrl = 'https://www.amazon.com/dp/'.$externalIdentifier;

        return [
            'retailer_key' => $retailerKey,
            'identifier_type' => $identifierType,
            'external_identifier' => $externalIdentifier,
            'listing_key_hash' => hash('sha256', $retailerKey.':'.$identifierType.':'.$externalIdentifier),
            'canonical_url' => $canonicalUrl,
            'normalized_url' => 'amazon.com/dp/'.strtolower($externalIdentifier),
            'url_hash' => hash('sha256', $canonicalUrl),
            'title' => $this->faker->sentence,
            'seller' => null,
            'marketplace' => null,
            'hardware_identity_id' => null,
            'resolution_state' => 'unverified',
            'resolution_reason' => null,
            'resolved_at' => null,
            'evidence' => [],
            'decision_trace' => [],
        ];
    }
}
