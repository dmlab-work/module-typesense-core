<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Client\Http;

use DmLab\TypesenseCore\Exception\TransportException;

/**
 * The single seam between this module and an actual HTTP stack.
 *
 * Implementations do transport only: no retry, no failover, no status interpretation.
 *
 * @api
 */
interface TransportInterface
{
    /**
     * Send one request and return the response, whatever its status.
     *
     * @param string $method HTTP method, uppercase
     * @param string $url absolute URL including query string
     * @param array<string,string> $headers header name => value
     * @param string|null $body raw request body
     * @param int $timeout seconds
     * @throws TransportException when the host could not be reached or answered unintelligibly
     */
    public function send(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 5
    ): Response;
}
