<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseCore\Model\Config;

/**
 * One configured Typesense node: a host, a port and the protocol to reach it over.
 */
class Node
{
    /**
     * @param string $host
     * @param int $port
     * @param string $protocol `http` or `https`
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $protocol = 'http'
    ) {
    }

    /**
     * Configured host name or address.
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Configured port.
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Configured transport protocol, `http` or `https`.
     */
    public function getProtocol(): string
    {
        return $this->protocol;
    }

    /**
     * Base URL for this node, without a trailing slash.
     */
    public function getBaseUrl(): string
    {
        return $this->protocol . '://' . $this->host . ':' . $this->port;
    }
}
