<?php

namespace Emitwave;

/** Emitwave gateway HMAC auth scheme (§3.3). Byte-identical to protocol/go/signing.go. */
class Signer
{
    public static function bodySha256(string $body): string
    {
        return hash('sha256', $body);
    }

    public static function canonicalQuery(array $params): string
    {
        unset($params['auth_signature']);
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = $k.'='.$v;
        }

        return implode('&', $pairs);
    }

    public static function canonicalString(string $method, string $path, string $canonicalQuery, string $bodySha256): string
    {
        return $method."\n".$path."\n".$canonicalQuery."\n".$bodySha256;
    }

    public static function sign(string $canonical, string $secret): string
    {
        return hash_hmac('sha256', $canonical, $secret);
    }
}
