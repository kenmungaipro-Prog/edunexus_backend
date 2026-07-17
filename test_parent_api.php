<?php
// Test the parents API endpoint
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/api/v1/parents');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Authorization: Bearer test-token'
]);
$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP " . $httpcode . "\n";
if ($error) {
    echo "cURL Error: " . $error . "\n";
} else {
    echo substr($response, 0, 800) . "\n";
}
