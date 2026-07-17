<?php

// ============================================================
// app/Exports/AttendanceExport.php
// ============================================================
namespace App\Exports;

use App\Models\Attendance;
use App\Models\Student;
use Maatwebsite\Excel\Concerns\{FromArray, WithHeadings, WithStyles, ShouldAutoSize};

class AttendanceExport implements FromArray, WithHeadings, WithStyles, ShouldAutoSize
{
    public function __construct(
        private readonly int $classId,
        private readonly int $month,
        private readonly int $year
    ) {}

    public function array(): array
    {
        $students   = Student::where('class_id', $this->classId)->orderBy('roll_number')->get();
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $this->month, $this->year);
        $rows = [];

        foreach ($students as $student) {
            $row = [
                $student->roll_number,
                $student->full_name,
            ];

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $date   = sprintf('%04d-%02d-%02d', $this->year, $this->month, $d);
                $record = Attendance::where('student_id', $student->id)
                    ->whereDate('date', $date)
                    ->first();

                $row[] = match ($record?->status) {
                    'present' => 'P',
                    'absent'  => 'A',
                    'late'    => 'L',
                    'holiday' => 'H',
                    default   => '—',
                };
            }

            $row[] = $student->attendance_percentage . '%';
            $rows[] = $row;
        }

        return $rows;
    }

    public function headings(): array
    {
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $this->month, $this->year);
        return array_merge(
            ['Roll No', 'Student Name'],
            array_map(fn ($d) => str_pad($d, 2, '0', STR_PAD_LEFT), range(1, $daysInMonth)),
            ['Attendance %']
        );
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}

