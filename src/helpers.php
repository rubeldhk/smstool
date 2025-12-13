<?php

function load_env(string $path): array
{
    $vars = [];
    if (!file_exists($path)) {
        return $vars;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $vars[$key] = $value;
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
        }
    }
    return $vars;
}

function env(string $key, ?string $default = null): ?string
{
    return $_ENV[$key] ?? $default;
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(string $token): void
{
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }
}

function json_read(string $path, array $default = []): array
{
    if (!file_exists($path)) {
        return $default;
    }
    $content = file_get_contents($path);
    $data = json_decode($content, true);
    return is_array($data) ? $data : $default;
}

function json_write(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
}

function next_id(array $items): int
{
    $max = 0;
    foreach ($items as $item) {
        if (($item['id'] ?? 0) > $max) {
            $max = (int) $item['id'];
        }
    }
    return $max + 1;
}

function validate_phone(string $phone): bool
{
    return (bool) preg_match('/^\+?[1-9]\d{7,14}$/', $phone);
}

function normalize_phone(string $phone): string
{
    $digits = preg_replace('/[^\d+]/', '', $phone);

    if ($digits === null) {
        return $phone;
    }

    if ($digits !== '' && $digits[0] !== '+') {
        $digits = '+' . $digits;
    }

    return $digits;
}

function rate_limit_sleep(int $perSecond): void
{
    if ($perSecond <= 0) {
        return;
    }
    usleep((int) floor(1_000_000 / $perSecond));
}
