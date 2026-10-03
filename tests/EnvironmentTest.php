<?php

declare(strict_types=1);

namespace Wazi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Premier test du projet : il vérifie que l'environnement respecte
 * l'ADR-002 (PHP 8.3 minimum). Il sera rejoint par les vrais tests
 * dès l'écriture du composant Http.
 */
final class EnvironmentTest extends TestCase
{
    public function testPhpVersionIsAtLeast83(): void
    {
        self::assertTrue(
            version_compare(PHP_VERSION, '8.3.0', '>='),
            'Wazi nécessite PHP 8.3 ou plus récent.',
        );
    }
}
