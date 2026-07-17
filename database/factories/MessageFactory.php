<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            // We'll override these in the seeder, but here are defaults
            'sender_id'   => User::factory(),
            'receiver_id' => User::factory(),
            'body'        => $this->faker->paragraph(rand(1, 3)),
            'read_at'     => $this->faker->boolean(70) ? now() : null, // 70% chance it's read
            'created_at'  => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }
}