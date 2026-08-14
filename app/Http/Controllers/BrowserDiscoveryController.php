<?php

namespace App\Http\Controllers;

use App\Enums\FulfillmentType;
use App\Enums\OfferCondition;
use App\Enums\OfferEvidenceQuality;
use App\Enums\OfferPurchasability;
use App\Enums\OfferScope;
use App\Enums\SellerType;
use App\Services\BrowserDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrowserDiscoveryController extends Controller
{
    public function __invoke(Request $request, BrowserDiscoveryService $discovery): JsonResponse
    {
        abort_unless($request->header('X-PriceBuddy-Companion') === '1', 403);

        $payload = $request->validate([
            'page_url' => ['required', 'url:https', 'max:2048'],
            'title' => ['required', 'string', 'max:1024'],
            'image_url' => ['nullable', 'url:http,https', 'max:4096'],
            'availability' => ['nullable', 'in:in_stock,out_of_stock,unknown'],
            'seller' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'mpn' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'condition' => ['nullable', Rule::enum(OfferCondition::class)],
            'seller_type' => ['nullable', Rule::enum(SellerType::class)],
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
            'part_number' => ['nullable', 'string', 'max:255'],
            'candidates' => ['sometimes', 'array', 'max:20'],
            'candidates.*.price' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'candidates.*.currency' => ['required', 'string', 'size:3'],
            'candidates.*.source' => ['required', 'string', 'max:60'],
            'candidates.*.confidence' => ['required', 'numeric', 'between:0,1'],
        ]);

        $result = $discovery->capture($payload);

        return response()->json([
            'data' => [
                'offer_id' => $result['offer']?->getKey(),
                'pc_part_id' => $result['part']?->getKey(),
                'product_id' => $result['product']?->getKey(),
                'component_type' => $result['component_type']->value,
                'price' => $result['offer']?->price === null ? null : (float) $result['offer']->price,
                'currency' => $result['offer']?->currency,
                'stored' => $result['stored'],
                'metadata_only' => $result['metadata_only'],
            ],
        ], $result['metadata_only'] ? 200 : 201);
    }
}
