<?php

namespace App\Services;

use App\Enums\ComponentType;
use App\Models\Product;

class PcComponentPriceGuard
{
    /** @var array<string, array{min: int|float, max: int|float}> */
    private const USD_RANGES = [
        'cpu' => ['min' => 5, 'max' => 5_000],
        'gpu' => ['min' => 10, 'max' => 10_000],
        'ram' => ['min' => 3, 'max' => 3_000],
        'ssd' => ['min' => 3, 'max' => 5_000],
        'psu' => ['min' => 5, 'max' => 3_000],
    ];

    public static function rejectionReason(
        Product $product,
        mixed $rawPrice,
        float $normalizedPrice,
        string $configuredCurrency,
    ): ?string {
        if ($product->component_type === null) {
            return null;
        }

        $configuredCurrency = strtoupper($configuredCurrency);
        $detectedCurrency = self::detectExplicitCurrency($rawPrice);

        if ($detectedCurrency !== null && $detectedCurrency !== $configuredCurrency) {
            return "page currency {$detectedCurrency} does not match {$configuredCurrency}";
        }

        if ($configuredCurrency !== 'USD') {
            return null;
        }

        return self::rejectionReasonForComponent(
            $product->component_type,
            $normalizedPrice,
            $configuredCurrency,
        );
    }

    public static function rejectionReasonForComponent(
        ComponentType|string|null $componentType,
        float $normalizedPrice,
        string $currency,
    ): ?string {
        $currency = strtoupper($currency);

        if ($currency !== 'USD') {
            return "captured currency {$currency} is not USD";
        }

        $type = $componentType instanceof ComponentType
            ? $componentType
            : ComponentType::tryFrom((string) $componentType);
        $range = $type ? (self::USD_RANGES[$type->value] ?? null) : null;

        if ($range !== null
            && ($normalizedPrice < $range['min'] || $normalizedPrice > $range['max'])) {
            return "USD price is outside the safe {$type->getLabel()} range";
        }

        return null;
    }

    public static function detectExplicitCurrency(mixed $rawPrice): ?string
    {
        if (! is_string($rawPrice)) {
            return null;
        }

        $value = strtoupper(trim($rawPrice));

        return match (true) {
            str_contains($value, '₡') || preg_match('/\bCRC\b/', $value) === 1 => 'CRC',
            str_contains($value, 'US$') || preg_match('/\bUSD\b/', $value) === 1 => 'USD',
            str_contains($value, 'C$') || preg_match('/\bCAD\b/', $value) === 1 => 'CAD',
            str_contains($value, 'A$') || preg_match('/\bAUD\b/', $value) === 1 => 'AUD',
            str_contains($value, '€') || preg_match('/\bEUR\b/', $value) === 1 => 'EUR',
            str_contains($value, '£') || preg_match('/\bGBP\b/', $value) === 1 => 'GBP',
            default => null,
        };
    }
}
