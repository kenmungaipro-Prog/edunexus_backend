<?php

namespace App\Imports;

use App\Models\Student;
use App\Models\ClassRoom;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Concerns\{
    ToCollection, WithHeadingRow, WithValidation,
    WithChunkReading, SkipsOnFailure, SkipsFailures
};
use Maatwebsite\Excel\Validators\Failure;

// ============================================================
// app/Imports/StudentsImport.php
// ============================================================

class StudentsImport implements ToCollection, WithHeadingRow, WithValidation, WithChunkReading, SkipsOnFailure
{
    use SkipsFailures;

    private int $rowCount = 0;

    public function __construct(
        private readonly int $schoolId,
        private readonly ?int $sessionId
    ) {}

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $class = ClassRoom::where('school_id', $this->schoolId)
                ->where('name', $row['class'])
                ->first();

            if (! $class) continue;

            // Create or find parent
            $parent = User::firstOrCreate(
                ['email' => $row['parent_email']],
                [
                    'name'      => $row['parent_name'],
                    'password'  => Hash::make('Parent@123'),
                    'role'      => 'parent',
                    'status'    => 'active',
                    'school_id' => $this->schoolId,
                ]
            );

            Student::create([
                'school_id'    => $this->schoolId,
                'session_id'   => $this->sessionId,
                'class_id'     => $class->id,
                'parent_id'    => $parent->id,
                'admission_no' => 'ADM-IMP-' . uniqid(),
                'roll_number'  => 'S-' . str_pad(Student::count() + 1001, 4, '0', STR_PAD_LEFT),
                'first_name'   => $row['first_name'],
                'last_name'    => $row['last_name'],
                'date_of_birth'=> $row['date_of_birth'],
                'gender'       => strtolower($row['gender']),
                'blood_group'  => $row['blood_group']  ?? null,
                'address'      => $row['address']      ?? null,
                'religion'     => $row['religion']     ?? null,
                'category'     => $row['category']     ?? 'General',
                'status'       => 'active',
                'admission_date' => now()->toDateString(),
            ]);

            $this->rowCount++;
        }
    }

    public function rules(): array
    {
        return [
            'first_name'   => 'required|string|max:100',
            'last_name'    => 'required|string|max:100',
            'date_of_birth'=> 'required|date',
            'gender'       => 'required|in:Male,Female,Other,male,female,other',
            'class'        => 'required|string',
            'parent_name'  => 'required|string|max:255',
            'parent_email' => 'required|email',
        ];
    }

    public function customValidationMessages(): array
    {
        return [
            'parent_email.email' => 'Row :attribute has an invalid parent email.',
            'gender.in'          => 'Row :attribute gender must be Male, Female, or Other.',
        ];
    }

    public function chunkSize(): int  { return 100; }
    public function getRowCount(): int { return $this->rowCount; }
}
