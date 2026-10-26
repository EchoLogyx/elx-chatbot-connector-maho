<?php

declare(strict_types=1);

class Elx_ChatbotConnector_Model_Client
{
    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string, json: array<string, mixed>}
     */
    public function register(array $payload): array
    {
        return $this->request('PUT', Elx_ChatbotConnector_Helper_Data::REGISTER_URL, $payload, false);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string, json: array<string, mixed>}
     */
    public function pushFeed(array $payload): array
    {
        return $this->request('POST', Elx_ChatbotConnector_Helper_Data::FEED_PUSH_URL, $payload, true);
    }

    /**
     * Exchange Maho OAuth2 client credentials for the Bearer token the chatbot sends on order lookup.
     *
     * @return array{status: int, body: string, json: array<string, mixed>}
     */
    public function mahoAccessToken(string $tokenUrl, string $clientId, string $clientSecret): array
    {
        return $this->request('POST', $tokenUrl, [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ], false);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string, json: array<string, mixed>}
     */
    private function request(string $method, string $url, array $payload, bool $followLocation): array
    {
        $encoded = json_encode($payload);
        if ($encoded === false) {
            throw new RuntimeException('Could not encode the ELX Chatbot request.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not start the ELX Chatbot request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $followLocation,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new RuntimeException($error !== '' ? $error : 'The ELX Chatbot service did not respond.');
        }

        $decoded = json_decode((string) $body, true);

        return [
            'status' => $status,
            'body' => (string) $body,
            'json' => is_array($decoded) ? $decoded : [],
        ];
    }
}
