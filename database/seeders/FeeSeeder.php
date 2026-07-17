<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Fee;
use App\Models\Student;
use App\Models\AcademicSession;
use App\Models\FeeType;
use App\Models\User;

class FeeSeeder extends Seeder
{
    public function run(): void
    {
        $student = Student::first();
        $session = AcademicSession::first();
        $feeType = FeeType::first();
        $admin   = User::where('role', 'admin')->first();

        if (!$student || !$session || !$feeType || !$admin) {
            throw new \Exception("Dependencies missing for FeeSeeder. Ensure other seeders run first.");
        }

        $fees = [
            [
                'receipt_no'     => 'RCP-' . rand(10000, 99999),
                'student_id'     => $student->id,
                'fee_type_id'    => $feeType->id,
                'session_id'     => $session->id,
                'collected_by'   => $admin->id,
                'amount'         => 12500.00,
                'payment_method' => 'cash',
                'status'         => 'paid',
                'paid_at'        => now(),
                'remarks'        => 'First Term Tuition Fee',
            ],
            [
                'receipt_no'     => 'RCP-' . rand(10000, 99999),
                'student_id'     => $student->id,
                'fee_type_id'    => $feeType->id,
                'session_id'     => $session->id,
                'collected_by'   => $admin->id,
                'amount'         => 5000.00,
                'payment_method' => 'mpesa',
                'status'         => 'paid',
                'paid_at'        => now()->subDays(5),
                'remarks'        => 'Transport Fee',
            ],
        ];

        foreach ($fees as $fee) {
            Fee::updateOrCreate(
                ['receipt_no' => $fee['receipt_no']],
                $fee
            );
        }
    }
}
