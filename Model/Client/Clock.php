<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Client;

/**
 * Wall clock and sleep behind a seam, so the client's deadline and backoff are testable.
 */
class Clock
{
    /**
     * Current Unix time in seconds, with microseconds.
     */
    public function now(): float
    {
        return microtime(true);
    }

    /**
     * Block for the given number of microseconds.
     *
     * @param int $microseconds
     */
    public function sleep(int $microseconds): void
    {
        if ($microseconds > 0) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            usleep($microseconds);
        }
    }
}
