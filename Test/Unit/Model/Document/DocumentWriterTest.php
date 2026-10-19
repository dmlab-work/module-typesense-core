<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Unit\Model\Document;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseCore\Model\Document\DocumentWriter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DocumentWriterTest extends TestCase
{
    private const OPERATION_TIMEOUT = 300;

    /** @var TypesenseClient|MockObject */
    private MockObject $client;

    /** @var DocumentWriter */
    private DocumentWriter $writer;

    protected function setUp(): void
    {
        $this->client = $this->createMock(TypesenseClient::class);
        $config = $this->createMock(ConnectionSettingsInterface::class);
        $config->method('getOperationTimeout')->willReturn(self::OPERATION_TIMEOUT);
        $this->writer = new DocumentWriter($this->client, $config);
    }

    public function testUpsertPostsTheDocumentWithTheUpsertAction(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('POST', '/collections/products/documents', ['id' => '1', 'sku' => 'ABC'], ['action' => 'upsert'])
            ->willReturn(['id' => '1', 'sku' => 'ABC']);

        $document = ['id' => '1', 'sku' => 'ABC'];

        $this->assertSame($document, $this->writer->upsert('products', $document));
    }

    public function testUpsertIsSentReplaySafe(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('POST', '/collections/products/documents', ['id' => '1'], ['action' => 'upsert'], null, null, true)
            ->willReturn([]);

        $this->writer->upsert('products', ['id' => '1']);
    }

    /**
     * @return array<string,array{0:string,1:bool|null}>
     */
    public static function importReplaySafetyProvider(): array
    {
        return [
            'upsert' => [DocumentWriter::ACTION_UPSERT, true],
            'update' => [DocumentWriter::ACTION_UPDATE, true],
            'emplace' => [DocumentWriter::ACTION_EMPLACE, true],
            'create decided by method' => [DocumentWriter::ACTION_CREATE, null],
        ];
    }

    #[DataProvider('importReplaySafetyProvider')]
    public function testImportReplaySafetyFollowsTheAction(string $action, ?bool $replaySafe): void
    {
        $this->client->expects($this->once())
            ->method('requestRaw')
            ->with(
                'POST',
                '/collections/products/documents/import',
                '{"id":"1"}',
                'text/plain',
                ['action' => $action],
                self::OPERATION_TIMEOUT,
                null,
                $replaySafe
            )
            ->willReturn('{"success":true}');

        $this->writer->importBatch('products', [['id' => '1']], $action);
    }

    #[DataProvider('documentsWithoutAStableIdProvider')]
    public function testUpsertWithoutAnIdIsLeftToTheMethodDefault(array $document): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('POST', '/collections/products/documents', $document, ['action' => 'upsert'], null, null, null)
            ->willReturn([]);

        $this->writer->upsert('products', $document);
    }

    /**
     * @return array<string,array{array<string,mixed>}>
     */
    public static function documentsWithoutAStableIdProvider(): array
    {
        return [
            'no id' => [['sku' => 'ABC']],
            'empty id' => [['id' => '', 'sku' => 'ABC']],
            'non-string id' => [['id' => 1, 'sku' => 'ABC']],
        ];
    }

    public function testImportWithADocumentMissingItsIdIsNotReplayed(): void
    {
        $this->client->expects($this->once())
            ->method('requestRaw')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                null,
                null
            )
            ->willReturn("{\"success\":true}\n{\"success\":true}");

        $this->writer->importBatch('products', [['id' => '1'], ['sku' => 'ABC']], DocumentWriter::ACTION_EMPLACE);
    }

    public function testImportIdCheckIgnoresDocumentsLeftOutAsUnencodable(): void
    {
        $this->client->expects($this->once())
            ->method('requestRaw')
            ->with(
                $this->anything(),
                $this->anything(),
                '{"id":"1"}',
                $this->anything(),
                $this->anything(),
                $this->anything(),
                null,
                true
            )
            ->willReturn('{"success":true}');

        $this->writer->importBatch('products', [['id' => '1'], ['price' => INF]]);
    }

    public function testUpsertEncodesTheCollectionIntoThePath(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('POST', '/collections/products%20eu/documents', $this->anything(), $this->anything())
            ->willReturn([]);

        $this->writer->upsert('products eu', ['id' => '1']);
    }

    public function testUpsertFailureSurfaces(): void
    {
        $this->client->method('request')->willThrowException(new TypesenseException('Not found.', 404));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionCode(404);

        $this->writer->upsert('missing', ['id' => '1']);
    }

    public function testDeleteRemovesOneDocumentById(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with('DELETE', '/collections/products/documents/sku%2F1')
            ->willReturn(['id' => 'sku/1']);

        $this->assertSame(['id' => 'sku/1'], $this->writer->delete('products', 'sku/1'));
    }

    public function testDeleteOnAMissingDocumentThrows(): void
    {
        $this->client->method('request')->willThrowException(new TypesenseException('Not found.', 404));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionCode(404);

        $this->writer->delete('products', '404');
    }

    public function testDeleteByPassesTheFilterUnretryablyAndReturnsTheDeletedCount(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with(
                'DELETE',
                '/collections/products/documents',
                null,
                ['filter_by' => 'store_id:=1'],
                self::OPERATION_TIMEOUT,
                null,
                false
            )
            ->willReturn(['num_deleted' => 42]);

        $this->assertSame(42, $this->writer->deleteBy('products', 'store_id:=1'));
    }

    public function testDeleteByIsZeroWhenTheEngineReportsNothing(): void
    {
        $this->client->method('request')->willReturn([]);

        $this->assertSame(0, $this->writer->deleteBy('products', 'store_id:=9'));
    }

    public function testImportAssemblesAJsonlBodyOneDocumentPerLine(): void
    {
        $this->client->expects($this->once())
            ->method('requestRaw')
            ->with(
                'POST',
                '/collections/products/documents/import',
                "{\"id\":\"1\"}\n{\"id\":\"2\"}",
                'text/plain',
                ['action' => 'upsert'],
                self::OPERATION_TIMEOUT
            )
            ->willReturn("{\"success\":true}\n{\"success\":true}");

        $result = $this->writer->importBatch('products', [['id' => '1'], ['id' => '2']]);

        $this->assertSame(2, $result->getSuccessCount());
        $this->assertSame(0, $result->getFailureCount());
        $this->assertFalse($result->hasFailures());
        $this->assertSame([], $result->getErrors());
    }

    public function testImportPassesTheRequestedAction(): void
    {
        $this->client->expects($this->once())
            ->method('requestRaw')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                ['action' => 'create'],
                self::OPERATION_TIMEOUT
            )
            ->willReturn('{"success":true}');

        $this->writer->importBatch('products', [['id' => '1']], DocumentWriter::ACTION_CREATE);
    }

    public function testImportSplitsIntoOneRequestPerBatch(): void
    {
        $bodies = [];

        $this->client->expects($this->exactly(2))
            ->method('requestRaw')
            ->willReturnCallback(function (string $method, string $path, string $body) use (&$bodies): string {
                $bodies[] = $body;

                return implode("\n", array_fill(0, substr_count($body, "\n") + 1, '{"success":true}'));
            });

        // N+1 documents with a batch size of N must not become one body.
        $result = $this->writer->importBatch('products', [['id' => '1'], ['id' => '2'], ['id' => '3']], 'upsert', 2);

        $this->assertSame(["{\"id\":\"1\"}\n{\"id\":\"2\"}", '{"id":"3"}'], $bodies);
        $this->assertSame(3, $result->getSuccessCount());
    }

    public function testImportConsumesAGeneratorLazilyOneBatchAtATime(): void
    {
        $produced = 0;
        $documents = (function () use (&$produced): \Generator {
            foreach (range(1, 4) as $id) {
                $produced++;
                yield ['id' => (string)$id];
            }
        })();

        $seenWhileImporting = [];
        $this->client->method('requestRaw')->willReturnCallback(
            function () use (&$produced, &$seenWhileImporting): string {
                $seenWhileImporting[] = $produced;

                return "{\"success\":true}\n{\"success\":true}";
            }
        );

        $this->writer->importBatch('products', $documents, 'upsert', 2);

        // The first request happens after 2 documents, not after all 4 were materialised.
        $this->assertSame([2, 4], $seenWhileImporting);
    }

    public function testImportOfNoDocumentsSendsNoRequest(): void
    {
        $this->client->expects($this->never())->method('requestRaw');

        $result = $this->writer->importBatch('products', []);

        $this->assertSame(0, $result->getTotalCount());
        $this->assertFalse($result->hasFailures());
    }

    public function testImportAggregatesPartialFailuresFromAMixedResponse(): void
    {
        $this->client->method('requestRaw')->willReturn(
            '{"success":true}' . "\n"
            . '{"success":false,"error":"Field `price` must be a float.","document":"{\"id\":\"2\"}"}' . "\n"
            . '{"success":true}'
        );

        $result = $this->writer->importBatch('products', [['id' => '1'], ['id' => '2'], ['id' => '3']]);

        $this->assertSame(2, $result->getSuccessCount());
        $this->assertSame(1, $result->getFailureCount());
        $this->assertSame(3, $result->getTotalCount());
        $this->assertTrue($result->hasFailures());
        $this->assertFalse($result->areErrorsTruncated());
        $this->assertSame(
            [['line' => 1, 'error' => 'Field `price` must be a float.', 'document' => '{"id":"2"}']],
            $result->getErrors()
        );
    }

    public function testImportNumbersFailedLinesAcrossBatches(): void
    {
        $this->client->method('requestRaw')->willReturnCallback(
            static function (string $method, string $path, string $body): string {
                return str_contains($body, '"4"')
                    ? '{"success":false,"error":"Bad."}' . "\n" . '{"success":true}'
                    : "{\"success\":true}\n{\"success\":true}\n{\"success\":true}";
            }
        );

        $documents = [['id' => '1'], ['id' => '2'], ['id' => '3'], ['id' => '4'], ['id' => '5']];
        $result = $this->writer->importBatch('products', $documents, 'upsert', 3);

        $this->assertSame(4, $result->getSuccessCount());
        $this->assertSame(1, $result->getFailureCount());
        // The failure is line 3 of the stream, not line 0 of its batch.
        $this->assertSame(3, $result->getErrors()[0]['line']);
        $this->assertSame(['id' => '4'], $result->getErrors()[0]['document']);
    }

    public function testImportCollectsOnlyTheFirstErrorsButCountsThemAll(): void
    {
        $documents = array_map(static fn (int $id): array => ['id' => (string)$id], range(1, 30));

        $this->client->method('requestRaw')->willReturn(
            implode("\n", array_fill(0, 30, '{"success":false,"error":"Bad."}'))
        );

        $result = $this->writer->importBatch('products', $documents, 'upsert', 30);

        $this->assertSame(30, $result->getFailureCount());
        $this->assertCount(20, $result->getErrors());
        $this->assertTrue($result->areErrorsTruncated());
    }

    public function testImportTreatsAnUnparsableResponseLineAsAFailure(): void
    {
        $this->client->method('requestRaw')->willReturn("{\"success\":true}\nnot json");

        $result = $this->writer->importBatch('products', [['id' => '1'], ['id' => '2']]);

        $this->assertSame(1, $result->getSuccessCount());
        $this->assertSame(1, $result->getFailureCount());
        $this->assertSame('Unparsable import response.', $result->getErrors()[0]['error']);
    }

    /**
     * Callers index the batch by line position, so a blank line must occupy its slot rather than
     * shift every later error onto the wrong document.
     */
    public function testImportKeepsABlankResponseLineAlignedWithItsDocument(): void
    {
        $this->client->method('requestRaw')
            ->willReturn("{\"success\":true}\n\n{\"success\":false,\"error\":\"Bad id\"}");

        $result = $this->writer->importBatch('products', [['id' => '1'], ['id' => '2'], ['id' => '3']]);

        $this->assertSame(1, $result->getSuccessCount());
        $this->assertSame(2, $result->getFailureCount());
        $this->assertSame(
            [
                ['line' => 1, 'error' => 'Unparsable import response.', 'document' => ['id' => '2']],
                ['line' => 2, 'error' => 'Bad id', 'document' => ['id' => '3']],
            ],
            $result->getErrors()
        );
    }

    public function testImportCountsDocumentsMissingFromAShortResponseAsFailures(): void
    {
        // One line back for three documents: the unaccounted two must not read as a clean import.
        $this->client->method('requestRaw')->willReturn('{"success":true}');

        $result = $this->writer->importBatch('products', [['id' => '1'], ['id' => '2'], ['id' => '3']]);

        $this->assertSame(1, $result->getSuccessCount());
        $this->assertSame(2, $result->getFailureCount());
        $this->assertTrue($result->hasFailures());
        $this->assertSame(
            [
                ['line' => 1, 'error' => 'The import response carried no result for this document.',
                    'document' => ['id' => '2']],
                ['line' => 2, 'error' => 'The import response carried no result for this document.',
                    'document' => ['id' => '3']],
            ],
            $result->getErrors()
        );
    }

    public function testImportIgnoresResponseLinesBeyondTheBatchLength(): void
    {
        // Four lines back for two documents: the surplus is not a result for anything we
        // sent, and must not inflate the totals past the batch.
        $this->client->method('requestRaw')->willReturn(
            "{\"success\":true}\n{\"success\":true}\n{\"success\":true}\n{\"success\":true}"
        );

        $result = $this->writer->importBatch('products', [['id' => '1'], ['id' => '2']]);

        $this->assertSame(2, $result->getSuccessCount());
        $this->assertSame(0, $result->getFailureCount());
        $this->assertSame(2, $result->getTotalCount());
    }

    public function testImportOfAnEmptyResponseFailsEveryDocumentRatherThanReportingSuccess(): void
    {
        $this->client->method('requestRaw')->willReturn('');

        $result = $this->writer->importBatch('products', [['id' => '1'], ['id' => '2']]);

        $this->assertSame(0, $result->getSuccessCount());
        $this->assertSame(2, $result->getFailureCount());
        $this->assertTrue($result->hasFailures());
    }

    public function testImportReportsAnUnencodableDocumentAndSendsTheRest(): void
    {
        $invalid = ['id' => '2', 'name' => "bad \xB1 utf8"];

        $this->client->expects($this->once())
            ->method('requestRaw')
            ->with($this->anything(), $this->anything(), "{\"id\":\"1\"}\n{\"id\":\"3\"}")
            ->willReturn("{\"success\":true}\n{\"success\":true}");

        $result = $this->writer->importBatch('products', [['id' => '1'], $invalid, ['id' => '3']]);

        $this->assertSame(2, $result->getSuccessCount());
        $this->assertSame(1, $result->getFailureCount());
        $this->assertSame(
            [[
                'line' => 1,
                'error' => 'Unable to encode the document: Malformed UTF-8 characters, possibly incorrectly encoded',
                'document' => $invalid,
            ]],
            $result->getErrors()
        );
    }

    public function testImportReportsANanValueAsAnEncodeFailure(): void
    {
        $this->client->method('requestRaw')->willReturn('{"success":true}');

        $result = $this->writer->importBatch('products', [['id' => '1', 'price' => NAN], ['id' => '2']]);

        $this->assertSame(1, $result->getSuccessCount());
        $this->assertSame(1, $result->getFailureCount());
        $this->assertSame(0, $result->getErrors()[0]['line']);
        $this->assertStringStartsWith('Unable to encode the document: Inf and NaN', $result->getErrors()[0]['error']);
    }

    public function testImportOfAWhollyUnencodableBatchSendsNoRequest(): void
    {
        $this->client->expects($this->never())->method('requestRaw');

        $result = $this->writer->importBatch('products', [['price' => INF], ['name' => "\xB1"]]);

        $this->assertSame(0, $result->getSuccessCount());
        $this->assertSame(2, $result->getFailureCount());
        $this->assertSame([0, 1], array_column($result->getErrors(), 'line'));
    }

    public function testImportKeepsStreamLinesAcrossBatchesWithEncodeFailures(): void
    {
        $bodies = [];
        $this->client->method('requestRaw')->willReturnCallback(
            static function (string $method, string $path, string $body) use (&$bodies): string {
                $bodies[] = $body;

                return str_contains($body, '"5"')
                    ? '{"success":false,"error":"Bad."}'
                    : '{"success":true}';
            }
        );

        $documents = [['id' => '1'], ['price' => NAN], ['price' => NAN], ['id' => '4'], ['id' => '5']];
        $result = $this->writer->importBatch('products', $documents, 'upsert', 2);

        $this->assertSame(['{"id":"1"}', '{"id":"4"}', '{"id":"5"}'], $bodies);
        $this->assertSame(2, $result->getSuccessCount());
        $this->assertSame(3, $result->getFailureCount());
        $this->assertSame([1, 2, 4], array_column($result->getErrors(), 'line'));
        $this->assertSame(['id' => '5'], $result->getErrors()[2]['document']);
    }

    public function testImportMapsAResponseFailureAfterASkippedDocumentToTheRightDocument(): void
    {
        // Response line 1 belongs to the third document: the second was never sent.
        $this->client->method('requestRaw')->willReturn(
            "{\"success\":true}\n{\"success\":false,\"error\":\"Bad id\"}\n{\"success\":true}"
        );

        $documents = [['id' => '1'], ['price' => NAN], ['id' => '3'], ['id' => '4']];
        $result = $this->writer->importBatch('products', $documents);

        $this->assertSame(2, $result->getSuccessCount());
        $this->assertSame(2, $result->getFailureCount());
        $this->assertSame([1, 2], array_column($result->getErrors(), 'line'));
        $this->assertSame('Bad id', $result->getErrors()[1]['error']);
        $this->assertSame(['id' => '3'], $result->getErrors()[1]['document']);
    }

    public function testImportCountsAShortResponseAgainstTheEncodedDocumentsOnly(): void
    {
        $this->client->method('requestRaw')->willReturn('{"success":true}');

        $result = $this->writer->importBatch('products', [['price' => NAN], ['id' => '2'], ['id' => '3']]);

        $this->assertSame(1, $result->getSuccessCount());
        $this->assertSame(2, $result->getFailureCount());
        $this->assertSame([0, 2], array_column($result->getErrors(), 'line'));
        $this->assertStringStartsWith('Unable to encode the document: ', $result->getErrors()[0]['error']);
        $this->assertSame(
            'The import response carried no result for this document.',
            $result->getErrors()[1]['error']
        );
    }

    public function testImportRejectsANonPositiveBatchSize(): void
    {
        $this->client->expects($this->never())->method('requestRaw');

        $this->expectException(TypesenseException::class);
        $this->expectExceptionMessage('batch size must be at least 1');

        $this->writer->importBatch('products', [['id' => '1']], 'upsert', 0);
    }

    public function testImportTransportFailureSurfaces(): void
    {
        $this->client->method('requestRaw')->willThrowException(new TypesenseException('Service unavailable.', 503));

        $this->expectException(TypesenseException::class);
        $this->expectExceptionCode(503);

        $this->writer->importBatch('products', [['id' => '1']]);
    }
}
