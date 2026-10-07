<?php

namespace Vipertecpro\MobileEntitlements\Exceptions;

use RuntimeException;

/**
 * The store API could not be reached or returned an unexpected response.
 */
class StoreApiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    /**
     * True when the store says the token or transaction does not exist (a client error, not an outage).
     */
    public function isNotFound(): bool
    {
        return in_array($this->status, [400, 404, 410], true);
    }
}
