<?php
// Test login and parents API with correct password
try {
    $ch = curl_init();
    
    echo "Step 1: Logging in as admin with email: admin@greenwood.edu.in\n";
    curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:8000/api/v1/auth/login');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    
    $login_data = json_encode([
        'email' => 'admin@greenwood.edu.in',
        'password' => 'password'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $login_data);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    echo "Response code: $http_code\n";
    
    if ($http_code === 200) {
        $auth_data = json_decode($response, true);
        if (isset($auth_data['data']['token'])) {
            $token = $auth_data['data']['token'];
            echo "✓ Login successful!\n";
            echo "Token: " . substr($token, 0, 30) . "...\n\n";
            
            // Now test parents endpoint
            echo "Step 2: Testing /api/v1/parents endpoint...\n";
            curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:8000/api/v1/parents?page=1&per_page=10');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token
            ]);
            curl_setopt($ch, CURLOPT_POST, false);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            echo "Response code: $http_code\n";
            $data = json_decode($response, true);
            
            if ($http_code === 200 && $data['success']) {
                echo "✓ API returned expected structure!\n";
                echo "Response structure:\n";
                echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            } else {
                echo "✗ API returned error\n";
                echo $response . "\n";
            }
        } else {
            echo "✗ No token in response\n";
            echo $response . "\n";
        }
    } else {
        echo "✗ Login failed\n";
        echo $response . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
