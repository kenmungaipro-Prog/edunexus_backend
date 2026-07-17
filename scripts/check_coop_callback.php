<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$db = $app->make('db');

echo "Latest mpesa_callbacks matching RCPT123 or coop_400222:\n";
$cb = $db->table('mpesa_callbacks')
    ->where('mpesa_receipt_number', 'RCPT123')
    ->orWhere('gateway_name', 'coop_400222')
    ->orderBy('id', 'desc')
    ->limit(5)
    ->get();
print_r($cb->toArray());

echo "\nLatest payment_gateway_transactions for coop_400222:\n";
$tx = $db->table('payment_gateway_transactions')
    ->where('gateway_name', 'coop_400222')
    ->orderBy('id', 'desc')
    ->limit(5)
    ->get();
print_r($tx->toArray());
