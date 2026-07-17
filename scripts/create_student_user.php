#!/usr/bin/env php
<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
$envPath = $basePath . '/.env';

if (! file_exists($envPath)) {
    fwrite(STDERR, "Error: .env file not found.\n");
    exit(1);
}

$env = parseEnv(file_get_contents($envPath));
$driver = $env['DB_CONNECTION'] ?? 'mysql';
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$database = $env['DB_DATABASE'] ?? '';
$username = $env['DB_USERNAME'] ?? 'root';
$password = $env['DB_PASSWORD'] ?? '';
$charset = $env['DB_CHARSET'] ?? 'utf8mb4';

if (empty($database)) {
    fwrite(STDERR, "Error: DB_DATABASE is not set.\n");
    exit(1);
}

$dsn = match (strtolower($driver)) {
    'mysql' => "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
    'sqlite' => "sqlite:{$basePath}/{$database}",
    default => throw new RuntimeException("Unsupported DB driver: {$driver}"),
};

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Database connection failed: {$e->getMessage()}\n");
    exit(1);
}

// Check for existing student users
$existing = $pdo->prepare('SELECT id, name, email, school_id FROM users WHERE role = ? LIMIT 1');
$existing->execute(['student']);
$studentUser = $existing->fetch();

if ($studentUser) {
    echo "A student user already exists: {$studentUser['email']} (id={$studentUser['id']}).\n";
    exit(0);
}

// Find a student record to create credentials for
$studentStmt = $pdo->query('SELECT id, school_id, admission_no, first_name, last_name, status FROM students WHERE status = "active" ORDER BY id LIMIT 1');
$student = $studentStmt->fetch();

if (! $student) {
    fwrite(STDERR, "No active student record found.\n");
    exit(1);
}

$email = sprintf('student.%s@edunexus.local', strtolower(preg_replace('/[^a-z0-9]+/', '', $student['admission_no'] ?? '')));
$password = substr(bin2hex(random_bytes(6)), 0, 12);
$hashed = password_hash($password, PASSWORD_BCRYPT);

$insert = $pdo->prepare('INSERT INTO users (school_id, name, email, password, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())');
$insert->execute([
    $student['school_id'],
    trim($student['first_name'] . ' ' . $student['last_name']),
    $email,
    $hashed,
    'student',
    'active',
]);

$newId = $pdo->lastInsertId();

echo "Created student login credentials:\n";
echo "id: {$newId}\n";
echo "name: " . trim($student['first_name'] . ' ' . $student['last_name']) . "\n";
echo "email: {$email}\n";
echo "password: {$password}\n";

echo "You can change the email if desired.\n";

function parseEnv(string $contents): array
{
    $lines = preg_split('/\r\n|\n|\r/', $contents);
    $result = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $result[trim($key)] = trim($value);
    }
    return $result;
}
