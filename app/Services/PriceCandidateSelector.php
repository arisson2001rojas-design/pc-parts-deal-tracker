<?php

namespace App\Services;

use App\Enums\ComponentType;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PriceCandidateSelector
{
    /** @return array{price: float, confidence: float, source: string} */
    public function select(
        array $rawCandidates,
        ComponentType|string|null $componentType = null,
        string $expectedCurrency = 'USD',
    ): array {
        $expectedCurrency = strtoupper($expectedCurrency);
        /** @var array<string, array{price: float, confidence: float, source: string}> $candidates */
        $candidates = [];
        $sawDifferentCurrency = false;

        foreach ($rawCandidates as $candidate) {
            $currency = strtoupper(trim((string) ($candidate['currency'] ?? '')));
            $sawDifferentCurrency = $sawDifferentCurrency
                || ($currency !== '' && $currency !== $expectedCurrency);

            if ($currency !== $expectedCurrency || ! is_numeric($candidate['price'] ?? null)) {
                continue;
            }

            $price = round((float) $candidate['price'], 2);
            if ($expectedCurrency === 'USD'
                && PcComponentPriceGuard::rejectionReasonForComponent(
                    $componentType,
                    $price,
                    $currency,
                ) !== null) {
                continue;
            }

            $source = Str::snake((string) ($candidate['source'] ?? 'unknown'));
            $confidence = (float) max(0, min(1, (float) ($candidate['confidence'] ?? 0)));
            $key = $source.'|'.number_format($price, 2, '.', '');
            $candidates[$key] = compact('price', 'confidence', 'source');
        }

        if ($candidates === []) {
            $message = $sawDifferentCurrency
                ? "The retailer is not showing {$expectedCurrency}. Select the expected store region and try again."
                : 'No plausible component price was found on the product page.';

            throw ValidationException::withMessages(['candidates' => $message]);
        }

        /** @var list<array{candidates: list<array{price: float, confidence: float, source: string}>}> $candidateGroups */
        $candidateGroups = [];
        foreach (array_values($candidates) as $candidate) {
            foreach ($candidateGroups as &$group) {
                $reference = $group['candidates'][0]['price'];
                if (abs($candidate['price'] - $reference) <= max(0.05, $reference * 0.01)) {
                    $group['candidates'][] = $candidate;

                    continue 2;
                }
            }
            unset($group);
            $candidateGroups[] = ['candidates' => [$candidate]];
        }
        unset($group);

        /** @var list<array{candidates: list<array{price: float, confidence: float, source: string}>, sources: list<string>, max_confidence: float, score: float}> $groups */
        $groups = array_map(static function (array $group): array {
            return [
                'candidates' => $group['candidates'],
                'sources' => array_values(array_unique(array_column($group['candidates'], 'source'))),
                'max_confidence' => (float) max(array_column($group['candidates'], 'confidence')),
                'score' => (float) array_sum(array_column($group['candidates'], 'confidence')),
            ];
        }, $candidateGroups);

        usort($groups, fn (array $a, array $b): int => [$b['score'], count($b['sources'])] <=> [$a['score'], count($a['sources'])]);
        $winnerGroup = $groups[0];
        $credibleGroups = array_filter($groups, fn (array $group): bool => $group['max_confidence'] >= 0.85);
        $hasConsensus = count($winnerGroup['sources']) >= 2
            || ($winnerGroup['max_confidence'] >= 0.92 && count($credibleGroups) === 1);

        if (! $hasConsensus) {
            throw ValidationException::withMessages([
                'candidates' => 'The page contains conflicting prices, so PriceBuddy did not guess.',
            ]);
        }

        usort($winnerGroup['candidates'], fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        return $winnerGroup['candidates'][0];
    }
}
