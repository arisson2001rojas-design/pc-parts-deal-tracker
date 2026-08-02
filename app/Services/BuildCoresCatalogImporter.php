<?php

namespace App\Services;

use App\Enums\ComponentType;
use App\Models\PcPart;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

class BuildCoresCatalogImporter
{
    public const SOURCE_URL = 'https://github.com/buildcores/buildcores-open-db';

    private const MAX_ARCHIVE_BYTES = 500_000_000;

    private const MAX_ENTRY_BYTES = 2_000_000;

    /**
     * @param  null|callable(int): void  $onImported
     */
    public function import(string $source, ?callable $onImported = null): int
    {
        [$archivePath, $temporary] = $this->resolveArchive($source);

        try {
            if (filesize($archivePath) > self::MAX_ARCHIVE_BYTES) {
                throw new RuntimeException('The component catalog archive is unexpectedly large.');
            }

            $archive = new ZipArchive;
            if ($archive->open($archivePath) !== true) {
                throw new RuntimeException('Unable to open the component catalog archive.');
            }

            try {
                return $this->importArchive($archive, $onImported);
            } finally {
                $archive->close();
            }
        } finally {
            if ($temporary && is_file($archivePath)) {
                unlink($archivePath);
            }
        }
    }

    /**
     * @param  null|callable(int): void  $onImported
     */
    private function importArchive(ZipArchive $archive, ?callable $onImported): int
    {
        $rows = [];
        $imported = 0;
        $syncedAt = now();

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $entry = $archive->statIndex($index);
            $entryName = $entry['name'] ?? '';

            if (! preg_match('#/open-db/(CPU|GPU|RAM|Storage|PSU)/[^/]+\.json$#', $entryName, $matches)) {
                continue;
            }

            if (($entry['size'] ?? 0) > self::MAX_ENTRY_BYTES) {
                continue;
            }

            $contents = $archive->getFromIndex($index);
            $data = is_string($contents) ? json_decode($contents, true) : null;
            if (! is_array($data)) {
                continue;
            }

            $row = $this->mapPart($matches[1], $data, $syncedAt->toDateTimeString());
            if ($row === null) {
                continue;
            }

            $rows[] = $row;
            $imported++;

            if (count($rows) >= 500) {
                $this->upsert($rows);
                $rows = [];
                $onImported?->__invoke($imported);
            }
        }

        if ($rows !== []) {
            $this->upsert($rows);
            $onImported?->__invoke($imported);
        }

        return $imported;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return null|array<string, mixed>
     */
    private function mapPart(string $category, array $data, string $syncedAt): ?array
    {
        $componentType = match ($category) {
            'CPU' => ComponentType::Cpu,
            'GPU' => ComponentType::Gpu,
            'RAM' => ComponentType::Ram,
            'PSU' => ComponentType::Psu,
            'Storage' => ComponentType::Ssd,
        };

        if ($category === 'Storage' && strtoupper((string) ($data['storage_type'] ?? $data['type'] ?? '')) !== 'SSD') {
            return null;
        }

        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $general = is_array($data['general_product_information'] ?? null)
            ? $data['general_product_information']
            : [];
        $id = $data['opendb_id'] ?? null;
        $name = $metadata['name'] ?? null;

        if (! is_string($id) || ! is_string($name) || $id === '' || $name === '') {
            return null;
        }

        return [
            'opendb_id' => $id,
            'component_type' => $componentType->value,
            'name' => $name,
            'manufacturer' => $metadata['manufacturer'] ?? null,
            'series' => $metadata['series'] ?? ($data['series'] ?? null),
            'variant' => $metadata['variant'] ?? null,
            'part_numbers' => json_encode($metadata['part_numbers'] ?? [], JSON_THROW_ON_ERROR),
            'release_year' => $metadata['releaseYear'] ?? null,
            'retailer_urls' => json_encode($this->retailerUrls($general), JSON_THROW_ON_ERROR),
            'specifications' => json_encode($data, JSON_THROW_ON_ERROR),
            'source_url' => self::SOURCE_URL,
            'source_updated_at' => $syncedAt,
            'created_at' => $syncedAt,
            'updated_at' => $syncedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $general
     * @return array<string, string>
     */
    private function retailerUrls(array $general): array
    {
        $urls = [];

        if ($sku = $this->validSku($general['amazon_sku'] ?? null)) {
            $urls['amazon'] = 'https://www.amazon.com/dp/'.rawurlencode($sku);
        }
        if ($sku = $this->validSku($general['walmart_sku'] ?? null)) {
            $urls['walmart'] = 'https://www.walmart.com/ip/'.rawurlencode($sku);
        }
        if ($sku = $this->validSku($general['newegg_sku'] ?? null)) {
            $urls['newegg'] = 'https://www.newegg.com/p/'.rawurlencode($sku);
        }

        return $urls;
    }

    private function validSku(mixed $sku): ?string
    {
        if (! is_string($sku) && ! is_int($sku)) {
            return null;
        }

        $sku = trim((string) $sku);

        return $sku !== '' ? $sku : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsert(array $rows): void
    {
        PcPart::query()->upsert(
            $rows,
            ['opendb_id'],
            [
                'component_type',
                'name',
                'manufacturer',
                'series',
                'variant',
                'part_numbers',
                'release_year',
                'retailer_urls',
                'specifications',
                'source_url',
                'source_updated_at',
                'updated_at',
            ]
        );
    }

    /**
     * @return array{string, bool}
     */
    private function resolveArchive(string $source): array
    {
        if (! filter_var($source, FILTER_VALIDATE_URL)) {
            if (! is_file($source)) {
                throw new RuntimeException('Component catalog archive not found.');
            }

            return [$source, false];
        }

        $temporaryPath = tempnam(storage_path('app'), 'pc-parts-catalog-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to create a temporary catalog file.');
        }

        Http::timeout(300)
            ->retry(3, 1000)
            ->withUserAgent('PC Parts Deal Tracker catalog sync')
            ->withOptions(['sink' => $temporaryPath])
            ->get($source)
            ->throw();

        return [$temporaryPath, true];
    }
}
