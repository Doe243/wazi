<?php

declare(strict_types=1);

namespace Wazi\Tests\Container\Fixtures;

final class WithDefaults
{
    public function __construct(
        public readonly Clock $clock,
        public readonly int $limit = 10,
        public readonly ?Mailer $mailer = null,
    ) {}
}
