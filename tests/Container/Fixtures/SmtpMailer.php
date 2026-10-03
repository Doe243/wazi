<?php

declare(strict_types=1);

namespace Wazi\Tests\Container\Fixtures;

final class SmtpMailer implements Mailer
{
    public function __construct(public readonly string $host) {}
}
