<?php

namespace Database\Factories;

use App\Models\ClassRoom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassRoom>
 */
class ClassRoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $grade = $this->faker->numberBetween(1, 12);
        $section = $this->faker->randomElement(['A', 'B', 'C', 'D']);

        return [
            'school_id'        => \App\Models\School::factory(),
            'session_id'       => \App\Models\AcademicSession::factory(),
            'name'             => "Grade {$grade}-{$section}",
            'grade'            => $grade,
            'section'          => $section,
            'capacity'         => 40,
            'room'             => 'Room ' . $this->faker->bothify('??-###'),
            'class_teacher_id' => null,
        ];
    }
}
