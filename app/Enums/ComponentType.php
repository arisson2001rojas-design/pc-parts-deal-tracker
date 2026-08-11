<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ComponentType: string implements HasColor, HasLabel
{
    case Cpu = 'cpu';
    case Gpu = 'gpu';
    case Motherboard = 'motherboard';
    case Ram = 'ram';
    case Ssd = 'ssd';
    case Hdd = 'hdd';
    case Sshd = 'sshd';
    case CpuCooler = 'cpu_cooler';
    case PcCase = 'pc_case';
    case Psu = 'psu';
    case Other = 'other';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Cpu => 'CPU',
            self::Gpu => 'GPU',
            self::Motherboard => 'Motherboard',
            self::Ram => 'RAM',
            self::Ssd => 'SSD',
            self::Hdd => 'HDD',
            self::Sshd => 'SSHD',
            self::CpuCooler => 'CPU cooler',
            self::PcCase => 'PC case',
            self::Psu => 'Power supply',
            self::Other => 'Other component',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Cpu => 'primary',
            self::Gpu => 'success',
            self::Motherboard => 'warning',
            self::Ram => 'info',
            self::Ssd => 'warning',
            self::Hdd => 'gray',
            self::Sshd => 'info',
            self::CpuCooler => 'primary',
            self::PcCase => 'gray',
            self::Psu => 'danger',
            self::Other => 'gray',
        };
    }
}
