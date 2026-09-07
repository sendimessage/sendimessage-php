<?php

namespace SendImessage;

/**
 * Client for the SendiMessage public API (X-API-Key / X-API-Secret).
 * Server-side use only: the key pair grants full messaging access.
 *
 *   $client = new \SendImessage\Client($apiKey, $apiSecret);
 *   $msg = $client->sendMessage('+15551234567', content: 'Hello!');
 *   echo $msg['message_handle']; // status QUEUED
 */
class Client
{
    public const DEFAULT_BASE_URL = 'https://api.sendimessage.com/v1';
    public const VERSION = '0.1.1';

    private string $baseUrl;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $timeout = 30,
    ) {
        if ($apiKey === '' || $apiSecret === '') {
            throw new \InvalidArgumentException('apiKey and apiSecret are required');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    // ---- Messages -----------------------------------------------------------

    /**
     * Queue a message; $content is optional when $mediaUrl is set.
     * Returns the outbound payload with message_handle and status QUEUED.
     */
    public function sendMessage(
        string $number,
        ?string $content = null,
        ?string $mediaUrl = null,
        ?string $lineHandle = null,
        ?string $statusCallback = null,
        ?string $scheduledAt = null,
        ?string $service = null,
    ): array {
        return $this->request('POST', '/send-message', json: self::prune([
            'number' => $number,
            'content' => $content,
            'media_url' => $mediaUrl,
            'line_handle' => $lineHandle,
            'status_callback' => $statusCallback,
            'scheduled_at' => $scheduledAt,
            // 'sms' = plain text message, no iMessage attempt; 'imessage' = default path
            'service' => $service,
        ]));
    }

    /** The user + company behind these credentials (GET /me). */
    public function me(): array
    {
        return $this->request('GET', '/me');
    }

    public function getStatus(string $messageHandle): array
    {
        return $this->request('GET', '/status', query: ['message_handle' => $messageHandle]);
    }

    /**
     * Message history. Filters: number, direction (in|out), line_handle,
     * conversation_handle, service, since, until, limit, before (cursor —
     * pass next_before from the previous page).
     */
    public function listMessages(array $filters = []): array
    {
        return $this->request('GET', '/v2/messages', query: self::prune($filters));
    }

    public function getMessage(string $messageHandle): array
    {
        return $this->request('GET', '/v2/messages/' . rawurlencode($messageHandle));
    }

    // ---- Media --------------------------------------------------------------

    /**
     * Upload a file (image/video/audio/PDF, max 50 MiB); returns the public
     * URL to pass as media_url to sendMessage.
     */
    public function uploadMedia(string $path, ?string $filename = null): string
    {
        if (!is_readable($path)) {
            throw new \InvalidArgumentException("File not readable: $path");
        }
        $file = new \CURLFile($path, posted_filename: $filename ?? basename($path));
        $result = $this->request('POST', '/media', multipart: ['file' => $file]);

        return $result['url'];
    }

    // ---- Lookup -------------------------------------------------------------

    /** Does this number have iMessage? -> {number, service, cached, checked_at} */
    public function lookup(string $number): array
    {
        return $this->request('GET', '/lookup', query: ['number' => $number]);
    }

    // ---- Contacts -----------------------------------------------------------

    public function listContacts(array $params = []): array
    {
        return $this->request('GET', '/contacts', query: self::prune($params));
    }

    public function createContact(array $props): array
    {
        return $this->request('POST', '/contacts', json: self::prune($props));
    }

    /** $number is the E.164 number or the contact_handle. */
    public function getContact(string $number): array
    {
        return $this->request('GET', '/contacts/' . rawurlencode($number));
    }

    public function updateContact(string $number, array $props): array
    {
        return $this->request('PUT', '/contacts/' . rawurlencode($number), json: self::prune($props));
    }

    public function deleteContact(string $number): array
    {
        return $this->request('DELETE', '/contacts/' . rawurlencode($number));
    }

    /** Opt a number out of (or back into) all messaging. */
    public function setOptOut(string $number, bool $optedOut = true): array
    {
        return $this->request('POST', '/contacts/opt-out', json: ['number' => $number, 'opted_out' => $optedOut]);
    }

    /** Re-run the iMessage/SMS service check for a contact right now. */
    public function recheckContact(string $number): array
    {
        return $this->request('POST', '/contacts/' . rawurlencode($number) . '/lookup');
    }

    // ---- Lines --------------------------------------------------------------

    public function listLines(): array
    {
        return $this->request('GET', '/lines');
    }

    public function updateLine(string $lineHandle, ?string $label = null, ?bool $isActive = null): array
    {
        return $this->request('PUT', '/lines/' . rawurlencode($lineHandle), json: self::prune([
            'label' => $label,
            'is_active' => $isActive,
        ]));
    }

    public function getCallForwarding(string $lineHandle): array
    {
        return $this->request('GET', '/lines/' . rawurlencode($lineHandle) . '/call-forwarding');
    }

    /** Queue a forwarding change for the operator (202); null turns it off. */
    public function setCallForwarding(string $lineHandle, ?string $forwardingNumber): array
    {
        return $this->request('PUT', '/lines/' . rawurlencode($lineHandle) . '/call-forwarding', json: [
            'forwarding_number' => $forwardingNumber,
        ]);
    }

    // ---- Conversations ------------------------------------------------------

    public function listConversations(array $params = []): array
    {
        return $this->request('GET', '/conversations', query: self::prune($params));
    }

    public function listConversationMessages(string $conversationHandle, array $params = []): array
    {
        return $this->request(
            'GET',
            '/conversations/' . rawurlencode($conversationHandle) . '/messages',
            query: self::prune($params),
        );
    }

    public function markConversationRead(string $conversationHandle): array
    {
        return $this->request('POST', '/conversations/' . rawurlencode($conversationHandle) . '/read');
    }

    // ---- Webhooks -----------------------------------------------------------

    public function listWebhooks(): array
    {
        return $this->request('GET', '/account/webhooks');
    }

    /** $events: subset of ['receive', 'outbound', 'line_blocked', 'line_unblocked'] (default: all). */
    public function createWebhook(string $url, ?array $events = null, ?string $secret = null): array
    {
        return $this->request('POST', '/account/webhooks', json: self::prune([
            'url' => $url,
            'events' => $events,
            'secret' => $secret,
        ]));
    }

    /** Replace the whole webhook set: [['url' => ..., 'events' => ?, 'secret' => ?], ...] */
    public function replaceWebhooks(array $webhooks): array
    {
        return $this->request('PUT', '/account/webhooks', json: ['webhooks' => $webhooks]);
    }

    public function deleteWebhook(string $url): array
    {
        return $this->request('DELETE', '/account/webhooks', json: ['url' => $url]);
    }

    // ---- Webhook signature --------------------------------------------------

    /**
     * Verify the X-SMSBridge-Signature header of an incoming webhook: hex
     * HMAC-SHA256 of the raw request body with the webhook's secret. Pass the
     * body exactly as received (e.g. file_get_contents('php://input')).
     */
    public static function verifyWebhookSignature(string $secret, string $rawBody, string $signature): bool
    {
        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    // ---- Internals ----------------------------------------------------------

    private function request(
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        ?array $multipart = null,
    ): mixed {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'X-API-Key: ' . $this->apiKey,
            'X-API-Secret: ' . $this->apiSecret,
            'Accept: application/json',
            'User-Agent: sendimessage-php/' . self::VERSION,
        ];

        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($multipart !== null) {
            $options[CURLOPT_POSTFIELDS] = $multipart; // curl builds the multipart body
        } elseif ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $text = curl_exec($ch);
        if ($text === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new ApiException("Request failed: $error", 0);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $parsed = json_decode((string) $text, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($parsed) && isset($parsed['message']) ? $parsed['message'] : "HTTP $status";
            throw new ApiException($message, $status, $parsed ?? $text);
        }

        return $parsed;
    }

    /** Drop null entries so optional params never reach the wire. */
    private static function prune(array $values): array
    {
        return array_filter($values, static fn ($v) => $v !== null);
    }
}
