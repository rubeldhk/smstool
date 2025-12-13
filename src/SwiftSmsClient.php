<?php

class SwiftSmsClient
{
    private string $baseUrl;
    private string $apiKey;
    private ?string $defaultSender;

    public function __construct()
    {
        $this->baseUrl = rtrim(env('SWIFTSMS_BASE_URL', ''), '/');
        $this->apiKey = (string) env('SWIFTSMS_API_KEY', '');
        $this->defaultSender = env('SWIFTSMS_SENDER_ID', null);
    }

    public function sendSms(string $to, string $message, ?string $senderId = null): array
    {
        $sender = $senderId ?: $this->defaultSender;

        $payload = [
            'to' => $to,
            'message' => $message,
        ];

        if (!empty($sender)) {
            $payload['senderId'] = $sender;
        }

        $ch = curl_init($this->baseUrl . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30,
        ]);

        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            return [
                'success' => false,
                'status_code' => $statusCode,
                'error' => $error ?: 'Unknown cURL error',
                'response' => null,
            ];
        }

        $decoded = json_decode($responseBody, true) ?: [];

        return [
            'success' => $statusCode >= 200 && $statusCode < 300,
            'status_code' => $statusCode,
            'response' => [
                'message_id' => $decoded['messageId'] ?? null,
                'status' => $decoded['status'] ?? null,
                'error' => $decoded['error'] ?? null,
            ],
            'raw' => $decoded,
        ];
    }
}
