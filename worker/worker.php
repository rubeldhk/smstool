<?php
require_once __DIR__ . '/../bootstrap.php';
require_once BASE_PATH . '/src/Storage.php';
require_once BASE_PATH . '/src/SwiftSmsClient.php';

if (php_sapi_name() !== 'cli') {
    exit("Worker must be run from CLI\n");
}

$storage = new Storage();
$client = new SwiftSmsClient();
$rateLimit = (int) (env('SMS_RATE_LIMIT_PER_SEC', '10'));
$maxAttempts = (int) (env('SMS_MAX_ATTEMPTS', '2'));

function calculate_counts_cli(array $recipients, int $invalid): array
{
    $counts = [
        'total' => count($recipients),
        'valid' => count($recipients),
        'invalid' => $invalid,
        'sent' => 0,
        'failed' => 0,
        'pending' => 0,
    ];

    foreach ($recipients as $recipient) {
        if ($recipient['status'] === 'sent') {
            $counts['sent']++;
        } elseif ($recipient['status'] === 'failed') {
            $counts['failed']++;
        } else {
            $counts['pending']++;
        }
    }

    return $counts;
}

$campaigns = $storage->campaigns();
foreach ($campaigns as $campaign) {
    if (!in_array($campaign['status'], ['queued', 'running'], true)) {
        continue;
    }

    $campaignId = (int) $campaign['id'];
    echo "Processing campaign {$campaignId}\n";
    $storage->updateCampaignStatus($campaignId, 'running');
    $recipients = $storage->recipients($campaignId);

    foreach ($recipients as $index => $recipient) {
        // Reload status to honor stop commands
        $freshCampaigns = $storage->campaigns();
        foreach ($freshCampaigns as $fresh) {
            if ((int) $fresh['id'] === $campaignId && $fresh['status'] === 'stopped') {
                echo "Campaign {$campaignId} stopped.\n";
                break 2;
            }
        }

        if ($recipient['status'] === 'sent') {
            continue;
        }

        if ($recipient['status'] === 'failed' && $recipient['attempts'] >= $maxAttempts) {
            continue;
        }

        $response = $client->sendSms($recipient['phone'], $campaign['message'], $campaign['sender_id'] ?? null);
        $recipient['attempts'] = ($recipient['attempts'] ?? 0) + 1;

        if ($response['success']) {
            $recipient['status'] = 'sent';
            $recipient['provider_message_id'] = $response['response']['message_id'] ?? null;
            $recipient['provider_status'] = $response['response']['status'] ?? 'sent';
            $recipient['error_message'] = null;
            $recipient['sent_at'] = date('c');
        } else {
            $recipient['status'] = $recipient['attempts'] >= $maxAttempts ? 'failed' : 'pending';
            $recipient['provider_status'] = $response['response']['status'] ?? 'error';
            $recipient['error_message'] = $response['response']['error'] ?? $response['error'] ?? 'Send failed';
        }

        $recipients[$index] = $recipient;
        $storage->saveRecipients($campaignId, $recipients);
        rate_limit_sleep($rateLimit);
    }

    $counts = calculate_counts_cli($recipients, $campaign['counts']['invalid'] ?? 0);
    $newStatus = $counts['pending'] === 0 ? 'completed' : 'running';
    $storage->setCampaign($campaignId, ['status' => $newStatus, 'counts' => $counts]);
    echo "Campaign {$campaignId} status: {$newStatus}\n";
}
