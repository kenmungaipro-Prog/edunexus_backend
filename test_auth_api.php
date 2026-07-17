<?php
// Test login and parents API
try {
    $ch = curl_init();
    
    // First, login as admin to get a token
    echo "Step 1: Logging in as admin...\n";
    curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:8000/api/v1/auth/login');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    
    $login_data = json_encode([
        'email' => 'admin@greenwood.edu.in',
        'password' => 'password123'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $login_data);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    echo "Response code: $http_code\n";
    
    if ($http_code === 200) {
        $auth_data = json_decode($response, true);
        if (isset($auth_data['data']['token'])) {
            $token = $auth_data['data']['token'];
            echo "Token obtained: " . substr($token, 0, 20) . "...\n\n";
            
            // Now test parents endpoint
            echo "Step 2: Testing /api/v1/parents endpoint...\n";
            curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:8000/api/v1/parents');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ]);
            curl_setopt($ch, CURLOPT_POST, false);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            echo "Response code: $http_code\n";
            echo "Response:\n";
            echo json_encode(json_decode($response, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            echo "Error: No token in response\n";
            echo $response . "\n";
        }
    } else {
        echo "Login failed\n";
        echo $response . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
