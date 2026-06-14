<?php
$envFile = __DIR__ . '/../.env';

if (!file_exists($envFile)) {
    die('Configuration error: .env file not found. Please copy .env.example to .env and configure your database credentials.');
}

$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
        list($k, $v) = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

// Validate required database configuration
$required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];
$missing = [];
foreach ($required as $key) {
    if (empty($_ENV[$key])) {
        $missing[] = $key;
    }
}

if (!empty($missing)) {
    die('Configuration error: Missing required environment variables: ' . implode(', ', $missing) . '. Please check your .env file.');
}

// Prevent root user usage in production
if ($_ENV['DB_USER'] === 'root') {
    die('Security error: Using root database user is not allowed. Please create a dedicated database user. See docs/INSTALLATION.md for instructions.');
}

return [
  'db' => [
    'host' => $_ENV['DB_HOST'],
    'name' => $_ENV['DB_NAME'],
    'user' => $_ENV['DB_USER'],
    'pass' => $_ENV['DB_PASS'],
    'charset' => 'utf8mb4'
  ]
];