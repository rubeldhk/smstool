<?php

class SwiftSmsClient
{
    private string $baseUrl;
    private string $defaultAccountKey;
    private ?string $defaultSender;

    public function __construct()
    {
        $this->baseUrl = rtrim(env('SWIFTSMS_BASE_URL', ''), '/');
        $this->defaultAccountKey = (string) env('SWIFTSMS_ACCOUNT_KEY', '');
        $this->defaultSender = env('SWIFTSMS_SENDER_ID', null);
    }

    public function sendSms(string $to, string $message, ?string $accountKey = null, ?string $senderId = null): array
    {
        $reference = uniqid('msg_', true);

        $response = $this->sendBulk($accountKey ?? $this->defaultAccountKey, [$to], $message, $reference, $senderId);

        return [
            'success' => $response['http_code'] === 200,
            'status_code' => $response['http_code'],
            'response' => [
                'message_id' => null,
                'status' => $response['http_code'] === 200 ? 'sent' : 'error',
                'error' => $response['http_code'] === 200 ? null : $response['response'],
            ],
            'raw' => $response,
        ];
    }

    public function sendBulk(string $accountKey, array $cellNumbers, string $messageBody, string $reference, ?string $senderId = null): array
    {
        $sender = $senderId ?: $this->defaultSender;

        $payload = [
            'MessageBody' => $messageBody,
            'Reference' => $reference,
            'CellNumbers' => array_values($cellNumbers),
        ];

        if (!empty($sender)) {
            $payload['SenderID'] = $sender;
        }

        $ch = curl_init($this->buildBulkUrl($accountKey));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json;charset=UTF-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30,
        ]);

        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            return [
                'http_code' => $statusCode ?: 0,
                'response' => "SwiftSMS CURL error: {$error}",
            ];
        }

        return [
            'http_code' => $statusCode,
            'response' => is_string($responseBody) ? trim($responseBody) : '',
        ];
    }

    private function buildBulkUrl(string $accountKey): string
    {
        return "{$this->baseUrl}/{$accountKey}/Bulk";
    }
}
