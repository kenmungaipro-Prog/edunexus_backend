<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$student = App\Models\Student::find(4);
if (!$student) {
    echo "student-not-found\n";
    exit;
}

echo "student={$student->id} school={$student->school_id}\n";
$invoices = App\Models\Invoice::where('student_id', 4)->get(['id','invoice_number','total','amount_paid','balance','status','due_date']);
foreach ($invoices as $i) {
    echo $i->invoice_number . ' total=' . $i->total . ' paid=' . $i->amount_paid . ' bal=' . $i->balance . ' status=' . $i->status . ' due=' . $i->due_date . PHP_EOL;
}
$payments = App\Models\Payment::where('student_id', 4)->get(['id','payment_number','amount','status']);
foreach ($payments as $p) {
    echo 'payment ' . $p->payment_number . ' amt=' . $p->amount . ' status=' . $p->status . PHP_EOL;
}
$bal = App\Models\StudentFinanceBalance::where('student_id', 4)->first();
echo 'balance_record=' . json_encode($bal?->toArray()) . PHP_EOL;
