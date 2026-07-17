<?php

namespace Database\Factories;

use App\Models\FeeType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeType>
 */
class FeeTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->word() . ' Fee',
            'amount' => $this->faker->randomFloat(2, 1000, 50000),
            'frequency' => $this->faker->randomElement(['monthly', 'annual', 'term']),
        ];
    }
}
