<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Exception;

/**
 * Thrown when a node could not be reached at all — no HTTP response.
 *
 * Distinct from {@see TypesenseException} because a 4xx does not trigger failover:
 * a server that answers 4xx is up and disagrees, and trying the next node changes nothing.
 * A 5xx does fail over — that node is lagging or overloaded, its peers may not be.
 */
class TransportException extends TypesenseException
{
    private const CURLE_OPERATION_TIMEDOUT = 28;

    /**
     * @param string $message
     * @param int $statusCode
     * @param array<string,mixed> $responseBody
     * @param \Throwable|null $previous
     * @param int $curlErrno cURL error number, 0 when unknown
     * @param bool $requestSent false only when the request provably never reached the server
     */
    public function __construct(
        string $message,
        int $statusCode = 0,
        array $responseBody = [],
        ?\Throwable $previous = null,
        private readonly int $curlErrno = 0,
        private readonly bool $requestSent = true
    ) {
        parent::__construct($message, $statusCode, $responseBody, $previous);
    }

    /**
     * The cURL error number of the failed request; 0 when unknown.
     */
    public function getCurlErrno(): int
    {
        return $this->curlErrno;
    }

    /**
     * Whether the server may have received the request; a write is safe to replay only when false.
     */
    public function isRequestSent(): bool
    {
        return $this->requestSent;
    }

    /**
     * Whether the request timed out (connect or transfer).
     */
    public function isTimeout(): bool
    {
        return $this->curlErrno === self::CURLE_OPERATION_TIMEDOUT;
    }
}
