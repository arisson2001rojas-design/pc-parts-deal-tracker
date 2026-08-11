<?php

namespace App\Filament\Resources\ProductResource\Api\Handlers;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Api\Requests\CreateProductRequest;
use App\Filament\Resources\ProductResource\Api\Transformers\ProductTransformer;
use App\Models\Url;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Rupadana\ApiService\Http\Handlers;

#[Group(ProductResource::API_GROUP)]
class CreateHandler extends Handlers
{
    public static ?string $uri = '/';

    public static ?string $resource = ProductResource::class;

    public static function getMethod()
    {
        return Handlers::POST;
    }

    public static function getModel()
    {
        return static::$resource::getModel();
    }

    /**
     * Create Product
     *
     * @return JsonResponse
     */
    public function handler(CreateProductRequest $request)
    {
        $values = $request->validated();

        $urlModel = Url::createFromUrl(
            url: data_get($values, 'url'),
            productId: data_get($values, 'product_id'),
            userId: auth()->id(),
            createStore: data_get($values, 'create_store', false)
        );

        if ($urlModel) {
            $product = $urlModel->product;

            if (filled(data_get($values, 'component_type'))) {
                $product->component_type = data_get($values, 'component_type');
            }

            if (array_key_exists('paused', $values)) {
                $product->setUserPaused((bool) $values['paused']);
            }

            if ($product->isDirty()) {
                $product->save();
            }

            return response()->json([
                'data' => new ProductTransformer($product),
                'message' => 'Product created',
            ], 201);
        }

        return response()->json([
            'message' => 'Unable to create product, check the logs',
        ], 422);
    }
}
