<?php

declare(strict_types=1);

namespace Ogmake;

/** The API answered with a non-2xx status. `$errorCode` is the API's `error` field. */
class ApiException extends OgmakeException
{
    /**
     * @param list<array{field: string, message: string}>|null $detail
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly ?array $detail = null,
    ) {
        parent::__construct("ogmake API error: {$errorCode} (HTTP {$status})");
    }
}
