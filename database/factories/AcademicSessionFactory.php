<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AcademicSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array // <--- Change 'void' to 'array' here
    {
        return [
            'school_id'  => \App\Models\School::factory(),
            'name'       => '2024-2025',
            'is_current' => true,
            'start_date' => '2024-01-01',
            'end_date'   => '2024-12-31',
        ];
    }
}