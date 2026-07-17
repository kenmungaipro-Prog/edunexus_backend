<?php

namespace Database\Factories;

use App\Models\Fee;
use App\Models\Student;
use App\Models\FeeType;
use App\Models\AcademicSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fee>
 */
class FeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'receipt_no' => 'RCPT-' . $this->faker->unique()->numerify('######'),
            'student_id' => Student::factory(),
            'fee_type_id' => FeeType::factory(),
            'session_id' => AcademicSession::where('is_current', true)->first()?->id ?? AcademicSession::factory(),
            'collected_by' => User::where('role', 'accountant')->first()?->id ?? User::factory(),
            'amount' => $this->faker->randomFloat(2, 500, 15000),
            'payment_method' => $this->faker->randomElement(['cash', 'upi', 'card']),
            'transaction_id' => $this->faker->optional()->bothify('TXN-#####'),
            'status' => 'paid',
            'paid_at' => now(),
            'remarks' => $this->faker->sentence(),
        ];
    }
}
