<?php

namespace App\Services\Helpers;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Exception\ParserException;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Parser\IntlLocalizedDecimalParser;
use NumberFormatter;
use Symfony\Component\Intl\Currencies;

/**
 * Helpers to make dealing with currencies easier.
 */
class CurrencyHelper
{
    public static function getLocale(): string
    {
        return SettingsHelper::getSetting(
            'default_locale_settings.locale',
            config('app.locale', 'en')
        );
    }

    public static function getCurrency(): string
    {
        return SettingsHelper::getSetting('default_locale_settings.currency', 'USD');
    }

    public static function getCurrencyFromLocale(string $locale): ?array
    {
        return once(fn () => self::getAllCurrencies()
            ->firstWhere('locale', $locale)
        );
    }

    public static function getAllCurrencies(): Collection
    {
        return collect(json_decode(
            file_get_contents(base_path('/resources/datasets/currency.json')), true)
        )
            // Normalize the locale to use underscores instead of dashes and ensure not empty.
            ->map(fn ($currency) => array_merge($currency, [
                'locale' => empty($currency['locale'])
                    ? 'none'
                    : str_replace('-', '_', $currency['locale']),
            ]));
    }

    public static function getSymbol(?string $iso = null): string
    {
        return Currencies::getSymbol($iso ?? self::getCurrency());
    }

    public static function toFloat(mixed $value, ?string $locale = null, ?string $iso = null): float
    {
        $iso = $iso ?? self::getCurrency();
        $locale = $locale ?? self::getLocale();

        try {
            $value = (string) preg_replace('/[^\d\.\,]/', '', (string) $value);

            $currencies = new ISOCurrencies;
            $numberFormatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
            $moneyParser = new IntlLocalizedDecimalParser($numberFormatter, $currencies);
            $moneyFormatter = new DecimalMoneyFormatter($currencies);

            $money = $moneyParser->parse($value, new Currency($iso));

            return (float) $moneyFormatter->format($money);
        } catch (Exception|ParserException $e) {
            return 0.0;
        }
    }

    /**
     * Parse a raw retailer price without guessing between conflicting locale
     * interpretations. The fallback locale is opt-in and source-specific.
     *
     * @return array{amount: ?float, locale: ?string, decision: string, expected_currency?: string, detected_currency?: string}
     */
    public static function parsePrice(
        mixed $value,
        ?string $locale = null,
        ?string $iso = null,
        ?string $fallbackLocale = null,
    ): array {
        $iso = $iso ?? self::getCurrency();
        $locale = $locale ?? self::getLocale();

        if ((is_int($value) || is_float($value)) && is_finite((float) $value) && (float) $value > 0) {
            return [
                'amount' => (float) $value,
                'locale' => null,
                'decision' => 'numeric',
            ];
        }

        if (! is_string($value)) {
            return ['amount' => null, 'locale' => null, 'decision' => 'invalid'];
        }

        $expectedCurrency = self::normalizeCurrencyCode($iso);
        $currencyToken = self::detectExplicitCurrencyToken($value);
        $detectedCurrency = self::normalizeCurrencyCode($currencyToken);
        if ($currencyToken !== null && $detectedCurrency === null) {
            return [
                'amount' => null,
                'locale' => null,
                'decision' => 'invalid_currency_token',
            ];
        }
        if ($expectedCurrency !== null
            && $detectedCurrency !== null
            && $detectedCurrency !== $expectedCurrency) {
            return [
                'amount' => null,
                'locale' => null,
                'decision' => 'currency_mismatch',
                'expected_currency' => $expectedCurrency,
                'detected_currency' => $detectedCurrency,
            ];
        }

        $token = (string) preg_replace('/[^\d\.\,]/', '', $value);
        if ($token === '') {
            return ['amount' => null, 'locale' => null, 'decision' => 'invalid'];
        }

        $primary = self::parseExactForLocale($token, $locale, $iso);
        $fallbackLocale = self::normalizeLocale($fallbackLocale);
        $locale = self::normalizeLocale($locale) ?? $locale;

        if ($fallbackLocale === null || $fallbackLocale === $locale) {
            return $primary === null
                ? ['amount' => null, 'locale' => null, 'decision' => 'invalid']
                : ['amount' => $primary, 'locale' => $locale, 'decision' => 'primary_locale'];
        }

        $fallback = self::parseExactForLocale($token, $fallbackLocale, $iso);

        if ($primary === null && $fallback === null) {
            return ['amount' => null, 'locale' => null, 'decision' => 'invalid'];
        }

        if ($primary === null) {
            return ['amount' => $fallback, 'locale' => $fallbackLocale, 'decision' => 'locale_fallback'];
        }

        if ($fallback === null || $primary === $fallback) {
            return ['amount' => $primary, 'locale' => $locale, 'decision' => 'primary_locale'];
        }

        return ['amount' => null, 'locale' => null, 'decision' => 'locale_mismatch'];
    }

    /**
     * Detect an explicit ISO currency only when the whole value is price-shaped.
     * Symbols such as "$" remain intentionally ambiguous.
     */
    public static function detectExplicitCurrency(mixed $value): ?string
    {
        return self::normalizeCurrencyCode(self::detectExplicitCurrencyToken($value));
    }

    /**
     * Detect an alphabetic token only when the whole value is price-shaped.
     * This lets callers reject lookalikes such as USDC without scanning prose.
     */
    public static function detectExplicitCurrencyToken(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        foreach ([
            '/^([\p{L}]+)\s*[+-]?\d[\d\s.,]*$/u',
            '/^[+-]?\d[\d\s.,]*\s*([\p{L}]+)$/u',
        ] as $pattern) {
            if (preg_match($pattern, $value, $matches) === 1) {
                return strtoupper($matches[1]);
            }
        }

        return null;
    }

    public static function normalizeCurrencyCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $currency = strtoupper(trim($value));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 && Currencies::exists($currency)
            ? $currency
            : null;
    }

    private static function parseExactForLocale(string $value, string $locale, string $iso): ?float
    {
        try {
            $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
            $formatter->setAttribute(NumberFormatter::LENIENT_PARSE, 0);
            $position = 0;
            $parsed = $formatter->parse($value, NumberFormatter::TYPE_DOUBLE, $position);

            if ($parsed === false || $position !== strlen($value)) {
                return null;
            }

            $amount = (float) $parsed;
            if (! is_finite($amount) || $amount <= 0) {
                return null;
            }

            return round($amount, Currencies::getFractionDigits($iso));
        } catch (Exception) {
            return null;
        }
    }

    private static function normalizeLocale(?string $locale): ?string
    {
        if (! is_string($locale) || trim($locale) === '') {
            return null;
        }

        return str_replace('-', '_', trim($locale));
    }

    public static function toString(mixed $value, int $maxPrecision = 2, ?string $locale = null, ?string $iso = null): string
    {
        return Number::currency(
            number: round(floatval($value), $maxPrecision),
            in: ($iso ?? self::getCurrency()),
            locale: ($locale ?? self::getLocale())
        );
    }
}
