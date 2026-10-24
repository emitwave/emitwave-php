<?php

namespace Emitwave;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class Client
{
    private ClientInterface $http;

    private RequestFactoryInterface $requestFactory;

    private StreamFactoryInterface $streamFactory;

    /**
     * The PSR trio is optional: omit it and the SDK wires its own curl-backed
     * transport + PSR-17 factories, so the standalone package works with no
     * extra setup. Inject your own (e.g. Guzzle) for retries/pooling.
     */
    /** The live Emitwave gateway, used when $gatewayUrl is omitted. */
    public const DEFAULT_GATEWAY_URL = 'https://api.emitwave.com';

    public function __construct(
        private string $appId,
        private string $publicKey,
        private string $secret,
        private string $gatewayUrl = self::DEFAULT_GATEWAY_URL,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        if ($this->gatewayUrl === '') {
            $this->gatewayUrl = self::DEFAULT_GATEWAY_URL;
        }
        $factory = ($requestFactory === null || $streamFactory === null || $http === null)
            ? new Psr17Factory()
            : null;
        $this->requestFactory = $requestFactory ?? $factory;
        $this->streamFactory = $streamFactory ?? $factory;
        $this->http = $http ?? new CurlHttpClient($factory);
    }

    public function trigger(string $channel, string $event, array $data = []): void
    {
        $this->post("/apps/{$this->appId}/events", [
            'name' => $event,
            'channel' => $channel,
            'data' => $data,
        ]);
    }

    /** @return array{token:string, ttl:int, app_id:string} */
    public function connectionToken(string $userId, array $info = []): array
    {
        $payload = ['user_id' => $userId];
        if ($info !== []) {
            $payload['info'] = $info;
        }

        return $this->post("/apps/{$this->appId}/connection_tokens", $payload);
    }

    /** @return array{token:string, ttl:int, channel:string, shared_secret?:string} */
    public function subscriptionToken(string $userId, string $channel, array $info = []): array
    {
        $payload = [
            'user_id' => $userId,
            'channel' => $channel,
        ];
        if ($info !== []) {
            $payload['info'] = $info;
        }

        return $this->post("/apps/{$this->appId}/subscription_tokens", $payload);
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $payload): array
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new EmitwaveException(0, '', 'Failed to JSON-encode request payload: '.$e->getMessage());
        }
        $q = [
            'auth_key' => $this->publicKey,
            'auth_timestamp' => (string) time(),
            'auth_version' => '1.0',
            'body_sha256' => Signer::bodySha256($body),
        ];
        $q['auth_signature'] = Signer::sign(
            Signer::canonicalString('POST', $path, Signer::canonicalQuery($q), $q['body_sha256']),
            $this->secret,
        );

        $url = rtrim($this->gatewayUrl, '/').$path.'?'.http_build_query($q);
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        $resp = $this->http->sendRequest($request);
        $status = $resp->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new EmitwaveException($status, (string) $resp->getBody());
        }

        $responseBody = (string) $resp->getBody();
        if ($responseBody === '') {
            return [];
        }

        try {
            $decoded = json_decode($responseBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new EmitwaveException($status, $responseBody, 'Emitwave returned invalid JSON: '.$e->getMessage());
        }

        if (! is_array($decoded)) {
            throw new EmitwaveException($status, $responseBody, 'Emitwave returned an invalid response payload.');
        }

        return $decoded;
    }
}
