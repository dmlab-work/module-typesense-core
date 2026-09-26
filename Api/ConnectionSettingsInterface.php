<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Api;

use DmLab\TypesenseCore\Exception\ConfigurationException;
use DmLab\TypesenseCore\Model\Config\Node;

/**
 * The connection the transport needs, decoupled from where it comes from.
 *
 * Core is pure transport and reads no Magento config: it uses whatever connection it is
 * handed. It ships {@see \DmLab\TypesenseCore\Model\NotConfiguredConnectionSettings}
 * as the default binding so the DI graph resolves standalone;
 * `DmLab_TypesenseIndexer` owns the config paths and overrides the preference with
 * the implementation that reads them.
 *
 * The transport protocol is carried by each {@see Node}, so there is no protocol getter here.
 *
 * @api
 */
interface ConnectionSettingsInterface
{
    /**
     * Configured nodes in the order they should be tried, primary first.
     *
     * @return Node[]
     * @throws ConfigurationException when no node is configured or an entry is malformed
     */
    public function getNodes(): array;

    /**
     * Decrypted admin API key.
     *
     * @throws ConfigurationException when unset
     */
    public function getApiKey(): string;

    /**
     * Per-node request timeout in seconds.
     */
    public function getConnectionTimeout(): int;

    /**
     * Timeout in seconds for writes the engine answers only once the work is done
     * (bulk import, schema PATCH, delete by filter).
     */
    public function getOperationTimeout(): int;

    /**
     * Retries per request after the node list is exhausted.
     */
    public function getRetryCount(): int;

    /**
     * Seconds a health-check result stays cached; 0 disables caching.
     */
    public function getHealthCacheTtl(): int;
}
