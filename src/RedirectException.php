<?php

declare(strict_types=1);

namespace Ogmake;

/** Redirects are never followed (a Bearer key must not be replayed elsewhere). */
class RedirectException extends OgmakeException
{
    public function __construct(public readonly int $status, public readonly ?string $location)
    {
        parent::__construct("ogmake API returned a redirect (HTTP {$status}); the SDK never follows redirects");
    }
}
