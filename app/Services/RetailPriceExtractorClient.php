<?php

namespace App\Services;

use App\Dto\PriceFetchResult;
use App\Enums\PriceFetchStatus;
use App\Models\DealOffer;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class RetailPriceExtractorClient
{
    /**
     * @return array{page_url: string, title: string, image_url: ?string, availability?: string, seller?: ?string, candidates: array<int, array<string, mixed>>}|null
     */
    public function extract(DealOffer $offer): ?array
    {
        return $this->extractUrl($offer->url);
    }

    /**
     * @return array{page_url: string, title: string, image_url: ?string, availability?: string, seller?: ?string, candidates: array<int, array<string, mixed>>}|null
     */
    public function extractUrl(string $url): ?array
    {
        return $this->fetchUrl($url)->toExtractorPayload();
    }

    public function fetchUrl(string $url): PriceFetchResult
    {
        $startedAt = hrtime(true);
        $baseUrl = rtrim((string) config('deal_hunter.price_extractor_url'), '/');
        if ($baseUrl === '') {
            return $this->failure($url, PriceFetchStatus::InvalidResponse, 'not_configured', false, $startedAt);
        }
        if (! $this->supports($url) || ! ScrapeUrl::allowsAutomatedAccess($url)) {
            return $this->failure($url, PriceFetchStatus::InvalidResponse, 'unsupported', false, $startedAt);
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(35)
                ->post($baseUrl.'/extract', ['url' => $url]);
        } catch (ConnectionException $exception) {
            $status = $this->isTimeout($exception->getMessage())
                ? PriceFetchStatus::Timeout
                : PriceFetchStatus::NetworkError;

            return $this->failure($url, $status, $status->value, true, $startedAt);
        } catch (Throwable) {
            return $this->failure(
                $url,
                PriceFetchStatus::InvalidResponse,
                PriceFetchStatus::InvalidResponse->value,
                false,
                $startedAt,
            );
        }

        $data = $response->json('data');
        $data = is_array($data) ? $data : [];
        $finalUrl = is_string($data['page_url'] ?? null) ? $data['page_url'] : $url;
        $title = is_string($data['title'] ?? null) && trim($data['title']) !== ''
            ? trim($data['title'])
            : null;
        $image = is_string($data['image_url'] ?? null) && $this->isHttpUrl($data['image_url'])
            ? $data['image_url']
            : null;
        $availability = in_array($data['availability'] ?? null, ['in_stock', 'out_of_stock', 'unknown'], true)
            ? $data['availability']
            : null;
        $seller = array_key_exists('seller', $data) && is_string($data['seller'])
            ? trim($data['seller'])
            : null;
        $candidates = $this->normalizeCandidates($data['candidates'] ?? null);

        if ($finalUrl !== $url && (! $this->isHttpUrl($finalUrl) || ! $this->sameRetailer($url, $finalUrl))) {
            return $this->failure(
                $url,
                PriceFetchStatus::InvalidResponse,
                'retailer_mismatch',
                false,
                $startedAt,
            );
        }

        if (! $response->successful()) {
            return $this->responseFailure(
                $response,
                $url,
                $finalUrl,
                $title,
                $image,
                $availability,
                $seller,
                $startedAt,
            );
        }

        if ($title === null) {
            return $this->failure(
                $finalUrl,
                PriceFetchStatus::InvalidResponse,
                'missing_title',
                false,
                $startedAt,
                image: $image,
                availability: $availability,
                seller: $seller,
            );
        }

        if ($candidates === []) {
            return $this->failure(
                $finalUrl,
                PriceFetchStatus::NoPrice,
                PriceFetchStatus::NoPrice->value,
                false,
                $startedAt,
                title: $title,
                image: $image,
                availability: $availability,
                seller: $seller,
            );
        }

        return new PriceFetchResult(
            status: PriceFetchStatus::Success,
            source: $candidates[0]['evidence'],
            engine: 'price_extractor',
            finalUrl: $finalUrl,
            title: $title,
            image: $image,
            availability: $availability,
            candidates: $candidates,
            observedAt: new DateTimeImmutable,
            latencyMs: $this->latencyMs($startedAt),
            seller: $seller,
            pricesNormalized: true,
        );
    }

    private function responseFailure(
        Response $response,
        string $url,
        string $finalUrl,
        ?string $title,
        ?string $image,
        ?string $availability,
        ?string $seller,
        int $startedAt,
    ): PriceFetchResult {
        $error = Str::lower((string) $response->json('error'));
        $blocked = $response->json('blocked') === true;
        $statusCode = $response->status();

        if ($blocked
            || in_array($statusCode, [403, 409, 429], true)
            || Str::contains($error, ['verification', 'challenge', 'captcha', 'rejected'])) {
            $status = PriceFetchStatus::Challenge;
            $retryable = true;
        } elseif (in_array($statusCode, [408, 504], true) || $this->isTimeout($error)) {
            $status = PriceFetchStatus::Timeout;
            $retryable = true;
        } elseif ($statusCode === 422 && Str::contains($error, ['no reliable', 'no price'])) {
            $status = PriceFetchStatus::NoPrice;
            $retryable = false;
        } elseif ($statusCode >= 500) {
            $status = PriceFetchStatus::NetworkError;
            $retryable = true;
        } else {
            $status = PriceFetchStatus::InvalidResponse;
            $retryable = false;
        }

        return $this->failure(
            $finalUrl,
            $status,
            $status->value,
            $retryable,
            $startedAt,
            title: $title,
            image: $image,
            availability: $availability,
            seller: $seller,
        );
    }

    /**
     * @return list<array{amount: float, currency: string, confidence: float, evidence: string}>
     */
    private function normalizeCandidates(mixed $rawCandidates): array
    {
        if (! is_array($rawCandidates)) {
            return [];
        }

        $candidates = [];
        foreach ($rawCandidates as $candidate) {
            if (! is_array($candidate) || ! is_numeric($candidate['price'] ?? null)) {
                continue;
            }

            $amount = round((float) $candidate['price'], 2);
            $currency = strtoupper(trim((string) ($candidate['currency'] ?? '')));
            if ($amount <= 0 || strlen($currency) !== 3) {
                continue;
            }

            $candidates[] = [
                'amount' => $amount,
                'currency' => $currency,
                'confidence' => (float) max(0, min(1, (float) ($candidate['confidence'] ?? 0))),
                'evidence' => trim((string) ($candidate['source'] ?? 'unknown')) ?: 'unknown',
            ];
        }

        return $candidates;
    }

    private function failure(
        string $url,
        PriceFetchStatus $status,
        string $kind,
        bool $retryable,
        int $startedAt,
        ?string $title = null,
        ?string $image = null,
        ?string $availability = null,
        ?string $seller = null,
    ): PriceFetchResult {
        return new PriceFetchResult(
            status: $status,
            source: 'retailer_page',
            engine: 'price_extractor',
            finalUrl: $url,
            title: $title,
            image: $image,
            availability: $availability,
            observedAt: new DateTimeImmutable,
            latencyMs: $this->latencyMs($startedAt),
            error: ['kind' => $kind, 'retryable' => $retryable],
            seller: $seller,
        );
    }

    private function isTimeout(string $message): bool
    {
        return Str::contains(Str::lower($message), ['timed out', 'timeout', 'curl error 28']);
    }

    private function latencyMs(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    private function supports(string $url): bool
    {
        $host = $this->host($url);
        if ($host === null) {
            return false;
        }

        foreach ((array) config('deal_hunter.retailers', []) as $retailer) {
            foreach ((array) ($retailer['domains'] ?? []) as $domain) {
                if ($this->hostMatches($host, (string) $domain)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function sameRetailer(string $firstUrl, string $secondUrl): bool
    {
        $firstHost = $this->host($firstUrl);
        $secondHost = $this->host($secondUrl);
        if ($firstHost === null || $secondHost === null) {
            return false;
        }

        foreach ((array) config('deal_hunter.retailers', []) as $retailer) {
            foreach ((array) ($retailer['domains'] ?? []) as $domain) {
                if ($this->hostMatches($firstHost, (string) $domain)
                    && $this->hostMatches($secondHost, (string) $domain)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower(rtrim($host, '.')) : null;
    }

    private function hostMatches(string $host, string $domain): bool
    {
        $domain = strtolower(ltrim($domain, '.'));

        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    private function isHttpUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
