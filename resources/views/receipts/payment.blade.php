<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        * { box-sizing: border-box; }
        body { color: #1f2937; font-family: DejaVu Sans, sans-serif; font-size: 11px; }
        .receipt { padding: 28px; }
        .header { border-bottom: 2px solid #2563eb; margin-bottom: 20px; padding-bottom: 14px; }
        .school { color: #1d4ed8; font-size: 19px; font-weight: bold; }
        .contact { color: #64748b; font-size: 9px; line-height: 1.5; margin-top: 4px; }
        .title { color: #111827; font-size: 15px; font-weight: bold; margin-top: 18px; }
        .receipt-no { color: #475569; font-family: DejaVu Sans Mono, monospace; margin-top: 4px; }
        .section { background: #f8fafc; border: 1px solid #e2e8f0; margin: 12px 0; padding: 12px; }
        .section-title { color: #1d4ed8; font-size: 9px; font-weight: bold; letter-spacing: 1px; margin-bottom: 8px; text-transform: uppercase; }
        .row { margin: 5px 0; }
        .label { color: #64748b; display: inline-block; width: 38%; }
        .value { color: #111827; font-weight: bold; }
        .amount { background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; font-size: 22px; font-weight: bold; margin: 16px 0; padding: 16px; text-align: center; }
        .amount-label { color: #475569; display: block; font-size: 9px; font-weight: normal; margin-bottom: 5px; }
        .footer { border-top: 1px solid #e2e8f0; color: #64748b; font-size: 9px; margin-top: 20px; padding-top: 10px; text-align: center; }
    </style>
</head>
<body>
<div class="receipt">
    <div class="header">
        <div class="school">{{ $receipt->school->name ?? 'EduNexus School' }}</div>
        <div class="contact">
            {{ $receipt->school->address ?? '' }}<br>
            @if($receipt->school?->phone) Tel: {{ $receipt->school->phone }} @endif
            @if($receipt->school?->email) · {{ $receipt->school->email }} @endif
        </div>
        <div class="title">OFFICIAL PAYMENT RECEIPT</div>
        <div class="receipt-no">{{ $receipt->receipt_number }}</div>
    </div>

    <div class="section">
        <div class="section-title">Student</div>
        <div class="row"><span class="label">Name</span><span class="value">{{ $receipt->payment->student->full_name ?? '—' }}</span></div>
        <div class="row"><span class="label">Admission number</span><span class="value">{{ $receipt->payment->student->admission_no ?? '—' }}</span></div>
        <div class="row"><span class="label">Class</span><span class="value">{{ $receipt->payment->student->classRoom->name ?? '—' }}</span></div>
    </div>

    <div class="section">
        <div class="section-title">Payment details</div>
        <div class="row"><span class="label">Payment number</span><span class="value">{{ $receipt->payment->payment_number }}</span></div>
        <div class="row"><span class="label">Date</span><span class="value">{{ $receipt->receipt_date?->format('d M Y') ?? '—' }}</span></div>
        <div class="row"><span class="label">Payment method</span><span class="value">{{ strtoupper(str_replace('_', ' ', $receipt->payment->payment_method)) }}</span></div>
        @if($receipt->payment->reference_number)
            <div class="row"><span class="label">Reference</span><span class="value">{{ $receipt->payment->reference_number }}</span></div>
        @endif
        @if($receipt->payment->payer_name)
            <div class="row"><span class="label">Payer</span><span class="value">{{ $receipt->payment->payer_name }}</span></div>
        @endif
        <div class="row"><span class="label">Received by</span><span class="value">{{ $receipt->payment->receivedBy->name ?? '—' }}</span></div>
    </div>

    <div class="amount">
        <span class="amount-label">AMOUNT RECEIVED</span>
        {{ $receipt->payment->currency ?? 'KES' }} {{ number_format((float) $receipt->payment->amount, 2) }}
    </div>

    @if($receipt->payment->allocations->isNotEmpty())
        <div class="section">
            <div class="section-title">Invoice allocation</div>
            @foreach($receipt->payment->allocations as $allocation)
                <div class="row">
                    <span class="label">{{ $allocation->invoice->invoice_number ?? 'Invoice' }}</span>
                    <span class="value">{{ $receipt->payment->currency ?? 'KES' }} {{ number_format((float) $allocation->amount_allocated, 2) }}</span>
                </div>
            @endforeach
        </div>
    @endif

    <div class="footer">
        This is a computer-generated receipt and does not require a physical signature.
        @if($receipt->school?->email)<br>For queries, contact {{ $receipt->school->email }}@endif
    </div>
</div>
</body>
</html>
