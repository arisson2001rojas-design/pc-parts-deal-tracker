<?php

namespace App\Services;

use App\Dto\HardwareEvidence;
use App\Models\PcPart;
use BackedEnum;
use Illuminate\Support\Str;

final class HardwareEvidenceNormalizer
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<int|string, mixed>  $sources
     */
    public function fromArray(array $input, array $sources = []): HardwareEvidence
    {
        $componentType = $this->normalizeComponentType($this->first($input, [
            'component_type',
            'componentType',
        ]));
        $rawTitle = $this->scalarString($this->first($input, ['title', 'name']));
        $title = $this->normalizeText($rawTitle);
        $manufacturer = $this->normalizeText($this->scalarString($this->first($input, [
            'manufacturer',
            'brand',
            'metadata.manufacturer',
            'specifications.metadata.manufacturer',
        ])));
        $model = $this->explicitModel($input, $componentType);
        $attributes = $this->baseAttributes($input);

        [$componentAttributes, $derivedModel] = match ($componentType) {
            'ssd', 'hdd', 'sshd' => $this->storageEvidence($input, $rawTitle, $componentType),
            'ram' => $this->ramEvidence($input, $rawTitle),
            'gpu' => $this->gpuEvidence($input, $rawTitle),
            'cpu' => $this->cpuEvidence($input, $rawTitle, $model),
            'motherboard' => $this->motherboardEvidence($input, $rawTitle, $model),
            'psu' => $this->psuEvidence($input, $rawTitle, $model),
            default => [[], null],
        };

        if ($derivedModel !== null
            && ($model === null
                || (in_array($componentType, ['ssd', 'hdd', 'sshd'], true)
                    && str_starts_with($derivedModel, $model.'-')))) {
            $model = $derivedModel;
        }
        $attributes = array_replace($attributes, $componentAttributes);
        ksort($attributes, SORT_STRING);

        $mpns = $this->typedMpns($input);
        $condition = $this->condition($input, $rawTitle);
        $bundle = $this->boolean($this->first($input, ['bundle', 'is_bundle']))
            || ($rawTitle !== null && preg_match('/\b(?:bundle|combo)\b/i', $rawTitle) === 1);
        $marketplace = $this->boolean($this->first($input, ['marketplace', 'is_marketplace']))
            || $this->normalizeIdentifier($this->scalarString($this->first($input, ['seller_type']))) === 'MARKETPLACE';
        $inputSources = $input['sources'] ?? [];
        if (! is_array($inputSources)) {
            $inputSources = [$inputSources];
        }

        return new HardwareEvidence(
            componentType: $componentType,
            manufacturer: $manufacturer,
            model: $model,
            mpns: $mpns,
            attributes: $attributes,
            condition: $condition,
            bundle: $bundle,
            marketplace: $marketplace,
            sources: array_merge($inputSources, $sources),
            title: $title,
        );
    }

    public function fromPcPart(PcPart $part): HardwareEvidence
    {
        $componentType = $part->component_type->value;

        return $this->fromArray([
            'component_type' => $componentType,
            'title' => $part->name,
            'manufacturer' => $part->manufacturer,
            'series' => $part->series,
            'variant' => $part->variant,
            'part_numbers' => $part->part_numbers,
            'specifications' => $part->specifications,
        ], ['pc_part:'.$part->getKey()]);
    }

    public function normalizeIdentifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtoupper(Str::ascii(trim($value)));
        $value = preg_replace('/[^A-Z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? $value : null;
    }

    public function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtoupper(Str::ascii(trim($value)));
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $input */
    private function explicitModel(array $input, ?string $componentType): ?string
    {
        $model = $this->scalarString($this->first($input, [
            'model',
            'model_number',
            'modelNumber',
            'attributes.model',
        ]));
        if ($model !== null) {
            return $this->normalizeIdentifier($model);
        }

        $series = $this->scalarString($this->first($input, [
            'series',
            'metadata.series',
            'specifications.metadata.series',
        ]));
        $variant = $this->scalarString($this->first($input, [
            'variant',
            'metadata.variant',
            'specifications.metadata.variant',
        ]));

        if ($series !== null) {
            return $this->normalizeIdentifier($series);
        }

        if (in_array($componentType, ['cpu', 'motherboard'], true) && $variant !== null) {
            return $this->normalizeIdentifier($variant);
        }

        if ($variant !== null && preg_match('/^\s*\d+(?:\.\d+)?\s*(?:GB|TB|W)(?:\b|\s|$)/i', $variant) !== 1) {
            return $this->normalizeIdentifier($variant);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, bool|int|float|string>
     */
    private function baseAttributes(array $input): array
    {
        $raw = $input['attributes'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $attributes = [];
        foreach ($raw as $key => $value) {
            if (! is_string($key) || (! is_scalar($value) && ! $value instanceof BackedEnum)) {
                continue;
            }

            $normalizedKey = Str::snake($key);
            $normalizedValue = $this->normalizeAttributeValue($value);
            if ($normalizedValue !== null) {
                $attributes[$normalizedKey] = $normalizedValue;
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{array<string, bool|int|float|string>, ?string}
     */
    private function storageEvidence(array $input, ?string $title, string $componentType): array
    {
        $attributes = ['storage_type' => strtoupper($componentType)];
        $capacity = $this->capacityGb($this->first($input, [
            'attributes.capacity_gb',
            'capacity_gb',
            'specifications.capacity',
            'capacity',
        ]));
        $capacity ??= $this->capacityFromTitle($title);
        if ($capacity !== null) {
            $attributes['capacity_gb'] = $capacity;
        }

        $storageType = $this->normalizeIdentifier($this->scalarString($this->first($input, [
            'attributes.storage_type',
            'storage_type',
            'specifications.storage_type',
        ])));
        if ($storageType !== null) {
            $attributes['storage_type'] = $storageType;
        }

        $interface = $this->scalarString($this->first($input, [
            'attributes.interface',
            'interface',
            'specifications.interface',
        ]));
        if ($interface !== null) {
            $attributes['interface'] = $this->storageInterface($interface);
        }
        $formFactor = $this->scalarString($this->first($input, [
            'attributes.form_factor',
            'form_factor',
            'specifications.form_factor',
        ]));
        if ($formFactor !== null) {
            $attributes['form_factor'] = $this->storageFormFactor($formFactor);
        }

        if (! isset($attributes['interface']) && $title !== null) {
            if (preg_match('/\b(?:SATA(?:\s+III|\s+3|\s+6\s*GB\/S)?|NVME|PCIE(?:\s+[345](?:\.0)?\s*X4)?)\b/i', $title, $match) === 1) {
                $attributes['interface'] = $this->storageInterface($match[0]);
            }
        }
        if (! isset($attributes['form_factor']) && $title !== null) {
            if (preg_match('/\b(M\.?2[- ]?(?:2230|2242|2260|2280|22110)|2\.5(?:\s*INCH|\")?|3\.5(?:\s*INCH|\")?)\b/i', $title, $match) === 1) {
                $attributes['form_factor'] = $this->storageFormFactor($match[1]);
            }
        }

        $variant = $this->scalarString($this->first($input, [
            'variant',
            'metadata.variant',
            'specifications.metadata.variant',
        ]));
        if (($variant !== null && preg_match('/\bheatsink\b/i', $variant) === 1)
            || ($title !== null && preg_match('/\bwith\s+heatsink\b/i', $title) === 1)) {
            $attributes['heatsink'] = true;
        }

        return [$attributes, $this->storageModelFromTitle($title)];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{array<string, bool|int|float|string>, ?string}
     */
    private function ramEvidence(array $input, ?string $title): array
    {
        $attributes = [];
        $total = $this->capacityGb($this->first($input, [
            'attributes.total_capacity_gb',
            'total_capacity_gb',
            'specifications.capacity',
            'capacity',
        ]));
        $modules = $this->positiveInt($this->first($input, [
            'attributes.module_count',
            'module_count',
            'modules.quantity',
            'specifications.modules.quantity',
        ]));
        $perModule = $this->capacityGb($this->first($input, [
            'attributes.module_capacity_gb',
            'module_capacity_gb',
            'modules.capacity_gb',
            'specifications.modules.capacity_gb',
        ]));

        if ($title !== null && preg_match('/\b(\d+)\s*X\s*(\d+(?:\.\d+)?)\s*GB\b/i', $title, $match) === 1) {
            $modules ??= (int) $match[1];
            $perModule ??= (int) round((float) $match[2]);
            $total ??= $modules * $perModule;
        }
        $total ??= $this->capacityFromTitle($title);
        if ($total !== null) {
            $attributes['total_capacity_gb'] = $total;
        }
        if ($modules !== null) {
            $attributes['module_count'] = $modules;
        }
        if ($perModule !== null) {
            $attributes['module_capacity_gb'] = $perModule;
        }

        $generation = $this->normalizeIdentifier($this->scalarString($this->first($input, [
            'attributes.ddr_generation',
            'ddr_generation',
            'ram_type',
            'specifications.ram_type',
        ])));
        if ($generation === null && $title !== null && preg_match('/\bDDR[1-5]\b/i', $title, $match) === 1) {
            $generation = strtoupper($match[0]);
        }
        if ($generation !== null) {
            $attributes['ddr_generation'] = $generation;
        }

        $speed = $this->positiveInt($this->first($input, [
            'attributes.speed_mhz',
            'speed_mhz',
            'speed',
            'specifications.speed',
        ]));
        if ($speed === null && $title !== null
            && preg_match('/\b(?:DDR[1-5][ -]?|PC\d+-)?(\d{4,5})\s*(?:MHZ|MT\/S)?\b/i', $title, $match) === 1) {
            $speed = (int) $match[1];
        }
        if ($speed !== null) {
            $attributes['speed_mhz'] = $speed;
        }

        $this->copyIdentifier($attributes, 'form_factor', $input, [
            'attributes.form_factor',
            'form_factor',
            'specifications.form_factor',
        ]);

        return [$attributes, null];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{array<string, bool|int|float|string>, ?string}
     */
    private function gpuEvidence(array $input, ?string $title): array
    {
        $attributes = [];
        $gpuModel = $this->normalizeIdentifier($this->scalarString($this->first($input, [
            'attributes.gpu_model',
            'gpu_model',
            'chipset',
            'specifications.chipset',
        ])));
        $gpuModel ??= $this->gpuModelFromTitle($title);
        if ($gpuModel !== null) {
            $attributes['gpu_model'] = $gpuModel;
        }

        $vram = $this->capacityGb($this->first($input, [
            'attributes.vram_gb',
            'vram_gb',
            'memory',
            'specifications.memory',
        ]));
        if ($vram === null && $title !== null
            && preg_match('/\b(\d+(?:\.\d+)?)\s*GB\s*(?:GDDR\dX?|VRAM|GRAPHICS)?\b/i', $title, $match) === 1) {
            $vram = (int) round((float) $match[1]);
        }
        if ($vram !== null) {
            $attributes['vram_gb'] = $vram;
        }
        $this->copyIdentifier($attributes, 'memory_type', $input, [
            'attributes.memory_type',
            'memory_type',
            'specifications.memory_type',
        ]);

        return [$attributes, $gpuModel];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{array<string, bool|int|float|string>, ?string}
     */
    private function cpuEvidence(array $input, ?string $title, ?string $model): array
    {
        $attributes = [];
        $cpuModel = $model ?? $this->cpuModelFromTitle($title);
        if ($cpuModel !== null) {
            $attributes['cpu_model'] = $cpuModel;
            if (preg_match('/\d+(X3D|XTX|XT|KS|KF|K|GE|G|F|X|T|TE|HX|H|U)$/', $cpuModel, $match) === 1) {
                $attributes['cpu_suffix'] = $match[1];
            }
        }
        $this->copyIdentifier($attributes, 'socket', $input, [
            'attributes.socket',
            'socket',
            'specifications.socket',
        ]);
        if (! isset($attributes['socket']) && $title !== null
            && preg_match('/\b(?:AM[1-5]|LGA\s*\d{3,4}|S?TRX?\d|TR[45])\b/i', $title, $match) === 1) {
            $attributes['socket'] = $this->normalizeIdentifier($match[0]);
        }
        $this->copyIdentifier($attributes, 'packaging', $input, [
            'attributes.packaging',
            'packaging',
            'specifications.specifications.packaging',
            'specifications.packaging',
        ]);

        return [$attributes, $cpuModel];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{array<string, bool|int|float|string>, ?string}
     */
    private function motherboardEvidence(array $input, ?string $title, ?string $model): array
    {
        $attributes = [];
        $boardModel = $model ?? $this->motherboardModelFromTitle($title);
        if ($boardModel !== null) {
            $attributes['board_model'] = $boardModel;
        }
        $this->copyIdentifier($attributes, 'chipset', $input, [
            'attributes.chipset',
            'chipset',
            'specifications.chipset',
        ]);
        if (! isset($attributes['chipset']) && $title !== null
            && preg_match('/\b(?:A|B|H|Q|W|X|Z)\d{3,4}E?\b/i', $title, $match) === 1) {
            $attributes['chipset'] = strtoupper($match[0]);
        }
        $this->copyIdentifier($attributes, 'socket', $input, [
            'attributes.socket',
            'socket',
            'specifications.socket',
        ]);
        if (! isset($attributes['socket']) && $title !== null
            && preg_match('/\b(?:AM[1-5]|LGA\s*\d{3,4}|S?TRX?\d|TR[45])\b/i', $title, $match) === 1) {
            $attributes['socket'] = $this->normalizeIdentifier($match[0]);
        }

        $ramGeneration = $this->normalizeIdentifier($this->scalarString($this->first($input, [
            'attributes.ram_generation',
            'ram_generation',
            'memory.ram_type',
            'specifications.memory.ram_type',
        ])));
        if ($ramGeneration === null && $title !== null && preg_match('/\bDDR[1-5]\b/i', $title, $match) === 1) {
            $ramGeneration = strtoupper($match[0]);
        }
        if ($ramGeneration !== null) {
            $attributes['ram_generation'] = $ramGeneration;
        }

        $revision = $this->normalizeIdentifier($this->scalarString($this->first($input, [
            'attributes.revision',
            'revision',
            'metadata.revision',
            'specifications.metadata.revision',
        ])));
        if ($revision === null && $title !== null
            && preg_match('/\b(?:REV(?:ISION)?[ ._-]*|R)([0-9]+(?:\.[0-9]+)?[A-Z]?)\b/i', $title, $match) === 1) {
            $revision = $this->normalizeIdentifier($match[1]);
        }
        if ($revision !== null) {
            $attributes['revision'] = $revision;
        }

        return [$attributes, $boardModel];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{array<string, bool|int|float|string>, ?string}
     */
    private function psuEvidence(array $input, ?string $title, ?string $model): array
    {
        $attributes = [];
        $psuModel = $model ?? $this->psuModelFromTitle($title);
        if ($psuModel !== null) {
            $attributes['psu_model'] = $psuModel;
        }
        $wattage = $this->positiveInt($this->first($input, [
            'attributes.wattage_w',
            'wattage_w',
            'wattage',
            'specifications.wattage',
        ]));
        if ($wattage === null && $title !== null && preg_match('/\b(\d{3,4})\s*W(?:ATT)?S?\b/i', $title, $match) === 1) {
            $wattage = (int) $match[1];
        }
        if ($wattage !== null) {
            $attributes['wattage_w'] = $wattage;
        }
        $this->copyIdentifier($attributes, 'form_factor', $input, [
            'attributes.form_factor',
            'form_factor',
            'specifications.form_factor',
        ]);
        if (! isset($attributes['form_factor']) && $title !== null
            && preg_match('/\b(SFX-L|SFX|TFX|FLEX\s+ATX|ATX(?:\/EPS)?)\b/i', $title, $match) === 1) {
            $attributes['form_factor'] = $this->normalizeIdentifier($match[1]);
        }
        $revision = $this->normalizeIdentifier($this->scalarString($this->first($input, [
            'attributes.revision',
            'revision',
            'metadata.variant',
            'specifications.metadata.variant',
        ])));
        if ($revision !== null) {
            $attributes['revision'] = $revision;
        }

        return [$attributes, $psuModel];
    }

    /** @param array<string, mixed> $input */
    private function typedMpns(array $input): array
    {
        $values = [];
        foreach (['mpns', 'mpn', 'manufacturer_part_number', 'part_numbers', 'metadata.part_numbers', 'specifications.metadata.part_numbers'] as $path) {
            $value = $this->get($input, $path);
            if (is_string($value) && str_starts_with(trim($value), '[')) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : $value;
            }
            foreach (is_array($value) ? $value : [$value] as $mpn) {
                $normalized = $this->normalizeIdentifier($this->scalarString($mpn));
                if ($normalized !== null && $this->isCredibleMpn($normalized)) {
                    $values[] = $normalized;
                }
            }
        }

        $partNumberType = $this->normalizeIdentifier($this->scalarString($this->first($input, [
            'part_number_type',
            'partNumberType',
        ])));
        if (in_array($partNumberType, ['MPN', 'MANUFACTURER-PART-NUMBER'], true)) {
            $partNumber = $this->normalizeIdentifier($this->scalarString($this->first($input, [
                'part_number',
                'partNumber',
            ])));
            if ($partNumber !== null && $this->isCredibleMpn($partNumber)) {
                $values[] = $partNumber;
            }
        }

        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }

    private function isCredibleMpn(string $value): bool
    {
        return strlen(str_replace('-', '', $value)) >= 5
            && preg_match('/\d/', $value) === 1;
    }

    /** @param array<string, mixed> $input */
    private function condition(array $input, ?string $title): ?string
    {
        $condition = strtolower((string) ($this->normalizeIdentifier($this->scalarString($this->first($input, [
            'condition',
            'listing_condition',
        ]))) ?? ''));
        $condition = match ($condition) {
            'open-box', 'openbox' => 'open_box',
            'pre-owned', 'preowned' => 'preowned',
            'factory-refurbished', 'refurb' => 'refurbished',
            '' => null,
            default => str_replace('-', '_', $condition),
        };

        if ($title === null) {
            return $condition;
        }
        if (preg_match('/\bopen[- ]box\b/i', $title) === 1) {
            return 'open_box';
        }
        if (preg_match('/\bpre[- ]?owned\b/i', $title) === 1) {
            return 'preowned';
        }
        if (preg_match('/\brefurb(?:ished)?\b/i', $title) === 1) {
            return 'refurbished';
        }
        if (preg_match('/\brenewed\b/i', $title) === 1) {
            return 'renewed';
        }
        if (preg_match('/\bused\b/i', $title) === 1) {
            return 'used';
        }

        return $condition;
    }

    private function gpuModelFromTitle(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        foreach ([
            '/\b(?:GEFORCE\s+)?(RTX\s*\d{3,4}(?:\s*(?:TI|SUPER)){0,2})\b/i',
            '/\b(?:RADEON\s+)?(RX\s*\d{3,4}(?:\s*(?:XTX|XT|GRE))?)\b/i',
            '/\b(?:INTEL\s+)?(ARC\s+[A-Z]\d{3})\b/i',
        ] as $pattern) {
            if (preg_match($pattern, $title, $match) === 1) {
                return $this->normalizeIdentifier($match[1]);
            }
        }

        return null;
    }

    private function cpuModelFromTitle(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        foreach ([
            '/\b(RYZEN\s+(?:3|5|7|9)\s+\d{4,5}[A-Z0-9]*)\b/i',
            '/\b(CORE\s+(?:ULTRA\s+)?(?:I[3579][ -]?)?\d{3,5}[A-Z]*)\b/i',
        ] as $pattern) {
            if (preg_match($pattern, $title, $match) === 1) {
                return $this->normalizeIdentifier($match[1]);
            }
        }

        return null;
    }

    private function motherboardModelFromTitle(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        if (preg_match('/\b((?:A|B|H|Q|W|X|Z)\d{3,4}[A-Z]?(?:[- ][A-Z0-9]+){0,4})(?=\s+(?:MOTHERBOARD|MAINBOARD|PLACA|DDR[1-5]|ATX|AM[1-5]|LGA)|$)/i', $title, $match) === 1) {
            return $this->normalizeIdentifier($match[1]);
        }

        return null;
    }

    private function psuModelFromTitle(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        if (preg_match('/\b([A-Z]{1,8}[- ]?\d{3,4}[A-Z0-9-]*)\b/i', $title, $match) === 1) {
            return $this->normalizeIdentifier($match[1]);
        }

        return null;
    }

    private function storageModelFromTitle(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        if (preg_match('/\b(\d{3,4}\s+(?:QVO|EVO(?:\s+PLUS)?|PRO))\b/i', $title, $match) === 1) {
            return $this->normalizeIdentifier($match[1]);
        }

        return null;
    }

    private function storageInterface(string $value): string
    {
        if (preg_match('/\bSATA\b/i', $value) === 1
            && preg_match('/(?:\bIII\b|\b3(?:\.0)?\b|\b6(?:\.0)?\s*G(?:B|BIT)\s*\/\s*S)/i', $value) === 1) {
            return 'SATA-III';
        }

        return $this->normalizeIdentifier($value) ?? '';
    }

    private function storageFormFactor(string $value): string
    {
        if (preg_match('/\b2[.,]5(?:\s*(?:INCH|IN|"))?/i', $value) === 1) {
            return '2-5-INCH';
        }
        if (preg_match('/\b3[.,]5(?:\s*(?:INCH|IN|"))?/i', $value) === 1) {
            return '3-5-INCH';
        }

        return $this->normalizeIdentifier($value) ?? '';
    }

    private function capacityFromTitle(?string $title): ?int
    {
        if ($title === null || preg_match('/\b(\d+(?:\.\d+)?)\s*(TB|GB)\b/i', $title, $match) !== 1) {
            return null;
        }

        return $this->capacityGb($match[1].$match[2]);
    }

    private function capacityGb(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return $value > 0 ? (int) round($value) : null;
        }
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(TB|GB)$/i', $value, $match) === 1) {
            $multiplier = strtoupper($match[2]) === 'TB' ? 1000 : 1;

            return (int) round((float) $match[1] * $multiplier);
        }
        if (is_numeric($value) && (float) $value > 0) {
            return (int) round((float) $value);
        }

        return null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/\d+(?:\.\d+)?/', $value, $match) === 1) {
            $value = $match[0];
        }

        return is_numeric($value) && (float) $value > 0 ? (int) round((float) $value) : null;
    }

    /**
     * @param  array<string, bool|int|float|string>  $attributes
     * @param  array<string, mixed>  $input
     * @param  list<string>  $paths
     */
    private function copyIdentifier(array &$attributes, string $key, array $input, array $paths): void
    {
        $value = $this->normalizeIdentifier($this->scalarString($this->first($input, $paths)));
        if ($value !== null) {
            $attributes[$key] = $value;
        }
    }

    private function normalizeComponentType(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        $value = strtolower((string) ($this->normalizeIdentifier($this->scalarString($value)) ?? ''));
        $value = str_replace('-', '_', $value);

        return in_array($value, [
            'cpu',
            'gpu',
            'hdd',
            'motherboard',
            'other',
            'pc_case',
            'psu',
            'ram',
            'ssd',
            'sshd',
            'cpu_cooler',
        ], true) ? $value : null;
    }

    private function normalizeAttributeValue(mixed $value): bool|int|float|string|null
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return null;
        }

        return $this->normalizeIdentifier($value);
    }

    private function scalarString(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /** @param array<string, mixed> $input */
    private function first(array $input, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = $this->get($input, $path);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $input */
    private function get(array $input, string $path): mixed
    {
        $value = $input;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
