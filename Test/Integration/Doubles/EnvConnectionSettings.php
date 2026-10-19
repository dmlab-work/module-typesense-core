<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Integration\Doubles;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Model\Config\Node;

/**
 * Connection settings for one Typesense node given as a URL, as the test environment supplies it.
 */
class EnvConnectionSettings implements ConnectionSettingsInterface
{
    /** @var Node[] */
    private readonly array $nodes;

    /**
     * @param string $url e.g. `http://typesense:8108`
     * @param string $apiKey
     * @param Node[] $leadingNodes tried before the URL's node, for failover tests
     */
    public function __construct(
        string $url,
        #[\SensitiveParameter] private readonly string $apiKey,
        array $leadingNodes = []
    ) {
        $this->nodes = [...array_values($leadingNodes), self::parseNode($url)];
    }

    /**
     * Turn `scheme://host:port` into a node; the port defaults by scheme.
     *
     * @param string $url
     * @throws \InvalidArgumentException when the URL has no host
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- a test double, never a DI subject
    public static function parseNode(string $url): Node
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a Typesense node URL.', $url));
        }
        $protocol = strtolower($parts['scheme'] ?? 'http');

        return new Node($parts['host'], (int)($parts['port'] ?? ($protocol === 'https' ? 443 : 8108)), $protocol);
    }

    /**
     * @inheritDoc
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    /**
     * @inheritDoc
     */
    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * @inheritDoc
     */
    public function getConnectionTimeout(): int
    {
        return 5;
    }

    /**
     * @inheritDoc
     */
    public function getOperationTimeout(): int
    {
        return 60;
    }

    /**
     * @inheritDoc
     */
    public function getRetryCount(): int
    {
        return 1;
    }

    /**
     * @inheritDoc
     */
    public function getHealthCacheTtl(): int
    {
        return 0;
    }
}
