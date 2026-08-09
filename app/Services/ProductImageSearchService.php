<?php

namespace App\Services;

use App\Models\PcPart;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class ProductImageSearchService
{
    public function findForPart(PcPart $part): ?string
    {
        return $this->find(
            trim(collect([$part->manufacturer, $part->name])->filter()->unique()->join(' '))
        );
    }

    public function find(string $query): ?string
    {
        $query = trim($query);
        $endpoint = (string) config('deal_hunter.search_url');

        if ($query === '' || $endpoint === '') {
            return null;
        }

        return Cache::remember(
            'pc-deal-image:'.hash('sha256', Str::lower($query)),
            now()->addDays(7),
            fn (): ?string => $this->search($endpoint, $query)
        );
    }

    private function search(string $endpoint, string $query): ?string
    {
        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->get($endpoint, [
                    'format' => 'json',
                    'categories' => 'images',
                    'language' => 'en-US',
                    'safesearch' => 1,
                    'q' => $query,
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $tokens = $this->searchTokens($query);
        if ($tokens === []) {
            return null;
        }

        $candidate = collect((array) $response->json('results', []))
            ->filter(fn (mixed $result): bool => is_array($result))
            ->map(function (array $result) use ($tokens): array {
                $url = $this->firstImageUrl($result);
                $haystack = $this->normalize(collect(Arr::only(
                    $result,
                    ['title', 'content', 'source', 'url']
                ))->filter(fn (mixed $value): bool => is_string($value))->join(' '));
                $score = collect($tokens)
                    ->filter(fn (string $token): bool => str_contains($haystack, $token))
                    ->count();

                return ['url' => $url, 'score' => $score];
            })
            ->filter(fn (array $candidate): bool => $candidate['url'] !== null
                && $candidate['score'] >= min(2, count($tokens)))
            ->sortByDesc('score')
            ->first();

        return $candidate === null ? null : $candidate['url'];
    }

    /** @return array<int, string> */
    private function searchTokens(string $query): array
    {
        return collect(preg_split('/[^a-z0-9]+/i', Str::lower(Str::ascii($query))) ?: [])
            ->map(fn (string $token): string => $this->normalize($token))
            ->filter(fn (string $token): bool => strlen($token) >= 4 || preg_match('/\d/', $token) === 1)
            ->reject(fn (string $token): bool => in_array($token, [
                'desktop', 'component', 'processor', 'graphics', 'memory', 'solid', 'state', 'drive',
            ], true))
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $result */
    private function firstImageUrl(array $result): ?string
    {
        foreach (['img_src', 'thumbnail'] as $key) {
            $url = data_get($result, $key);
            if (is_string($url)
                && strlen($url) < ScrapeUrl::MAX_STR_LENGTH
                && filter_var($url, FILTER_VALIDATE_URL)
                && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                return $url;
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii($value))) ?? '';
    }
}
