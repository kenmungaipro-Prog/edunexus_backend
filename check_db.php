<?php
// Direct database connection to test
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=edunexus_full',
        'root',
        ''
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if parent_profiles table exists
    $result = $pdo->query("SELECT COUNT(*) as cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'edunexus_full' AND TABLE_NAME = 'parent_profiles'");
    $table_exists = $result->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;
    
    echo "parent_profiles table exists: " . ($table_exists ? "YES" : "NO") . "\n";
    
    if ($table_exists) {
        // Count parents
        $result = $pdo->query("SELECT COUNT(*) as cnt FROM parent_profiles");
        $count = $result->fetch(PDO::FETCH_ASSOC)['cnt'];
        echo "Number of parent profiles: " . $count . "\n\n";
        
        // Get a sample parent profile with user info
        $result = $pdo->query("
            SELECT pp.id, pp.user_id, u.name, u.email, u.role 
            FROM parent_profiles pp 
            JOIN users u ON pp.user_id = u.id 
            LIMIT 3
        ");
        
        echo "Sample parent users:\n";
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            echo "  ID: {$row['id']}, User ID: {$row['user_id']}, Name: {$row['name']}, Email: {$row['email']}, Role: {$row['role']}\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
