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
        return json_read($this->campaignsFile, []);
    }

    public function saveCampaigns(array $campaigns): void
    {
        json_write($this->campaignsFile, $campaigns);
    }

    public function createCampaign(string $name, string $message, ?string $senderId, array $recipients, int $invalidCount): array
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
            $recipientRows[] = [
                'phone' => $recipient['phone'],
                'name' => $recipient['name'],
                'status' => 'pending',
                'provider_message_id' => null,
                'provider_status' => null,
                'error_message' => null,
                'attempts' => 0,
                'sent_at' => null,
            ];
            $recipientLines[] = $recipient['phone'] . (!empty($recipient['name']) ? ',' . $recipient['name'] : '');
        }

        file_put_contents($campaignDir . '/recipients.txt', implode("\n", $recipientLines));
        json_write($campaignDir . '/recipients.json', $recipientRows);

        $campaign = [
            'id' => $campaignId,
            'name' => $name,
            'message' => $message,
            'sender_id' => $senderId,
            'status' => 'draft',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
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
