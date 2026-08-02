<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ComponentType: string implements HasColor, HasLabel
{
    case Cpu = 'cpu';
    case Gpu = 'gpu';
    case Ram = 'ram';
    case Ssd = 'ssd';
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
            self::Ram => 'RAM',
            self::Ssd => 'SSD',
            self::Psu => 'Power supply',
            self::Other => 'Other component',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Cpu => 'primary',
            self::Gpu => 'success',
            self::Ram => 'info',
            self::Ssd => 'warning',
            self::Psu => 'danger',
            self::Other => 'gray',
        };
    }
}
