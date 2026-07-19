<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseCore\Model\Client\Http;

/**
 * A raw HTTP response: status and body, undecoded.
 */
class Response
{
    /**
     * @param int $statusCode
     * @param string $body
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly string $body
    ) {
    }

    /**
     * HTTP status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Raw response body.
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Whether the status is in the 2xx range.
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
