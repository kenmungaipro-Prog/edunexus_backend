<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\Request;
use App\Http\Controllers\Api\Payments\CoopBankController;

$payload = [
    'TransID' => 'RCPT-SIM-'.time(),
    'Amount' => '5000.00',
    'MSISDN' => '254700000001',
    'AccountNumber' => '400222#ADM-001',
    'BusinessShortCode' => '400222',
    'TransTime' => date('YmdHis'),
];

// Create a Symfony request wrapper so controller reads JSON as expected
$symfonyRequest = Symfony\Component\HttpFoundation\Request::create('/api/v1/payments/coop/400222/confirmation', 'POST', [], [], [], [], json_encode($payload));
$symfonyRequest->headers->set('Content-Type', 'application/json');

$request = Request::createFromBase($symfonyRequest);

$controller = $app->make(CoopBankController::class);

$response = $controller->confirmation($request);

echo "Controller Response:\n";
echo (string) $response->getContent() . "\n";

// Show created callback and transaction
$db = $app->make('db');
$receipt = $payload['TransID'];

$cb = $db->table('mpesa_callbacks')->where('mpesa_receipt_number', $receipt)->first();
echo "\nCallback record:\n";
print_r($cb);

$tx = $db->table('payment_gateway_transactions')->where('gateway_response', $receipt)->first();
echo "\nGateway transaction:\n";
print_r($tx);

$pri = $db->table('payment_reconciliation_items')->where('mpesa_receipt_number', $receipt)->first();
echo "\nReconciliation item:\n";
print_r($pri);

$payments = $db->table('payments')->where('reference_number', $receipt)->get();
echo "\nPayments linked to receipt:\n";
print_r($payments->toArray());

$receipts = $db->table('receipts')->where('reference_number', $receipt)->get();
echo "\nReceipts linked to receipt:\n";
print_r($receipts->toArray());
