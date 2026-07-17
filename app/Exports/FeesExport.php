<?php

// ============================================================
// app/Exports/FeesExport.php
// ============================================================
namespace App\Exports;

use App\Models\Fee;
use Maatwebsite\Excel\Concerns\{FromQuery, WithHeadings, WithMapping, ShouldAutoSize};

class FeesExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(private readonly array $filters = []) {}

    public function query()
    {
        return Fee::with(['student.classRoom', 'feeType', 'collectedBy'])
            ->when($this->filters['status'] ?? null,  fn ($q, $v) => $q->where('status', $v))
            ->when($this->filters['month']  ?? null,  fn ($q, $v) => $q->whereMonth('paid_at', $v))
            ->when($this->filters['year']   ?? null,  fn ($q, $v) => $q->whereYear('paid_at', $v))
            ->orderByDesc('created_at');
    }

    public function headings(): array
    {
        return ['Receipt No', 'Student', 'Admission No', 'Class', 'Fee Type', 'Amount', 'Method', 'Transaction ID', 'Collected By', 'Date', 'Status'];
    }

    public function map($fee): array
    {
        return [
            $fee->receipt_no,
            $fee->student->full_name,
            $fee->student->admission_no,
            $fee->student->classRoom->name,
            $fee->feeType->name,
            formatCurrency($fee->amount),
            strtoupper($fee->payment_method),
            $fee->transaction_id ?? '—',
            $fee->collectedBy->name,
            $fee->paid_at?->format('d/m/Y H:i') ?? '—',
            ucfirst($fee->status),
        ];
    }
}
