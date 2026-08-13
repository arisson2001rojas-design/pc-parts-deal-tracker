<?php

namespace App\Dto;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Deterministic, normalized evidence about one physical hardware variant.
 *
 * Values in $mpns are typed manufacturer part numbers. Retailer SKUs and
 * untyped JSON-LD `model`/`sku` values must not be placed in this collection.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class HardwareEvidence implements Arrayable, JsonSerializable
{
    /**
     * @param  list<string>  $mpns
     * @param  array<string, bool|int|float|string>  $attributes
     * @param  array<int|string, mixed>  $sources
     */
    public function __construct(
        public ?string $componentType,
        public ?string $manufacturer,
        public ?string $model,
        public array $mpns = [],
        public array $attributes = [],
        public ?string $condition = null,
        public bool $bundle = false,
        public bool $marketplace = false,
        public array $sources = [],
        public ?string $title = null,
    ) {}

    public function authoritativeKeyHash(): ?string
    {
        if ($this->componentType === null || $this->manufacturer === null || $this->mpns === []) {
            return null;
        }

        $mpns = $this->mpns;
        sort($mpns, SORT_STRING);

        return hash('sha256', json_encode([
            'component_type' => $this->componentType,
            'manufacturer' => $this->manufacturer,
            'mpns' => $mpns,
        ], JSON_THROW_ON_ERROR));
    }

    public function variantFingerprint(): ?string
    {
        if ($this->componentType === null || $this->manufacturer === null || $this->model === null) {
            return null;
        }

        $attributes = $this->attributes;
        ksort($attributes, SORT_STRING);

        return hash('sha256', json_encode([
            'component_type' => $this->componentType,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'attributes' => $attributes,
        ], JSON_THROW_ON_ERROR));
    }

    public function canEstablishIdentity(): bool
    {
        return $this->componentType !== null
            && $this->manufacturer !== null
            && $this->mpns !== []
            && ! $this->isUnsafeForVerification();
    }

    public function isUnsafeForVerification(): bool
    {
        return $this->bundle
            || $this->marketplace
            || in_array($this->condition, [
                'open_box',
                'preowned',
                'refurbished',
                'renewed',
                'used',
            ], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'component_type' => $this->componentType,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'mpns' => $this->mpns,
            'attributes' => $this->attributes,
            'condition' => $this->condition,
            'bundle' => $this->bundle,
            'marketplace' => $this->marketplace,
            'sources' => $this->sources,
            'title' => $this->title,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
