<?php

namespace Database\Factories;

use App\Models\{School, AcademicSession, ClassRoom};
use Illuminate\Database\Eloquent\Factories\Factory;

class StudentFactory extends Factory
{
    public function definition(): array
    {
        static $rollCount = 1000;

        return [
            // Mandatory Foreign Keys
            'school_id'     => School::first()?->id ?? School::factory(),
            'session_id'    => AcademicSession::where('is_current', true)->first()?->id ?? AcademicSession::factory(),
            'class_id'      => ClassRoom::first()?->id ?? ClassRoom::factory(),
            
            // Student Details
            'admission_no'  => 'ADM-' . $this->faker->unique()->numerify('####'),
            'roll_number'   => 'S-' . ++$rollCount,
            'first_name'    => $this->faker->firstName(),
            'last_name'     => $this->faker->lastName(),
            'date_of_birth' => $this->faker->dateTimeBetween('-18 years', '-10 years')->format('Y-m-d'),
            'gender'        => $this->faker->randomElement(['male', 'female']),
            'blood_group'   => $this->faker->randomElement(['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-']),
            'address'       => $this->faker->address(),
            'status'        => 'active',
            'admission_date'=> $this->faker->dateTimeBetween('-3 years', 'now')->format('Y-m-d'),
            'category'      => $this->faker->randomElement(['General', 'OBC', 'SC', 'ST']),
        ];
    }
}