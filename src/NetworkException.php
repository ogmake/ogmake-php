<?php

declare(strict_types=1);

namespace Ogmake;

class NetworkException extends OgmakeException
{
    public function __construct(string $message)
    {
        parent::__construct("ogmake network error: {$message}");
    }
}
