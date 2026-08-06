# SendiMessage SDK for PHP

[![Version](https://img.shields.io/github/v/tag/sendimessage/sendimessage-php?label=version&color=blue)](https://github.com/sendimessage/sendimessage-php/tags)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-brightgreen)](https://www.php.net)

The official PHP client for the [SendiMessage API](https://sendimessage.com) —
send and receive **iMessage** and **SMS** programmatically. No dependencies
beyond `ext-curl` and `ext-json`.

> **Server-side only.** The API key pair grants full messaging access to your
> account — never expose it in front-end code.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Authentication](#authentication)
- [Quick start](#quick-start)
- [Usage](#usage)
  - [Sending messages](#sending-messages)
  - [Sending media](#sending-media)
  - [Scheduling and status callbacks](#scheduling-and-status-callbacks)
  - [Message status and history](#message-status-and-history)
  - [iMessage lookup](#imessage-lookup)
  - [Contacts and opt-out](#contacts-and-opt-out)
  - [Lines and call forwarding](#lines-and-call-forwarding)
  - [Conversations](#conversations)
  - [Webhooks](#webhooks)
- [Error handling](#error-handling)
- [Configuration](#configuration)
- [API reference](#api-reference)
- [License](#license)

## Requirements

- PHP ≥ 8.1 with `ext-curl` and `ext-json`
- A SendiMessage account with an API key pair

## Installation

```bash
composer require sendimessage/sdk
```

## Authentication

Create a key pair in the [account portal](https://account.sendimessage.com)
under **API credentials**. You get a `key_id` and a `secret` — the secret is
shown **once**, store it securely (e.g. in your secret manager or environment
variables). Every request the SDK makes carries them as the `X-API-Key` /
`X-API-Secret` headers.

```php
use SendImessage\Client;

$client = new Client(
    apiKey: getenv('SENDIMESSAGE_KEY'),
    apiSecret: getenv('SENDIMESSAGE_SECRET'),
);
```

You can verify a key pair at any time with `GET /me` (curl example in the
account portal).

## Quick start

```php
use SendImessage\Client;

$client = new Client(apiKey: '...', apiSecret: '...');

$msg = $client->sendMessage(number: '+15551234567', content: 'Hello from SendiMessage!');

echo $msg['message_handle'], ' ', $msg['status']; // "…" "QUEUED"
```

The API delivers over **iMessage first** and falls back to SMS when the
recipient is not reachable over iMessage.

## Usage

### Sending messages

```php
$msg = $client->sendMessage(
    number: '+15551234567',  // E.164 phone number, or an email for iMessage
    content: 'Your order has shipped 🎉',
);
```

If your account has more than one line (sending number), route via a specific
one with `lineHandle`:

```php
$lines = $client->listLines();
$client->sendMessage(
    number: '+15551234567',
    content: 'Hi!',
    lineHandle: $lines['lines'][0]['line_handle'],
);
```

### Sending media

Upload the file first — `uploadMedia` returns a public URL you pass as
`mediaUrl`. Images, video, audio and PDF are supported, up to 50 MiB.

```php
$url = $client->uploadMedia('./photo.jpg');

$client->sendMessage(number: '+15551234567', mediaUrl: $url);
```

`content` is optional when `mediaUrl` is set; provide both to send a caption
with the attachment. Any public `http(s)` URL also works as `mediaUrl`.

### Scheduling and status callbacks

```php
$client->sendMessage(
    number: '+15551234567',
    content: 'Reminder: appointment at 3pm',
    scheduledAt: '2026-08-10T14:30:00Z',                    // ISO 8601, deliver later
    statusCallback: 'https://example.com/hooks/status',     // POSTed the final status
);
```

### Message status and history

Every send returns a `message_handle`. Poll it — or better, use a
[webhook](#webhooks) / `statusCallback`:

```php
$status = $client->getStatus($msg['message_handle']);
echo $status['status']; // QUEUED | SENT | ERROR
```

History is cursor-paginated: pass `next_before` from one page as `before` on
the next. `next_before` is `null` on the last page.

```php
$before = null;
do {
    $page = $client->listMessages(['number' => '+15551234567', 'limit' => 50, 'before' => $before]);
    foreach ($page['messages'] as $m) {
        echo $m['date_sent'], $m['is_outbound'] ? ' → ' : ' ← ', $m['content'], PHP_EOL;
    }
    $before = $page['next_before'];
} while ($before !== null);
```

Filters: `number`, `direction` (`in` / `out`), `line_handle`,
`conversation_handle`, `service`, `since`, `until`, `limit`.

```php
$one = $client->getMessage($handle); // single history message
```

### iMessage lookup

Check whether a number is reachable over iMessage before sending:

```php
$result = $client->lookup('+15551234567');
echo $result['service']; // "iMessage" | "SMS"
echo $result['cached'];  // true when answered from the capability cache
```

Lookups are answered by a live Apple device, so the API is cache-first: a
fresh cached answer returns immediately, otherwise the question is queued and
the call waits briefly for a device. If no answer arrives in time you get a
`pending` response — retry shortly; a `503` means no device is currently
online for your account.

### Contacts and opt-out

Contacts are your address book: names shown in conversations, the detected
service (iMessage/SMS) and the opt-out flag.

```php
$client->createContact(['number' => '+15551234567', 'first_name' => 'Jane', 'last_name' => 'Doe']);
$client->updateContact('+15551234567', ['company_name' => 'Acme Inc.']);
$contact = $client->getContact('+15551234567'); // number or contact_handle
$all = $client->listContacts(['limit' => 100]);
$client->deleteContact('+15551234567');
$client->recheckContact('+15551234567'); // re-run the iMessage/SMS check now
```

**Opt-out is enforced on every send path** — a send to an opted-out number
fails with HTTP 422. Inbound `STOP` / `UNSUBSCRIBE` / `CANCEL` / `END` /
`QUIT` opts the sender out automatically; `START` / `UNSTOP` / `YES` opts
back in. You can also manage it explicitly:

```php
$client->setOptOut('+15551234567', true);   // opt out
$client->setOptOut('+15551234567', false);  // opt back in
```

### Lines and call forwarding

A **line** is a sending identity (phone number or iMessage address) on your
account.

```php
$lines = $client->listLines();
$client->updateLine($lineHandle, label: 'Support line', isActive: true);
```

Calls to your line's number can be forwarded to any number you choose.
Forwarding is configured by the SendiMessage operator, so changes are
asynchronous — the API answers `202` and the request shows as pending until
fulfilled:

```php
$client->setCallForwarding($lineHandle, '+15559876543'); // request forwarding
$client->setCallForwarding($lineHandle, null);           // request turning it off
$state = $client->getCallForwarding($lineHandle);        // current + pending
```

### Conversations

Conversations group the message history per contact:

```php
$convos = $client->listConversations(['limit' => 20]);
$msgs = $client->listConversationMessages($conversationHandle, ['limit' => 50]);
$client->markConversationRead($conversationHandle);
```

### Webhooks

Get pushed events instead of polling. Events:

| Event | Fired when |
|---|---|
| `receive` | An inbound message arrives |
| `outbound` | An outbound message reaches its final status (sent/failed) |
| `line_blocked` | A line was blocked by Apple (sends answer 422 until unblocked) |
| `line_unblocked` | The block was lifted |

```php
$client->createWebhook(
    url: 'https://example.com/hooks/sendimessage',
    events: ['receive', 'outbound'],        // default: every event
    secret: getenv('WEBHOOK_SECRET'),       // enables the signature header
);

$hooks = $client->listWebhooks();
$client->replaceWebhooks([...]);            // swap the whole set atomically
$client->deleteWebhook('https://example.com/hooks/sendimessage');
```

When the webhook has a `secret`, every delivery carries an
`X-SMSBridge-Signature` header — the hex HMAC-SHA256 of the **raw** request
body. Always verify it:

```php
use SendImessage\Client;

// The raw body is required — re-serialized JSON differs byte-for-byte.
$rawBody = file_get_contents('php://input');

$ok = Client::verifyWebhookSignature(
    secret: getenv('WEBHOOK_SECRET'),
    rawBody: $rawBody,
    signature: $_SERVER['HTTP_X_SMSBRIDGE_SIGNATURE'] ?? '',
);
if (!$ok) {
    http_response_code(401);
    exit;
}

$event = json_decode($rawBody, true);
// ... handle event
```

## Error handling

Every non-2xx response throws `SendImessage\ApiException` with the HTTP code
as `getCode()`, the API error text as `getMessage()`, and the parsed response
body on the `body` property.

```php
use SendImessage\ApiException;

try {
    $client->sendMessage(number: '+15551234567', content: 'hi');
} catch (ApiException $e) {
    error_log($e->getCode() . ' ' . $e->getMessage());
}
```

Common statuses:

| Status | Meaning |
|---|---|
| `401` | Bad or missing key pair |
| `404` | Unknown handle (message, contact, line, conversation) |
| `422` | Validation failed — including sends to an opted-out contact or via a blocked line |
| `429` | Rate limited — back off and retry |
| `503` | Lookup: no device online for your account |

## Configuration

```php
$client = new Client(
    apiKey: '...',
    apiSecret: '...',
    baseUrl: 'https://api.sendimessage.com/v1', // default; override for testing
    timeout: 30,                                // seconds, per request
);
```

## API reference

| Method | Endpoint |
|---|---|
| `sendMessage(number, content?, mediaUrl?, lineHandle?, statusCallback?, scheduledAt?)` | `POST /send-message` |
| `getStatus(messageHandle)` | `GET /status` |
| `listMessages(filters?)` | `GET /v2/messages` |
| `getMessage(messageHandle)` | `GET /v2/messages/{handle}` |
| `uploadMedia(path, filename?)` | `POST /media` |
| `lookup(number)` | `GET /lookup` |
| `listContacts(params?)` | `GET /contacts` |
| `createContact(props)` | `POST /contacts` |
| `getContact(number)` | `GET /contacts/{number}` |
| `updateContact(number, props)` | `PUT /contacts/{number}` |
| `deleteContact(number)` | `DELETE /contacts/{number}` |
| `setOptOut(number, optedOut?)` | `POST /contacts/opt-out` |
| `recheckContact(number)` | `POST /contacts/{number}/lookup` |
| `listLines()` | `GET /lines` |
| `updateLine(lineHandle, label?, isActive?)` | `PUT /lines/{handle}` |
| `getCallForwarding(lineHandle)` | `GET /lines/{handle}/call-forwarding` |
| `setCallForwarding(lineHandle, forwardingNumber)` | `PUT /lines/{handle}/call-forwarding` |
| `listConversations(params?)` | `GET /conversations` |
| `listConversationMessages(handle, params?)` | `GET /conversations/{handle}/messages` |
| `markConversationRead(handle)` | `POST /conversations/{handle}/read` |
| `listWebhooks()` | `GET /account/webhooks` |
| `createWebhook(url, events?, secret?)` | `POST /account/webhooks` |
| `replaceWebhooks(webhooks)` | `PUT /account/webhooks` |
| `deleteWebhook(url)` | `DELETE /account/webhooks` |

Full HTTP-level reference: the [Postman collection](https://sendimessage.com/postman_collection.json).

## License

[MIT](LICENSE)
