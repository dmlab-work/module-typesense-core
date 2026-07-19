<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseCore\Model;

use MageDevGroup\TypesenseCore\Api\ConnectionSettingsInterface;
use MageDevGroup\TypesenseCore\Exception\ConfigurationException;

/**
 * Default {@see ConnectionSettingsInterface} binding: a connection that is not configured.
 *
 * Core defines the contract but owns no config, so its own DI graph would otherwise have no
 * implementation to resolve. This one keeps the graph valid and fails with a clear, actionable
 * message the moment anything actually asks for the connection —
 * `MageDevGroup_TypesenseIndexer` overrides the preference with the real reader.
 */
class NotConfiguredConnectionSettings implements ConnectionSettingsInterface
{
    private const MESSAGE = 'Typesense connection not configured — install/configure MageDevGroup_TypesenseIndexer';

    /**
     * @inheritDoc
     */
    public function getNodes(): array
    {
        throw new ConfigurationException(self::MESSAGE);
    }

    /**
     * @inheritDoc
     */
    public function getApiKey(): string
    {
        throw new ConfigurationException(self::MESSAGE);
    }

    /**
     * @inheritDoc
     */
    public function getConnectionTimeout(): int
    {
        throw new ConfigurationException(self::MESSAGE);
    }

    /**
     * @inheritDoc
     */
    public function getOperationTimeout(): int
    {
        throw new ConfigurationException(self::MESSAGE);
    }

    /**
     * @inheritDoc
     */
    public function getRetryCount(): int
    {
        throw new ConfigurationException(self::MESSAGE);
    }

    /**
     * @inheritDoc
     */
    public function getHealthCacheTtl(): int
    {
        throw new ConfigurationException(self::MESSAGE);
    }
}
