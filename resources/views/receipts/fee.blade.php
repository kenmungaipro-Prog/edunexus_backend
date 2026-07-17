{{-- resources/views/receipts/fee.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #1a1a2e; background: #fff; }
    .receipt { max-width: 600px; margin: 0 auto; padding: 32px; }
    .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #4f8ef7; padding-bottom: 16px; margin-bottom: 20px; }
    .school-name { font-size: 20px; font-weight: 700; color: #4f8ef7; }
    .school-meta { font-size: 10px; color: #666; margin-top: 4px; line-height: 1.5; }
    .receipt-title { font-size: 16px; font-weight: 700; text-align: right; color: #333; }
    .receipt-no { font-size: 11px; color: #666; text-align: right; font-family: monospace; }
    .section { background: #f8f9ff; border-radius: 8px; padding: 14px 16px; margin-bottom: 14px; }
    .section-title { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #4f8ef7; margin-bottom: 8px; }
    .row { display: flex; justify-content: space-between; margin-bottom: 4px; }
    .label { color: #666; }
    .value { font-weight: 600; color: #1a1a2e; }
    .amount-box { background: linear-gradient(135deg, #4f8ef7, #6366f1); color: #fff; border-radius: 8px; padding: 16px; text-align: center; margin: 16px 0; }
    .amount-label { font-size: 11px; opacity: 0.85; margin-bottom: 4px; }
    .amount-value { font-size: 28px; font-weight: 700; }
    .status-badge { display: inline-block; background: #10b981; color: #fff; font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 20px; }
    .footer { border-top: 1px solid #eee; padding-top: 12px; margin-top: 16px; font-size: 10px; color: #999; text-align: center; }
    .signature { margin-top: 30px; display: flex; justify-content: space-between; font-size: 10px; color: #666; }
  </style>
</head>
<body>
<div class="receipt">
  <div class="header">
    <div>
      <div class="school-name">{{ $fee->student->school->name ?? 'EduNexus School' }}</div>
      <div class="school-meta">
        {{ $fee->student->school->address ?? '' }}<br>
        Tel: {{ $fee->student->school->phone ?? '' }} | {{ $fee->student->school->email ?? '' }}
      </div>
    </div>
    <div>
      <div class="receipt-title">FEE RECEIPT</div>
      <div class="receipt-no">{{ $fee->receipt_no }}</div>
      <div class="receipt-no" style="margin-top:4px">{{ $fee->paid_at?->format('d M Y, h:i A') }}</div>
    </div>
  </div>

  <div class="section">
    <div class="section-title">Student Information</div>
    <div class="row"><span class="label">Name</span><span class="value">{{ $fee->student->full_name }}</span></div>
    <div class="row"><span class="label">Admission No</span><span class="value">{{ $fee->student->admission_no }}</span></div>
    <div class="row"><span class="label">Class</span><span class="value">{{ $fee->student->classRoom->name }}</span></div>
    <div class="row"><span class="label">Roll Number</span><span class="value">{{ $fee->student->roll_number }}</span></div>
  </div>

  <div class="section">
    <div class="section-title">Payment Details</div>
    <div class="row"><span class="label">Fee Type</span><span class="value">{{ $fee->feeType->name }}</span></div>
    <div class="row"><span class="label">Academic Year</span><span class="value">{{ $fee->session->name }}</span></div>
    <div class="row"><span class="label">Payment Method</span><span class="value" style="text-transform:uppercase">{{ $fee->payment_method }}</span></div>
    @if($fee->transaction_id)
    <div class="row"><span class="label">Transaction ID</span><span class="value" style="font-family:monospace">{{ $fee->transaction_id }}</span></div>
    @endif
    <div class="row"><span class="label">Received By</span><span class="value">{{ $fee->collectedBy->name }}</span></div>
    <div class="row" style="margin-top:8px"><span class="label">Status</span><span><span class="status-badge">✓ PAID</span></span></div>
  </div>

  <div class="amount-box">
    <div class="amount-label">Amount Paid</div>
    <div class="amount-value">{{ formatCurrency($fee->amount) }}</div>
  </div>

  @if($fee->remarks)
  <div class="section">
    <div class="section-title">Remarks</div>
    <div>{{ $fee->remarks }}</div>
  </div>
  @endif

  <div class="signature">
    <div>___________________________<br>Student / Parent Signature</div>
    <div style="text-align:right">___________________________<br>Authorized Signatory</div>
  </div>

  <div class="footer">
    This is a computer-generated receipt and does not require a physical signature.<br>
    For queries, contact: {{ $fee->student->school->email ?? 'admin@edunexus.local' }}
  </div>
</div>
</body>
</html>
