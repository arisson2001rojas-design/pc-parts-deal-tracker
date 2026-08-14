<?php

namespace App\Http\Controllers;

use App\Enums\FulfillmentType;
use App\Enums\OfferCondition;
use App\Enums\OfferEvidenceQuality;
use App\Enums\OfferPurchasability;
use App\Enums\OfferScope;
use App\Enums\SellerType;
use App\Models\DealOffer;
use App\Services\BrowserPriceCaptureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class BrowserPriceCaptureController extends Controller
{
    public function __invoke(
        Request $request,
        DealOffer $offer,
        BrowserPriceCaptureService $capture,
    ): JsonResponse {
        abort_unless($request->header('X-PriceBuddy-Companion') === '1', 403);

        $payload = $request->validate([
            'page_url' => ['required', 'url:http,https', 'max:2048'],
            'title' => ['required', 'string', 'max:1024'],
            'image_url' => ['nullable', 'url:http,https', 'max:4096'],
            'availability' => ['nullable', 'in:in_stock,out_of_stock,unknown'],
            'seller' => ['nullable', 'string', 'max:255'],
            'seller_type' => ['nullable', Rule::enum(SellerType::class)],
            'condition' => ['nullable', Rule::enum(OfferCondition::class)],
            'marketplace' => ['nullable', 'boolean'],
            'bundle' => ['nullable', 'boolean'],
            'offer_scope' => ['nullable', Rule::enum(OfferScope::class)],
            'purchasability' => ['nullable', Rule::enum(OfferPurchasability::class)],
            'fulfillment_type' => ['nullable', Rule::enum(FulfillmentType::class)],
            'evidence_quality' => ['nullable', Rule::enum(OfferEvidenceQuality::class)],
            'offer_evidence' => ['nullable', 'array', 'max:8'],
            'offer_evidence.source' => ['nullable', 'string', 'max:80'],
            'offer_evidence.seller_source' => ['nullable', 'string', 'max:80'],
            'offer_evidence.condition_source' => ['nullable', 'string', 'max:80'],
            'offer_evidence.fulfillment_source' => ['nullable', 'string', 'max:80'],
            'offer_evidence.conflict' => ['nullable', 'string', 'max:120'],
            'candidates' => ['required', 'array', 'min:1', 'max:20'],
            'candidates.*.price' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'candidates.*.currency' => ['required', 'string', 'size:3'],
            'candidates.*.source' => ['required', 'string', 'max:60'],
            'candidates.*.confidence' => ['required', 'numeric', 'between:0,1'],
        ]);

        $offer = $capture->capture($offer, $payload);

        return response()->json([
            'data' => [
                'offer_id' => $offer->getKey(),
                'price' => (float) $offer->price,
                'currency' => $offer->currency,
                'title' => $offer->title,
                'captured_at' => Carbon::parse($offer->fetched_at)->toIso8601String(),
                'availability' => $offer->availability,
                'seller' => $offer->seller,
            ],
        ]);
    }
}
