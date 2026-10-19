<?php

namespace Emitwave;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal PSR-18 client over curl, so the SDK works with zero extra runtime HTTP
 * deps. Callers needing retries/pooling should inject their own PSR-18 client.
 */
class CurlHttpClient implements ClientInterface
{
    public function __construct(private ResponseFactoryInterface $responseFactory) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $ch = curl_init((string) $request->getUri());
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request->getMethod());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[] = $name.': '.implode(', ', $values);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $bodyStr = (string) $request->getBody();
        if ($bodyStr !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyStr);
        }
        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('curl transport error: '.$err);
        }
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $resp = $this->responseFactory->createResponse($status);
        $resp->getBody()->write(is_string($out) ? $out : '');

        return $resp;
    }
}
