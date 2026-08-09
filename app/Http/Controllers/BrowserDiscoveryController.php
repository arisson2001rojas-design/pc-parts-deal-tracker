<?php

namespace App\Http\Controllers;

use App\Services\BrowserDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'part_number' => ['nullable', 'string', 'max:255'],
            'candidates' => ['required', 'array', 'min:1', 'max:20'],
            'candidates.*.price' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'candidates.*.currency' => ['required', 'string', 'size:3'],
            'candidates.*.source' => ['required', 'string', 'max:60'],
            'candidates.*.confidence' => ['required', 'numeric', 'between:0,1'],
        ]);

        $result = $discovery->capture($payload);

        return response()->json([
            'data' => [
                'offer_id' => $result['offer']->getKey(),
                'pc_part_id' => $result['part']->getKey(),
                'component_type' => $result['component_type']->value,
                'price' => (float) $result['offer']->price,
                'currency' => $result['offer']->currency,
                'stored' => true,
            ],
        ], 201);
    }
}
