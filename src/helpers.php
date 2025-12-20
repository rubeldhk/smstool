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

function normalize_phone_for_country(string $phone, string $country): string
{
    $digits = preg_replace('/\D+/', '', $phone);

    if ($digits === null) {
        return '';
    }

    $country = strtoupper($country);

    if ($country === 'AU') {
        if (str_starts_with($digits, '0')) {
            $digits = '61' . substr($digits, 1);
        }
    } elseif ($country === 'NZ') {
        if (str_starts_with($digits, '0')) {
            $digits = '64' . substr($digits, 1);
        }
    } else { // Default to CA/US rules
        if (strlen($digits) === 10) {
            $digits = '1' . $digits;
        }
    }

    return $digits;
}

function validate_normalized_phone(string $phone, string $country): bool
{
    if ($phone === '' || !ctype_digit($phone)) {
        return false;
    }

    $len = strlen($phone);
    $max = 15;
    $country = strtoupper($country);

    if ($country === 'AU') {
        // Australian numbers must include the country code and be either 11 or 12 digits in total.
        // This accepts the common mobile format (614XXXXXXXX) and longer landline variants.
        return str_starts_with($phone, '61') && in_array($len, [11, 12], true);
    }

    if ($country === 'NZ') {
        return str_starts_with($phone, '64') && $len >= 10 && $len <= $max;
    }

    // Default CA/US
    return str_starts_with($phone, '1') && $len >= 11 && $len <= $max;
}

function normalize_phone(string $phone): string
{
    return normalize_phone_for_country($phone, 'CA');
}

function validate_phone(string $phone): bool
{
    return validate_normalized_phone(normalize_phone($phone), 'CA');
}

function rate_limit_sleep(int $perSecond): void
{
    if ($perSecond <= 0) {
        return;
    }
    usleep((int) floor(1_000_000 / $perSecond));
}

function render_message_template(string $template, array $data): string
{
    $replacements = [
        '{{customer_name}}' => $data['customer_name'] ?? '',
        '{{receiver_name}}' => $data['receiver_name'] ?? '',
        '{{phone}}' => $data['phone'] ?? '',
    ];

    $rendered = strtr($template, $replacements);
    // Collapse extra internal spaces while preserving newlines
    $rendered = preg_replace('/[ \t]{2,}/', ' ', $rendered ?? '');
    $rendered = preg_replace('/\s+\n/', "\n", $rendered ?? '');

    return trim((string) ($rendered ?? ''));
}
