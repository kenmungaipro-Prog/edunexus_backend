<?php
// Test login with proper headers
try {
    $ch = curl_init();
    
    echo "Testing /api/v1/auth/login endpoint...\n";
    curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:8000/api/v1/auth/login');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: PHP-Test'
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    
    $login_data = json_encode([
        'email' => 'admin@greenwood.edu.in',
        'password' => 'password123'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $login_data);
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    
    echo "Response code: $http_code\n";
    echo "Content-Type: $content_type\n";
    echo "URL: $url\n\n";
    echo "Response (first 1000 chars):\n";
    echo substr($response, 0, 1000) . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
