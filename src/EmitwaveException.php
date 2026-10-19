<?php

namespace Emitwave;

class EmitwaveException extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $body, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "Emitwave publish failed (HTTP {$status})");
    }
}
