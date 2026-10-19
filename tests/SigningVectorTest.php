<?php

namespace Emitwave\Tests;

use Emitwave\Signer;
use PHPUnit\Framework\TestCase;

class SigningVectorTest extends TestCase
{
    public function test_matches_shared_vector(): void
    {
        // The shared cross-language vector is vendored into tests/fixtures/ so
        // this passes in the standalone emitwave-php repo; falls back to the
        // monorepo path.
        $local = __DIR__.'/fixtures/gateway-signing.json';
        $path = is_file($local) ? $local : __DIR__.'/../../protocol/fixtures/gateway-signing.json';
        $v = json_decode(file_get_contents($path), true);
        $this->assertSame($v['query']['body_sha256'], Signer::bodySha256($v['body']));
        $cq = Signer::canonicalQuery($v['query']);
        $cs = Signer::canonicalString($v['method'], $v['path'], $cq, $v['query']['body_sha256']);
        $this->assertSame($v['signature'], Signer::sign($cs, $v['secret']));
    }
}
