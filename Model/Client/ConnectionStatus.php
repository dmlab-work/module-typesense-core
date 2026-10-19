<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Client;

/**
 * Verdict of {@see HealthChecker::verifyConnection()}.
 *
 * Not `final`: consumers double it in their own tests.
 *
 * @api
 */
class ConnectionStatus
{
    public const STATE_OK = 'ok';
    public const STATE_UNREACHABLE = 'unreachable';
    public const STATE_UNAUTHORIZED = 'unauthorized';

    /**
     * @param string $state
     * @param string $message
     * @param string|null $version
     */
    private function __construct(
        private readonly string $state,
        private readonly string $message,
        private readonly ?string $version
    ) {
    }

    /**
     * A node answered and accepted the API key.
     *
     * @param string $version
     * @param string $message
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- a value object is never a DI subject
    public static function ok(string $version, string $message): self
    {
        return new self(self::STATE_OK, $message, $version);
    }

    /**
     * No node could be used: misconfigured, down or erroring.
     *
     * @param string $message
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- a value object is never a DI subject
    public static function unreachable(string $message): self
    {
        return new self(self::STATE_UNREACHABLE, $message, null);
    }

    /**
     * A node is up but the API key is missing or rejected.
     *
     * @param string $message
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- a value object is never a DI subject
    public static function unauthorized(string $message): self
    {
        return new self(self::STATE_UNAUTHORIZED, $message, null);
    }

    /**
     * Whether the connection is usable.
     */
    public function isOk(): bool
    {
        return $this->state === self::STATE_OK;
    }

    /**
     * One of the `STATE_*` constants.
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * Human-readable, translated verdict.
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Typesense version when ok, `unknown` if the node did not report it; null otherwise.
     */
    public function getVersion(): ?string
    {
        return $this->version;
    }
}
