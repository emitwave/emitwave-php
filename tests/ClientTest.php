<?php

namespace Emitwave\Tests;

use Emitwave\Client;
use Emitwave\EmitwaveException;
use Emitwave\Signer;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class FakeHttp implements ClientInterface
{
    public ?RequestInterface $last = null;

    public function __construct(private int $status = 200, private string $body = '{"published":true}') {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->last = $request;
        $f = new Psr17Factory();

        return $f->createResponse($this->status)->withBody($f->createStream($this->body));
    }
}

class ClientTest extends TestCase
{
    private function client(FakeHttp $http): Client
    {
        $f = new Psr17Factory();

        return new Client('app_1', 'ew_pk_test_x', 'sekret', 'http://gw:8080', $http, $f, $f);
    }

    public function test_trigger_signs_and_posts_correct_request(): void
    {
        $http = new FakeHttp();
        $this->client($http)->trigger('news', 'article.created', ['hello' => 'world']);

        $req = $http->last;
        $this->assertSame('POST', $req->getMethod());
        $this->assertStringContainsString('/apps/app_1/events', (string) $req->getUri());
        parse_str($req->getUri()->getQuery(), $q);
        $this->assertSame('ew_pk_test_x', $q['auth_key']);
        $this->assertSame('1.0', $q['auth_version']);
        $this->assertNotEmpty($q['auth_timestamp']);
        $body = (string) $req->getBody();
        $this->assertSame(Signer::bodySha256($body), $q['body_sha256']);
        $this->assertStringContainsString('"name":"article.created"', $body);
        $this->assertStringContainsString('"channel":"news"', $body);
        $expected = Signer::sign(Signer::canonicalString('POST', '/apps/app_1/events', Signer::canonicalQuery($q), $q['body_sha256']), 'sekret');
        $this->assertSame($expected, $q['auth_signature']);
    }

    public function test_non_2xx_throws_with_status_and_body_no_secret(): void
    {
        $http = new FakeHttp(403, '{"error":{"code":"INVALID_APP_KEY"}}');
        try {
            $this->client($http)->trigger('news', 'x', []);
            $this->fail('expected EmitwaveException');
        } catch (EmitwaveException $e) {
            $this->assertSame(403, $e->status);
            $this->assertStringContainsString('INVALID_APP_KEY', $e->body);
            $this->assertStringNotContainsString('sekret', $e->getMessage().$e->body);
        }
    }

    public function test_connection_token_signs_request_and_returns_gateway_payload(): void
    {
        $http = new FakeHttp(200, '{"token":"connection.jwt","ttl":900,"app_id":"app_1"}');

        $result = $this->client($http)->connectionToken('user_42', ['name' => 'Ada']);

        $this->assertSame(['token' => 'connection.jwt', 'ttl' => 900, 'app_id' => 'app_1'], $result);
        $this->assertSame('/apps/app_1/connection_tokens', $http->last->getUri()->getPath());
        $this->assertSame([
            'user_id' => 'user_42',
            'info' => ['name' => 'Ada'],
        ], json_decode((string) $http->last->getBody(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertValidSignature($http->last);
    }

    public function test_subscription_token_signs_scoped_channel_request(): void
    {
        $http = new FakeHttp(200, '{"token":"subscription.jwt","ttl":300,"channel":"private:app_1.user.42"}');

        $result = $this->client($http)->subscriptionToken('user_42', 'private-user.42', ['role' => 'owner']);

        $this->assertSame('subscription.jwt', $result['token']);
        $this->assertSame('/apps/app_1/subscription_tokens', $http->last->getUri()->getPath());
        $this->assertSame([
            'user_id' => 'user_42',
            'channel' => 'private-user.42',
            'info' => ['role' => 'owner'],
        ], json_decode((string) $http->last->getBody(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertValidSignature($http->last);
    }

    private function assertValidSignature(RequestInterface $request): void
    {
        parse_str($request->getUri()->getQuery(), $query);
        $body = (string) $request->getBody();
        $expected = Signer::sign(
            Signer::canonicalString('POST', $request->getUri()->getPath(), Signer::canonicalQuery($query), Signer::bodySha256($body)),
            'sekret',
        );
        $this->assertSame($expected, $query['auth_signature']);
    }

    public function test_unencodable_payload_throws_not_silently_wrong(): void
    {
        // A non-UTF-8 byte makes json_encode return false; a silently-wrong
        // (empty) publish must not reach the gateway — fail fast instead.
        $http = new FakeHttp();
        $this->expectException(EmitwaveException::class);
        $this->client($http)->trigger('news', 'x', ['bad' => "\xB1\x31"]);
    }

    public function test_default_http_client_when_none_supplied(): void
    {
        // The standalone package must work with zero wiring: omit the PSR
        // trio and the client builds its own curl-backed transport + factories.
        $client = new Client('app_1', 'ew_pk_test_x', 'sekret', 'http://gw:8080');
        $this->assertInstanceOf(Client::class, $client);
    }
}
