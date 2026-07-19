<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseCore\Test\Unit\Model;

use MageDevGroup\TypesenseCore\Exception\ConfigurationException;
use MageDevGroup\TypesenseCore\Model\NotConfiguredConnectionSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NotConfiguredConnectionSettingsTest extends TestCase
{
    /** @var NotConfiguredConnectionSettings */
    private NotConfiguredConnectionSettings $settings;

    protected function setUp(): void
    {
        $this->settings = new NotConfiguredConnectionSettings();
    }

    /**
     * Every getter must fail loudly and point at the module that owns the connection.
     *
     * @param string $method
     */
    #[DataProvider('getters')]
    public function testEveryGetterThrowsWithAnActionableMessage(string $method): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('MageDevGroup_TypesenseIndexer');

        $this->settings->$method();
    }

    /**
     * @return array<string,array{string}>
     */
    public static function getters(): array
    {
        return [
            'getNodes' => ['getNodes'],
            'getApiKey' => ['getApiKey'],
            'getConnectionTimeout' => ['getConnectionTimeout'],
            'getOperationTimeout' => ['getOperationTimeout'],
            'getRetryCount' => ['getRetryCount'],
            'getHealthCacheTtl' => ['getHealthCacheTtl'],
        ];
    }
}
