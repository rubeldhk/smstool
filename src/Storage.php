<?php

class Storage
{
    private string $campaignsFile;

    public function __construct()
    {
        $this->campaignsFile = STORAGE_PATH . '/campaigns.json';
    }

    public function campaigns(): array
    {
        $campaigns = json_read($this->campaignsFile, []);

        usort($campaigns, static function (array $a, array $b): int {
            $createdA = $a['created_at'] ?? '';
            $createdB = $b['created_at'] ?? '';

            if ($createdA !== '' && $createdB !== '') {
                $comparison = strcmp($createdB, $createdA);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return ($b['id'] ?? 0) <=> ($a['id'] ?? 0);
        });

        return $campaigns;
    }

    public function saveCampaigns(array $campaigns): void
    {
        json_write($this->campaignsFile, $campaigns);
    }

    public function createCampaign(string $name, string $messageTemplate, ?string $senderId, string $country, array $recipients, int $invalidCount, array $previewMessages = []): array
    {
        $campaigns = $this->campaigns();
        $campaignId = next_id($campaigns);
        $timestamp = date('c');

        $campaignDir = $this->campaignDir($campaignId);
        if (!file_exists($campaignDir)) {
            mkdir($campaignDir, 0775, true);
        }

        $recipientRows = [];
        $recipientLines = [];
        foreach ($recipients as $recipient) {
            $customerName = $recipient['customer_name'] ?? '';
            $receiverName = $recipient['receiver_name'] ?? '';
            $recipientRows[] = [
                'phone' => $recipient['phone'],
                'customer_name' => $customerName,
                'receiver_name' => $receiverName,
                'country' => $country,
                'rendered_message' => $recipient['rendered_message'] ?? null,
                'name' => $receiverName !== '' ? $receiverName : $customerName, // backward compatible field
                'status' => 'pending',
                'provider_message_id' => null,
                'provider_status' => null,
                'error_message' => null,
                'last_error' => null,
                'provider_response' => null,
                'http_code' => null,
                'attempts' => 0,
                'sent_at' => null,
            ];
            $recipientLines[] = implode(',', [
                $recipient['phone'],
                $customerName,
                $receiverName,
                $country,
            ]);
        }

        file_put_contents($campaignDir . '/recipients.txt', implode("\n", $recipientLines));
        json_write($campaignDir . '/recipients.json', $recipientRows);

        $campaign = [
            'id' => $campaignId,
            'name' => $name,
            'message' => $messageTemplate,
            'message_template' => $messageTemplate,
            'country' => $country,
            'sender_id' => $senderId,
            'status' => 'draft',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'preview_messages' => array_values(array_slice($previewMessages, 0, 5)),
            'counts' => [
                'total' => count($recipients),
                'valid' => count($recipients),
                'invalid' => $invalidCount,
                'sent' => 0,
                'failed' => 0,
                'pending' => count($recipients),
            ],
        ];

        $campaigns[] = $campaign;
        $this->saveCampaigns($campaigns);

        return $campaign;
    }

    public function campaignDir(int $campaignId): string
    {
        return STORAGE_PATH . '/campaign_' . $campaignId;
    }

    public function recipients(int $campaignId): array
    {
        return json_read($this->campaignDir($campaignId) . '/recipients.json', []);
    }

    public function saveRecipients(int $campaignId, array $recipients): void
    {
        json_write($this->campaignDir($campaignId) . '/recipients.json', $recipients);
    }

    public function updateCampaignStatus(int $campaignId, string $status, ?array $counts = null): void
    {
        $campaigns = $this->campaigns();
        foreach ($campaigns as &$campaign) {
            if ((int) $campaign['id'] === $campaignId) {
                $campaign['status'] = $status;
                if ($counts !== null) {
                    $campaign['counts'] = array_merge($campaign['counts'], $counts);
                }
                $campaign['updated_at'] = date('c');
            }
        }
        $this->saveCampaigns($campaigns);
    }

    public function setCampaign(int $campaignId, array $data): void
    {
        $campaigns = $this->campaigns();
        foreach ($campaigns as &$campaign) {
            if ((int) $campaign['id'] === $campaignId) {
                $campaign = array_merge($campaign, $data);
                $campaign['updated_at'] = date('c');
            }
        }
        $this->saveCampaigns($campaigns);
    }
}
