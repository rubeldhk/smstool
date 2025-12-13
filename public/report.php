<?php
require_once __DIR__ . '/../bootstrap.php';
require_once BASE_PATH . '/src/Auth.php';
require_once BASE_PATH . '/src/Storage.php';

if (!Auth::check()) {
    http_response_code(403);
    exit('Unauthorized');
}

if (!hash_equals(csrf_token(), $_GET['csrf'] ?? '')) {
    http_response_code(400);
    exit('Invalid CSRF token');
}

$campaignId = (int) ($_GET['campaign_id'] ?? 0);
$storage = new Storage();
$campaigns = $storage->campaigns();
$campaign = null;
foreach ($campaigns as $item) {
    if ((int) $item['id'] === $campaignId) {
        $campaign = $item;
        break;
    }
}

if (!$campaign) {
    http_response_code(404);
    exit('Campaign not found');
}

$recipients = $storage->recipients($campaignId);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="campaign_' . $campaignId . '_report.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['phone', 'name', 'status', 'provider_message_id', 'provider_status', 'error_message', 'attempts', 'sent_at']);
foreach ($recipients as $recipient) {
    fputcsv($output, [
        $recipient['phone'],
        $recipient['name'],
        $recipient['status'],
        $recipient['provider_message_id'],
        $recipient['provider_status'],
        $recipient['error_message'],
        $recipient['attempts'],
        $recipient['sent_at'],
    ]);
}
fclose($output);
exit;
