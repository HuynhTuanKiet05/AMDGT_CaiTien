<?php

declare(strict_types=1);

$configFile = __DIR__ . '/config.local.php';
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/config.php';
}

if (!file_exists($configFile)) {
    throw new RuntimeException('Thiếu file cấu hình. Hãy tạo app/config.local.php từ app/config.example.php.');
}

$config = require $configFile;

date_default_timezone_set('Asia/Ho_Chi_Minh');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['session_name']);
    session_start();
}

function config(string $key = null, mixed $default = null): mixed
{
    global $config;

    if ($key === null) {
        return $config;
    }

    $segments = explode('.', $key);
    $value = $config;

    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        config('db.host'),
        config('db.port'),
        config('db.dbname'),
        config('db.charset')
    );

    $pdo = new PDO($dsn, config('db.username'), config('db.password'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user']);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

function require_admin(): void
{
    require_login();
    $user = current_user();
    if (($user['role'] ?? '') !== 'admin') {
        redirect('index.php');
    }
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }

    $value = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $value;
}

function load_csv_assoc(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $handle = @fopen($path, 'r');
    if ($handle === false) {
        return [];
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        return [];
    }

    $header = array_map(static fn($value) => trim((string) $value), $header);
    $rows = [];

    while (($row = fgetcsv($handle)) !== false) {
        $assoc = [];
        foreach ($header as $index => $key) {
            $assoc[$key !== '' ? $key : ('col_' . $index)] = trim((string) ($row[$index] ?? ''));
        }
        $rows[] = $assoc;
    }

    fclose($handle);
    return $rows;
}

function count_csv_lines(string $path): int
{
    if (!is_file($path)) {
        return 0;
    }

    $handle = @fopen($path, 'r');
    if ($handle === false) {
        return 0;
    }

    $rows = 0;
    while (fgets($handle) !== false) {
        $rows++;
    }

    fclose($handle);
    return $rows;
}

function count_csv_rows_robust(string $path, bool $hasHeader = true): int
{
    if (!is_file($path)) {
        return 0;
    }

    $handle = @fopen($path, 'r');
    if ($handle === false) {
        return 0;
    }

    $count = 0;
    if ($hasHeader) {
        @fgetcsv($handle); // skip header line
    }

    while (($row = @fgetcsv($handle)) !== false) {
        // Only count if it's a valid row (not completely empty)
        if ($row !== null && $row !== [null]) {
            $count++;
        }
    }

    fclose($handle);
    return $count;
}

