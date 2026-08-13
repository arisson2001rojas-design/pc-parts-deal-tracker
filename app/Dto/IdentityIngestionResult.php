<?php

namespace App\Dto;

use App\Models\HardwareIdentity;
use App\Models\RetailerListing;

final readonly class IdentityIngestionResult
{
    public function __construct(
        public RetailerListing $listing,
        public ?HardwareIdentity $identity,
        public IdentityResolution $resolution,
    ) {}
}
