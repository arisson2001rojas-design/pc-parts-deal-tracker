<?php

namespace Database\Factories;

use App\Enums\ComponentType;
use App\Models\HardwareIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<HardwareIdentity> */
class HardwareIdentityFactory extends Factory
{
    protected $model = HardwareIdentity::class;

    public function definition(): array
    {
        $manufacturer = $this->faker->company;
        $model = strtoupper($this->faker->bothify('MODEL-####'));
        $mpn = strtoupper($this->faker->bothify('MPN-####-??'));
        $key = Str::uuid()->toString();

        return [
            'component_type' => $this->faker->randomElement(ComponentType::cases()),
            'manufacturer' => $manufacturer,
            'manufacturer_normalized' => strtoupper(Str::ascii($manufacturer)),
            'model' => $model,
            'model_normalized' => $model,
            'mpn' => $mpn,
            'mpn_normalized' => $mpn,
            'authoritative_key_hash' => hash('sha256', 'factory:'.$key),
            'variant_fingerprint' => hash('sha256', 'variant:'.$key),
            'attributes' => [],
        ];
    }
}
