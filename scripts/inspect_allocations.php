<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$allocations = App\Models\PaymentAllocation::where('payment_id', 1)->get(['invoice_id','amount_allocated']);
foreach ($allocations as $allocation) {
    echo $allocation->invoice_id . ':' . $allocation->amount_allocated . PHP_EOL;
}
