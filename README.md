# `emitwave/php`

Framework-independent PHP SDK for publishing signed events to Emitwave.

## Requirements

- PHP 8.3 or newer
- The cURL extension when using the built-in transport
- An Emitwave application ID and active server credential

Server credential secrets must remain on trusted servers.

## Publishing authentication

`publicKey` and `secret` are an application server credential. They are
different from the public application key used by browser clients. The SDK
hashes the exact JSON body and HMAC-signs each publish request; the gateway
validates the key, timestamp, body hash, and signature before publishing.

Browser clients separately use an end-user Bearer token to obtain a connection
token and, for authenticated channels, a subscription token.

## Publishing to channel types

`trigger()` accepts every Emitwave external channel name:

| Channel | Example | Subscriber authorization |
|---|---|---|
| Public | `news` | Connection token only |
| Private | `private-account.42` | Truthy private callback |
| Presence | `presence-room.lobby` | Callback returning `{ id, info }` |
| Public cache | `cache-prices` | Connection token only |
| Private cache | `private-cache-account.42` | Truthy private callback |
| Presence cache | `presence-cache-room.lobby` | Callback returning `{ id, info }` |
| Private encrypted | `private-encrypted-account.42` | Private callback and shared decryption key |

The gateway scopes names to the configured application. Publishing permission
does not grant a subscriber access to a private channel.

This SDK currently sends the supplied JSON payload unchanged. For a
private-encrypted channel, first create a compatible ciphertext envelope on a
trusted server and publish the outer event as `encrypted`; never send plaintext
under an encrypted channel name.

## Installation

The package is currently developed from this monorepo. Add it as a Composer
path repository while it is unpublished:

```json
{
  "repositories": [
    { "type": "path", "url": "../packages/php-sdk" }
  ],
  "require": {
    "emitwave/php": "@dev"
  }
}
```

Then run:

```bash
composer update emitwave/php
```

After a public release, use `composer require emitwave/php`.

## Publish an event

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Emitwave\Client;

$emitwave = new Client(
    appId: $_ENV['EMITWAVE_APP_ID'],
    publicKey: $_ENV['EMITWAVE_KEY'],
    secret: $_ENV['EMITWAVE_SECRET'],
    // gatewayUrl defaults to https://api.emitwave.com
);

$emitwave->trigger('orders', 'order.created', [
    'id' => 'ord_123',
    'total' => 4900,
]);
```

The default client uses cURL plus Nyholm PSR-7 factories.

## Custom PSR-18 transport

Inject a PSR-18 client and matching PSR-17 request/stream factories when your
application already has an HTTP stack:

```php
$emitwave = new Client(
    $gatewayUrl,
    $appId,
    $publicKey,
    $secret,
    $psr18Client,
    $requestFactory,
    $streamFactory,
);
```

## Error handling

```php
use Emitwave\EmitwaveException;

try {
    $emitwave->trigger('orders', 'order.created', ['id' => 'ord_123']);
} catch (EmitwaveException $error) {
    error_log("Emitwave rejected the event: HTTP {$error->status}");
    error_log($error->body);
}
```

Non-2xx responses and payload encoding failures throw `EmitwaveException`.
The exception does not include the credential secret.

## Development

```bash
composer install
./vendor/bin/phpunit
```

