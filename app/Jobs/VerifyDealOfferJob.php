<?php

namespace App\Jobs;

use App\Models\DealOffer;
use App\Services\BrowserPriceCaptureService;
use App\Services\DealHunterService;
use App\Services\RetailPriceExtractorClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;

class VerifyDealOfferJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 1800;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public readonly int $dealOfferId) {}

    public function handle(
        RetailPriceExtractorClient $extractor,
        BrowserPriceCaptureService $capture,
        DealHunterService $hunter,
    ): void {
        $offer = DealOffer::query()->with('dealSearch.user')->find($this->dealOfferId);
        if (! $offer) {
            return;
        }

        $payload = $extractor->extract($offer);
        if ($payload === null) {
            return;
        }

        try {
            $offer = $capture->capture($offer, $payload, 'direct_extract');
        } catch (ValidationException) {
            return;
        }

        $hunter->notifyWhenTargetReached($offer->dealSearch?->fresh(['user']));
    }

    public function uniqueId(): string
    {
        return (string) $this->dealOfferId;
    }
}
