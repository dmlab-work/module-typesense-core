<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Exception\ConfigurationException;

/**
 * Default {@see ConnectionSettingsInterface} binding: a connection that is not configured.
 *
 * Core defines the contract but owns no config, so its own DI graph would otherwise have no
 * implementation to resolve. This one keeps the graph valid and fails with a clear, actionable
 * message the moment anything actually asks for the connection —
 * `DmLab_TypesenseIndexer` overrides the preference with the real reader.
 */
class NotConfiguredConnectionSettings implements ConnectionSettingsInterface
{
    private const MESSAGE = 'Typesense connection not configured — install/configure DmLab_TypesenseIndexer';

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
