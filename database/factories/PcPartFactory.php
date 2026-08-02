<?php

namespace Database\Factories;

use App\Enums\ComponentType;
use App\Models\PcPart;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PcPart>
 */
class PcPartFactory extends Factory
{
    protected $model = PcPart::class;

    public function definition(): array
    {
        return [
            'opendb_id' => (string) Str::uuid(),
            'component_type' => $this->faker->randomElement(ComponentType::cases()),
            'name' => $this->faker->words(4, true),
            'manufacturer' => $this->faker->company,
            'series' => $this->faker->word,
            'variant' => null,
            'part_numbers' => [$this->faker->bothify('??-####')],
            'release_year' => $this->faker->numberBetween(2020, (int) date('Y')),
            'retailer_urls' => [
                'amazon' => 'https://www.amazon.com/dp/'.$this->faker->bothify('B0????????'),
            ],
            'specifications' => [],
            'source_url' => 'https://github.com/buildcores/buildcores-open-db',
            'source_updated_at' => now(),
        ];
    }
}

