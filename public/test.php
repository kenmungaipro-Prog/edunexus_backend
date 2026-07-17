<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$file = dirname(__DIR__, 2) . '/frontend/app/pages/students/index.tsx';
echo "File: " . $file . "\n";
echo "Exists: " . (file_exists($file) ? "YES" : "NO") . "\n";

if (file_exists($file)) {
    $content = file_get_contents($file);
    echo "Size: " . strlen($content) . "\n";
    $lines = file($file);
    foreach ($lines as $i => $line) {
        if (strpos($line, 'initials') !== false) {
            echo "Line " . ($i + 1) . ": " . trim($line) . "\n";
        }
    }
}
