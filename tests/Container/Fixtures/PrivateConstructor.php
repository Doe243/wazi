<?php

declare(strict_types=1);

namespace Wazi\Tests\Container\Fixtures;

final class PrivateConstructor
{
    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }
}
