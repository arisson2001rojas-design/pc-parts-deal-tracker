<?php

namespace App\Services;

use App\Enums\ComponentType;

class PcComponentClassifier
{
    public function detect(string $title): ?ComponentType
    {
        $title = trim($title);
        if ($title === '' || preg_match(
            '/\b(?:laptop|notebook|chromebook|gaming\s+pc|desktop\s+computer|prebuilt|all-in-one|motherboard|mainboard|heatsink|water\s+block|thermal\s+paste|enclosure|adapter|replacement\s+fan|extension\s+cable|graphics?\s+card\s+(?:holder|support))\b|\bcpu.{0,20}(?:cooler|fan)\b|\b(?:cooler|fan).{0,20}cpu\b|\b(?:power|psu).{0,20}cable\b/i',
            $title,
        )) {
            return null;
        }

        if (preg_match('/\b(?:cpu|processor|ryzen|athlon|threadripper|celeron|pentium|core\s+(?:ultra\s+)?[i3579])\b/i', $title)) {
            return ComponentType::Cpu;
        }
        if (preg_match('/\b(?:gpu|graphics?\s+card|video\s+card|geforce|radeon|intel\s+arc)\b/i', $title)) {
            return ComponentType::Gpu;
        }
        if (preg_match('/\b(?:psu|power\s+suppl(?:y|ies))\b/i', $title)) {
            return ComponentType::Psu;
        }
        if (preg_match('/\b(?:ssd|solid[ -]state|nvme)\b/i', $title)) {
            return ComponentType::Ssd;
        }
        if (preg_match('/\b(?:ram|ddr[345]|so-?dimm)\b/i', $title)
            && preg_match('/\b(?:ram|memory|ddr[345]|so-?dimm)\b/i', $title)) {
            return ComponentType::Ram;
        }

        return null;
    }
}
