<?php

namespace Emitwave\Tests;

use Emitwave\Signer;
use PHPUnit\Framework\TestCase;

class SignerTest extends TestCase
{
    public function test_body_sha256_lowercase_hex(): void
    {
        $this->assertSame(hash('sha256', '{"a":1}'), Signer::bodySha256('{"a":1}'));
    }

    public function test_canonical_query_sorts_excludes_signature(): void
    {
        $q = Signer::canonicalQuery([
            'auth_signature' => 'DROP', 'auth_timestamp' => '100',
            'auth_key' => 'k', 'auth_version' => '1.0', 'body_sha256' => 'abc',
        ]);
        $this->assertSame('auth_key=k&auth_timestamp=100&auth_version=1.0&body_sha256=abc', $q);
    }

    public function test_canonical_string_shape(): void
    {
        $this->assertSame("POST\n/apps/app_1/events\na=b\ndead", Signer::canonicalString('POST', '/apps/app_1/events', 'a=b', 'dead'));
    }

    public function test_sign_hmac_sha256_hex(): void
    {
        $c = "POST\n/apps/app_1/events\nauth_key=k\ndead";
        $this->assertSame(hash_hmac('sha256', $c, 's'), Signer::sign($c, 's'));
    }
}
