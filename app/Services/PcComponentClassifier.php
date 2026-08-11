<?php

namespace App\Services;

use App\Enums\ComponentType;

class PcComponentClassifier
{
    public function detect(string $title): ?ComponentType
    {
        $title = trim($title);
        if ($title === '') {
            return null;
        }

        $componentCompatibility = preg_match(
            '/\\b(?:compatible\\s+(?:with|con)|works?\\s+with|for|para)\\b.{0,80}\\b(?:laptop|notebook|chromebook|desktop(?:\\s+(?:computer|pc))?|pc\\s+de\\s+escritorio|computadora\\s+de\\s+escritorio|computadora\\s+port[aá]til)\\b|\\b(?:laptop|notebook)\\s+(?:memory|ram|ssd|nvme|hdd|hard\\s+drive)\\b/i',
            $title,
        ) === 1;

        $completeComputer = preg_match(
            '/\\b(?:laptop|notebook|chromebook|desktop\\s+(?:computer|pc)|prebuilt(?:\\s+pc)?|mini\\s+pc|all-in-one\\s+(?:pc|computer|desktop)|gaming\\s+(?:desktop|computer|pc(?:\\s+(?:desktop|computer|system))?)|pc\\s+de\\s+escritorio|computadora\\s+de\\s+escritorio|computadora\\s+port[aá]til)\\b/i',
            $title,
        ) === 1;

        if ($completeComputer && ! $componentCompatibility) {
            return null;
        }

        if (preg_match(
            '/\\b(?:thermal\\s+paste|pasta\\s+t[eé]rmica|water\\s+block|storage\\s+enclosure|drive\\s+enclosure|adapter|replacement\\s+fan|extension\\s+cable|case\\s+fan|chassis\\s+fan|graphics?\\s+card\\s+(?:holder|support)|gpu\\s+(?:holder|support)|(?:power|psu).{0,20}cable)\\b/i',
            $title,
        )) {
            return null;
        }

        if (preg_match('/\\b(?:mother\\s*board|mainboard|mobo|placa\\s+(?:base|madre)|tarjeta\\s+madre)\\b/i', $title)) {
            return ComponentType::Motherboard;
        }
        if (preg_match('/\\b(?:cpu\\s+(?:air\\s+|liquid\\s+)?cooler|processor\\s+cooler|air\\s+cpu\\s+cooler|tower\\s+cpu\\s+cooler|(?:aio|all-in-one)\\s+(?:liquid\\s+)?(?:cpu\\s+)?cooler|liquid\\s+cpu\\s+cooler|cpu\\s+liquid\\s+cooler|cpu\\s+heatsink|heatsink\\s+for\\s+cpu|disipador(?:\\s+de|\\s+para)?\\s+(?:cpu|procesador)|enfriador(?:\\s+de|\\s+para)?\\s+(?:cpu|procesador)|refrigeraci[oó]n\\s+l[ií]quida(?:\\s+para\\s+(?:cpu|procesador))?)\\b/i', $title)) {
            return ComponentType::CpuCooler;
        }
        if (preg_match('/\\b(?:pc\\s+case|computer\\s+case|gaming\\s+case|atx\\s+(?:mid[-\\s]?tower\\s+)?case|micro[-\\s]?atx\\s+case|mini[-\\s]?itx\\s+case|mid[-\\s]?tower(?:\\s+case)?|full[-\\s]?tower(?:\\s+case)?|mini[-\\s]?tower(?:\\s+case)?|pc\\s+chassis|computer\\s+chassis|gabinete(?:\\s+(?:para|de)\\s+(?:pc|computadora))?|caja\\s+(?:para|de)\\s+(?:pc|computadora))\\b/i', $title)) {
            return ComponentType::PcCase;
        }
        if (preg_match('/\\b(?:sshd|solid[-\\s]?state\\s+hybrid(?:\\s+drive)?|hybrid\\s+(?:hard\\s+)?drive)\\b/i', $title)) {
            return ComponentType::Sshd;
        }
        if (preg_match('/\\b(?:ssd|solid[ -]?state(?:\\s+drive)?|nvme|unidad\\s+de\\s+estado\\s+s[oó]lido)\\b/i', $title)) {
            return ComponentType::Ssd;
        }
        if (preg_match('/\\b(?:hdd|hard\\s+(?:disk|drive)(?:\\s+drive)?|disco\\s+duro)\\b/i', $title)) {
            return ComponentType::Hdd;
        }
        if (preg_match('/\\b(?:psu|power\\s+suppl(?:y|ies)|fuente\\s+de\\s+(?:poder|alimentaci[oó]n))\\b/i', $title)) {
            return ComponentType::Psu;
        }
        if (preg_match('/\\b(?:gpu|graphics?\\s+card|video\\s+card|geforce|radeon|intel\\s+arc|tarjeta\\s+gr[aá]fica)\\b/i', $title)) {
            return ComponentType::Gpu;
        }
        if (preg_match('/\\b(?:ram|ddr[345]|so-?dimm|dimm|memory|memoria)\\b/i', $title)
            && preg_match('/\\b(?:ram|memory|memoria|ddr[345]|so-?dimm|dimm)\\b/i', $title)) {
            return ComponentType::Ram;
        }
        if (preg_match('/\\b(?:cpu|processor|procesador|ryzen|athlon|threadripper|celeron|pentium|core\\s+(?:ultra\\s+)?[i3579])\\b/i', $title)) {
            return ComponentType::Cpu;
        }

        return null;
    }
}
