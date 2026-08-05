<?php
header('Content-Type: text/plain');

$dirPath = __DIR__ . '/../app';
$dir = new RecursiveDirectoryIterator($dirPath);
$iterator = new RecursiveIteratorIterator($dir);
$regex = new RegexIterator($iterator, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);

$found = false;
foreach ($regex as $file => $value) {
    $content = file_get_contents($file);
    if (strpos($content, 'FinanceStatuses') !== false) {
        echo "Found word in: " . realpath($file) . "\n";
        $found = true;
    }
}
if (!$found) {
    echo "FinanceStatuses not found anywhere in app/.\n";
}
echo "Search completed.\n";
