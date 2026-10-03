<?php

declare(strict_types=1);

namespace Wazi\Tests\Container\Fixtures;

final class NeedsText
{
    public function __construct(public readonly string $dsn) {}
}
