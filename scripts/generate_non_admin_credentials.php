#!/usr/bin/env php
<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
$envPath = $basePath . '/.env';

if (! file_exists($envPath)) {
    fwrite(STDERR, "Error: .env file not found at {$envPath}.\n");
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
    fwrite(STDERR, "Error: DB_DATABASE is not set in .env.\n");
    exit(1);
}

$dsn = match (strtolower($driver)) {
    'mysql' => "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
    'sqlite' => "sqlite:{$basePath}/{$database}",
    default => throw new RuntimeException("Unsupported DB_CONNECTION driver: {$driver}"),
};

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Error: Could not connect to the database. {$e->getMessage()}\n");
    exit(1);
}

$excludedRoles = ['admin', 'superadmin'];
$placeholders = implode(',', array_fill(0, count($excludedRoles), '?'));
$sql = "SELECT id, name, email, role FROM users WHERE role NOT IN ({$placeholders})";
$stmt = $pdo->prepare($sql);
$stmt->execute($excludedRoles);
$users = $stmt->fetchAll();

if (! $users) {
    echo "No non-admin users found.\n";
    exit(0);
}

$outputPath = $basePath . '/storage/app/non-admin-user-credentials-' . date('YmdHis') . '.csv';
$fp = fopen($outputPath, 'w');
if (! $fp) {
    fwrite(STDERR, "Error: Could not write to {$outputPath}.\n");
    exit(1);
}

fputcsv($fp, ['id', 'name', 'email', 'role', 'password'], ',', '"', "\\");

foreach ($users as $user) {
    $plainPassword = generatePassword();
    $hashedPassword = password_hash($plainPassword, PASSWORD_BCRYPT);

    $update = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
    $update->execute([$hashedPassword, $user['id']]);

    fputcsv($fp, [$user['id'], $user['name'], $user['email'], $user['role'], $plainPassword], ',', '"', "\\");
    echo "Updated {$user['email']} ({$user['role']}).\n";
}

fclose($fp);

echo "\nGenerated credentials for " . count($users) . " users.\n";
echo "Saved credentials to: {$outputPath}\n";

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
        $key = trim($key);
        $value = trim($value);

        if ($value === '') {
            $result[$key] = '';
            continue;
        }

        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }

        $result[$key] = $value;
    }

    return $result;
}

function generatePassword(): string
{
    return substr(bin2hex(random_bytes(6)), 0, 12);
}
