<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Model\Client;

use DmLab\TypesenseCore\Model\Client\NodeHealthRegistry;
use DmLab\TypesenseCore\Model\Config\Node;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class NodeHealthRegistryTest extends TestCase
{
    /** @var Node */
    private Node $a;

    /** @var Node */
    private Node $b;

    /** @var Node */
    private Node $c;

    protected function setUp(): void
    {
        $this->a = new Node('a', 8108);
        $this->b = new Node('b', 8108);
        $this->c = new Node('c', 8108, 'https');
    }

    private static function key(Node $node): string
    {
        return 'dmlab_typesense_node_down_' . sha1($node->getBaseUrl());
    }

    /**
     * @param string[] $marked cache keys that hold a mark
     */
    private function registryWithMarks(array $marked): NodeHealthRegistry
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            static fn (string $key): string|false => in_array($key, $marked, true) ? '1' : false
        );

        return new NodeHealthRegistry($cache, new NullLogger());
    }

    public function testUnmarkedNodesKeepConfiguredOrder(): void
    {
        $registry = $this->registryWithMarks([]);

        $this->assertSame([$this->a, $this->b, $this->c], $registry->order([$this->a, $this->b, $this->c]));
    }

    public function testMarkedNodeIsMovedLast(): void
    {
        $registry = $this->registryWithMarks([self::key($this->a)]);

        $this->assertSame([$this->b, $this->c, $this->a], $registry->order([$this->a, $this->b, $this->c]));
    }

    public function testAllMarkedReturnsAllInConfiguredOrder(): void
    {
        $registry = $this->registryWithMarks([self::key($this->a), self::key($this->b)]);

        $this->assertSame([$this->a, $this->b], $registry->order(['x' => $this->a, 'y' => $this->b]));
    }

    public function testIsMarked(): void
    {
        $registry = $this->registryWithMarks([self::key($this->b)]);

        $this->assertFalse($registry->isMarked($this->a));
        $this->assertTrue($registry->isMarked($this->b));
    }

    public function testMarkDownSavesWithTtlAndTag(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('save')
            ->with('1', self::key($this->c), ['DMLAB_TYPESENSE'], 30)
            ->willReturn(true);

        (new NodeHealthRegistry($cache, new NullLogger()))->markDown($this->c);
    }

    public function testMarkUpRemovesAnExistingKey(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('1');
        $cache->expects($this->once())->method('remove')->with(self::key($this->a))->willReturn(true);

        (new NodeHealthRegistry($cache, new NullLogger()))->markUp($this->a);
    }

    public function testMarkUpDoesNotReadTheCache(): void
    {
        // The caller already knows the node was marked; a read here would cost the happy path a hop.
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('load');
        $cache->expects($this->once())->method('remove')->with(self::key($this->a))->willReturn(true);

        (new NodeHealthRegistry($cache, new NullLogger()))->markUp($this->a);
    }

    public function testMarksReadsEachNodeOnceAndReturnsTheMarkedBaseUrls(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->exactly(3))
            ->method('load')
            ->willReturnCallback(fn (string $key): string|false => $key === self::key($this->c) ? '1' : false);

        $marks = (new NodeHealthRegistry($cache, new NullLogger()))->marks([$this->a, $this->b, $this->c]);

        $this->assertSame(['https://c:8108' => true], $marks);
    }

    public function testOrderWithGivenMarksDoesNotReadTheCache(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('load');
        $registry = new NodeHealthRegistry($cache, new NullLogger());

        $this->assertSame(
            [$this->b, $this->a],
            $registry->order([$this->a, $this->b], ['http://a:8108' => true])
        );
    }

    public function testThrowingLoadIsLoggedAndTreatedAsUnmarked(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willThrowException(new \RuntimeException('valkey down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('valkey down'));
        $registry = new NodeHealthRegistry($cache, $logger);

        $this->assertSame([$this->a, $this->b], $registry->order([$this->a, $this->b]));
        $this->assertFalse($registry->isMarked($this->a));
    }

    public function testThrowingSaveNeverPropagates(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('save')->willThrowException(new \RuntimeException('valkey down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('write failed'));

        (new NodeHealthRegistry($cache, $logger))->markDown($this->a);
    }

    public function testThrowingRemoveNeverPropagates(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn('1');
        $cache->method('remove')->willThrowException(new \RuntimeException('valkey down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('remove failed'));

        (new NodeHealthRegistry($cache, $logger))->markUp($this->a);
    }
}
