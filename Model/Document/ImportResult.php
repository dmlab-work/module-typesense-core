<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Document;

/**
 * The outcome of an import, aggregated across every batch it took.
 *
 * An import is partially fallible: the request succeeds while individual documents fail,
 * so a caller needs counts and a sample of errors rather than an exception.
 *
 * @api
 */
class ImportResult
{
    /**
     * @param int $successCount
     * @param int $failureCount
     * @param array<int,array{line:int,error:string,document:mixed}> $errors first N failures only
     */
    public function __construct(
        private readonly int $successCount,
        private readonly int $failureCount,
        private readonly array $errors
    ) {
    }

    /**
     * Documents the engine accepted.
     */
    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    /**
     * Documents the engine rejected.
     */
    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    /**
     * Documents sent, accepted or not.
     */
    public function getTotalCount(): int
    {
        return $this->successCount + $this->failureCount;
    }

    /**
     * Whether anything was rejected.
     */
    public function hasFailures(): bool
    {
        return $this->failureCount > 0;
    }

    /**
     * The first N failures, each with its zero-based position in the document stream.
     *
     * @return array<int,array{line:int,error:string,document:mixed}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Whether more failures occurred than are listed by `getErrors()`.
     */
    public function areErrorsTruncated(): bool
    {
        return $this->failureCount > count($this->errors);
    }
}
