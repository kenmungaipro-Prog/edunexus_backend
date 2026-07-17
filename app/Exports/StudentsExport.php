<?php

// ============================================================
// app/Exports/StudentsExport.php
// ============================================================

namespace App\Exports;

use App\Models\Student;
use Maatwebsite\Excel\Concerns\{FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize};
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class StudentsExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    public function __construct(
        private readonly int   $schoolId,
        private readonly ?int  $classId   = null,
        private readonly ?string $status  = null
    ) {}

    public function query()
    {
        return Student::with(['classRoom', 'parent'])
            ->where('school_id', $this->schoolId)
            ->when($this->classId, fn ($q, $v) => $q->where('class_id', $v))
            ->when($this->status,  fn ($q, $v) => $q->where('status', $v))
            ->orderBy('first_name');
    }

    public function headings(): array
    {
        return [
            'Admission No', 'Roll No', 'First Name', 'Last Name', 'Class',
            'Gender', 'Date of Birth', 'Blood Group', 'Category',
            'Parent Name', 'Parent Email', 'Attendance %', 'Fee Status', 'Status',
        ];
    }

    public function map($student): array
    {
        return [
            $student->admission_no,
            $student->roll_number,
            $student->first_name,
            $student->last_name,
            $student->classRoom->name,
            ucfirst($student->gender),
            $student->date_of_birth->format('d/m/Y'),
            $student->blood_group ?? '—',
            $student->category    ?? 'General',
            $student->parent->name  ?? '—',
            $student->parent->email ?? '—',
            $student->attendance_percentage . '%',
            $student->fee_status,
            $student->status,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1a2035']]],
        ];
    }
}
