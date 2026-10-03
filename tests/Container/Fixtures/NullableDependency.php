<?php

declare(strict_types=1);

namespace Wazi\Tests\Container\Fixtures;

final class NullableDependency
{
    public function __construct(public readonly ?Mailer $mailer) {}
}
