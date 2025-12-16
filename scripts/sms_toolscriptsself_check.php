@@ -0,0 +1,94 @@
<?php
require_once __DIR__ . '/../bootstrap.php';
require_once BASE_PATH . '/src/Storage.php';
require_once BASE_PATH . '/src/SwiftSmsClient.php';

if (php_sapi_name() !== 'cli') {
    exit("This utility must be run from the CLI\n");
}

$withPing = in_array('--ping-api', $argv ?? [], true);

$checks = [];
$exitCode = 0;

function add_check(array &$list, string $label, bool $ok, string $details = ''): void
{
    $list[] = [$label, $ok, $details];
}

add_check($checks, 'PHP version >= 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION);

$requiredExtensions = ['curl', 'json'];
foreach ($requiredExtensions as $ext) {
    add_check($checks, "Extension: {$ext}", extension_loaded($ext));
}

add_check($checks, '.env present', file_exists(BASE_PATH . '/.env'), 'Copy .env.example to .env');

$requiredEnv = [
    'APP_USERNAME',
    'APP_PASSWORD',
    'SWIFTSMS_BASE_URL',
    'SWIFTSMS_ACCOUNT_KEY_CA',
    'SWIFTSMS_ACCOUNT_KEY_AU',
    'SWIFTSMS_ACCOUNT_KEY_NZ',
];
foreach ($requiredEnv as $envKey) {
    $value = env($envKey);
    add_check(
        $checks,
        "Env set: {$envKey}",
        !empty($value),
        empty($value) ? 'Set this in .env' : ''
    );
}

$storageWritable = is_dir(STORAGE_PATH) && is_writable(STORAGE_PATH);
add_check($checks, 'Storage directory writable', $storageWritable, STORAGE_PATH);

$tempFile = STORAGE_PATH . '/.healthcheck.tmp';
$writeResult = @file_put_contents($tempFile, 'ok');
if ($writeResult === false) {
    $storageWritable = false;
}
@unlink($tempFile);
if ($writeResult !== false) {
    add_check($checks, 'Storage write test', true, 'Created and removed a temp file');
} else {
    add_check($checks, 'Storage write test', false, 'Failed to write temp file');
}

if ($withPing) {
    $client = new SwiftSmsClient();
    $base = rtrim(env('SWIFTSMS_BASE_URL', ''), '/');
    if ($base === '') {
        add_check($checks, 'API base reachable', false, 'SWIFTSMS_BASE_URL is empty');
    } else {
        $ch = curl_init($base);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $responseBody = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ok = $responseBody !== false && $httpCode > 0;
        $detail = $curlErr ?: "HTTP {$httpCode}";
        add_check($checks, 'API base reachable (--ping-api)', $ok, $detail);
    }
} else {
    add_check($checks, 'API base reachable (--ping-api)', true, 'Skipped (run with --ping-api to verify connectivity)');
}

foreach ($checks as [$label, $ok, $details]) {
    $status = $ok ? '[OK]' : '[FAIL]';
    echo sprintf("%s %s%s\n", $status, $label, $details ? " — {$details}" : '');
    if (!$ok) {
        $exitCode = 1;
    }
}

if ($exitCode === 0) {
    echo "\nEnvironment looks good. Start the web UI and worker to run a live test.\n";
} else {
    echo "\nFix the failed checks above before running the app.\n";
}

exit($exitCode);
