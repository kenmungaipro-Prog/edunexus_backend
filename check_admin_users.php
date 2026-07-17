<?php
// Check database for admin users
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=edunexus_full',
        'root',
        ''
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get admin users
    $result = $pdo->query("SELECT id, name, email, role FROM users WHERE role = 'admin' LIMIT 5");
    
    echo "Admin users:\n";
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        echo "  ID: {$row['id']}, Name: {$row['name']}, Email: {$row['email']}\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
