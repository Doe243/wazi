<?php

declare(strict_types=1);

namespace Wazi\Tests\Container\Fixtures;

final class UnionDependency
{
    public function __construct(public readonly Clock|FakeMailer $dependency) {}
}
