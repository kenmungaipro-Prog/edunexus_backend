<?php
// Get all users to find a parent user
$users_response = file_get_contents('http://127.0.0.1:8000/api/v1/users');
$users_data = json_decode($users_response, true);

// Find a parent user
$parent_user = null;
if ($users_data && isset($users_data['data']['data'])) {
    foreach ($users_data['data']['data'] as $user) {
        if ($user['role'] === 'parent') {
            $parent_user = $user;
            break;
        }
    }
}

if ($parent_user) {
    echo "Found parent user: " . $parent_user['email'] . "\n";
    echo "User ID: " . $parent_user['id'] . "\n\n";
    
    // Test the parents endpoint with admin token (should return paginated list)
    echo "Testing /api/v1/parents endpoint:\n";
    $options = [
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer admin-token\r\n"
        ]
    ];
    $context = stream_context_create($options);
    $response = file_get_contents('http://127.0.0.1:8000/api/v1/parents', false, $context);
    $data = json_decode($response, true);
    
    if ($data) {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo "No data returned\n";
    }
} else {
    echo "No parent user found\n";
}
?>
