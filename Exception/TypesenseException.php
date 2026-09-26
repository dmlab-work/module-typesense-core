<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Exception;

/**
 * Thrown when Typesense answers with a non-2xx status or an undecodable body.
 *
 * Carries the HTTP status and the decoded response body so callers can branch on
 * them (404 on a missing collection, 409 on a duplicate) without parsing text.
 */
class TypesenseException extends \RuntimeException
{
    /**
     * @param string $message
     * @param int $statusCode HTTP status, 0 when no response was received
     * @param array<string,mixed> $responseBody decoded response body, empty when undecodable
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message,
        int $statusCode = 0,
        private readonly array $responseBody = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * HTTP status of the failed response; 0 when the request never got one.
     */
    public function getStatusCode(): int
    {
        return $this->getCode();
    }

    /**
     * Decoded response body, empty when there was none or it was undecodable.
     *
     * @return array<string,mixed>
     */
    public function getResponseBody(): array
    {
        return $this->responseBody;
    }
}
