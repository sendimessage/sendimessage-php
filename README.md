# SendiMessage SDK for PHP

Dependency-free client (PHP ≥ 8.1, ext-curl) for the SendiMessage API.
Server-side use — the key pair grants full messaging access.

## Install

```bash
composer require sendimessage/sdk
```

## Quick start

```php
use SendImessage\Client;

$client = new Client($apiKey, $apiSecret); // from the account portal

// Text
$msg = $client->sendMessage('+15551234567', content: 'Hello!');
echo $msg['message_handle'], ' ', $msg['status']; // QUEUED

// Photo / video: upload first, then send the returned URL
$url = $client->uploadMedia('photo.jpg');
$client->sendMessage('+15551234567', mediaUrl: $url);

// Delivery status
$status = $client->getStatus($msg['message_handle']);
```

## Webhooks

```php
$client->createWebhook(
    'https://example.com/hooks/sendimessage',
    events: ['receive', 'outbound'],
    secret: 'my-shared-secret',
);

// In your webhook handler:
$ok = Client::verifyWebhookSignature(
    'my-shared-secret',
    file_get_contents('php://input'),
    $_SERVER['HTTP_X_SMSBRIDGE_SIGNATURE'] ?? '',
);
```

## Errors

Any non-2xx response throws `SendImessage\ApiException` with `->status`
(HTTP code), the API's error text as the message, and `->body` (parsed
response).

```php
use SendImessage\ApiException;

try {
    $client->sendMessage('+15551234567', content: 'hi');
} catch (ApiException $e) {
    if ($e->status === 422) {
        echo $e->getMessage(); // e.g. "Contact has opted out of messages"
    }
}
```

## Surface

Messages: `sendMessage`, `getStatus`, `listMessages`, `getMessage`, `uploadMedia`.
Lookup: `lookup($number)` — iMessage or SMS.
Contacts: `listContacts`, `createContact`, `getContact`, `updateContact`,
`deleteContact`, `setOptOut`, `recheckContact`.
Lines: `listLines`, `updateLine`, `getCallForwarding`, `setCallForwarding`.
Conversations: `listConversations`, `listConversationMessages`, `markConversationRead`.
Webhooks: `listWebhooks`, `createWebhook`, `replaceWebhooks`, `deleteWebhook`.

Full API reference: the Postman collection at
`https://sendimessage.com/postman_collection.json`.
