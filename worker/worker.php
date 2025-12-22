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
$defaultCountry = 'CA';

function sanitize_phone_for_sending(string $phone, string $country): string
{
    if (strtoupper($country) === 'AU') {
        return ltrim($phone, '+');
    }

    return $phone;
}

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
    $country = strtoupper($campaign['country'] ?? $defaultCountry);
    $accountKey = $client->getAccountKeyForCountry($country);
    if ($accountKey === '') {
        echo "Campaign {$campaignId} missing account key for country {$country}, skipping.\n";
        $storage->updateCampaignStatus($campaignId, 'failed');
        continue;
    }

    $template = $campaign['message_template'] ?? $campaign['message'] ?? '';
    $campaignReference = $campaign['reference'] ?? ('campaign-' . $campaignId);
    if ($template === '') {
        echo "Campaign {$campaignId} has no message template, skipping.\n";
        $storage->updateCampaignStatus($campaignId, 'failed');
        continue;
    }

    echo "Processing campaign {$campaignId}\n";
    $storage->updateCampaignStatus($campaignId, 'running');
    $recipients = $storage->recipients($campaignId);

    $campaignStopped = false;
    foreach ($recipients as $index => $recipient) {
        // Reload status to honor stop commands
        $freshCampaigns = $storage->campaigns();
        foreach ($freshCampaigns as $fresh) {
            if ((int) $fresh['id'] === $campaignId && $fresh['status'] === 'stopped') {
                $stopResponse = $client->stopBulk($accountKey, $campaignReference);
                $stopCode = $stopResponse['http_code'] ?? 0;
                $stopBody = $stopResponse['response'] ?? '';
                echo "Stop requested for campaign {$campaignId} (HTTP {$stopCode}): {$stopBody}\n";
                echo "Campaign {$campaignId} stopped.\n";
                $campaignStopped = true;
                break 2;
            }
        }

        if ($recipient['status'] === 'sent') {
            continue;
        }

        if (empty($recipient['country'])) {
            $recipient['country'] = $country;
        }

        if ($recipient['status'] === 'failed' && $recipient['attempts'] >= $maxAttempts) {
            continue;
        }

        $rendered = render_message_template($template, [
            'customer_name' => $recipient['customer_name'] ?? '',
            'receiver_name' => $recipient['receiver_name'] ?? '',
            'phone' => $recipient['phone'] ?? '',
        ]);

        $recipient['rendered_message'] = $rendered;

        if (strlen($rendered) > 480) {
            $recipient['status'] = 'failed';
            $recipient['provider_status'] = 'error';
            $recipient['error_message'] = 'Message exceeds 480 character limit after rendering.';
            $recipient['last_error'] = $recipient['error_message'];
            $recipient['http_code'] = null;
            $recipients[$index] = $recipient;
            $storage->saveRecipients($campaignId, $recipients);
            continue;
        }

        $sendPhone = sanitize_phone_for_sending($recipient['phone'], $recipient['country'] ?? $country);
        $response = $client->sendBulk($accountKey, [$sendPhone], $rendered, $campaignReference, $campaign['sender_id'] ?? null);
        $recipient['attempts'] = ($recipient['attempts'] ?? 0) + 1;
        $recipient['http_code'] = $response['http_code'] ?? null;
        $recipient['provider_response'] = $response['response'] ?? null;

        $success = ($response['http_code'] ?? 0) === 200;
        $responseBody = $response['response'] ?? null;

        if ($success) {
            $recipient['status'] = 'sent';
            $recipient['provider_message_id'] = is_array($responseBody) ? ($responseBody['message_id'] ?? null) : null;
            $recipient['provider_status'] = is_array($responseBody)
                ? ($responseBody['status'] ?? 'sent')
                : 'sent';
            $recipient['error_message'] = null;
            $recipient['last_error'] = null;
            $recipient['sent_at'] = date('c');
        } else {
            $recipient['status'] = $recipient['attempts'] >= $maxAttempts ? 'failed' : 'pending';
            $recipient['provider_status'] = is_array($responseBody)
                ? ($responseBody['status'] ?? 'error')
                : 'error';
            $recipient['error_message'] = is_array($responseBody)
                ? ($responseBody['error'] ?? $response['error'] ?? 'Send failed')
                : ($responseBody ?? $response['error'] ?? 'Send failed');
            $recipient['last_error'] = $recipient['error_message'];
        }

        $recipients[$index] = $recipient;
        $storage->saveRecipients($campaignId, $recipients);
        rate_limit_sleep($rateLimit);
    }

    $counts = calculate_counts_cli($recipients, $campaign['counts']['invalid'] ?? 0);

    if ($campaignStopped) {
        $storage->setCampaign($campaignId, ['status' => 'stopped', 'counts' => $counts]);
        echo "Campaign {$campaignId} status: stopped\n";
        continue;
    }

    $newStatus = $counts['pending'] === 0 ? 'completed' : 'running';
    $storage->setCampaign($campaignId, ['status' => $newStatus, 'counts' => $counts]);
    echo "Campaign {$campaignId} status: {$newStatus}\n";
}
