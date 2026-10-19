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
        try {
            $body = json_encode(
                ['name' => $event, 'channel' => $channel, 'data' => $data],
                JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $e) {
            // A non-encodable payload must fail fast, not silently publish an
            // empty/garbled body to the gateway.
            throw new EmitwaveException(0, '', 'Failed to JSON-encode event payload: '.$e->getMessage());
        }
        $path = "/apps/{$this->appId}/events";
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
    }
}
