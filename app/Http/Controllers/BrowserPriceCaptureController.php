<?php

namespace App\Http\Controllers;

use App\Models\DealOffer;
use App\Services\BrowserPriceCaptureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
            ],
        ]);
    }
}
