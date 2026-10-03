<?php

declare(strict_types=1);

namespace Wazi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Premier test du projet : il vérifie que l'environnement respecte
 * l'ADR-004 (PHP 8.5 minimum) et que l'extension URI native,
 * sur laquelle s'appuie la classe Uri (ADR-005), est disponible.
 */
final class EnvironmentTest extends TestCase
{
    public function testPhpVersionIsAtLeast85(): void
    {
        self::assertTrue(
            version_compare(PHP_VERSION, '8.5.0', '>='),
            'Wazi nécessite PHP 8.5 ou plus récent.',
        );
    }

    public function testNativeUriExtensionIsAvailable(): void
    {
        self::assertTrue(
            class_exists('Uri\\Rfc3986\\Uri'),
            "La classe native Uri\\Rfc3986\\Uri est introuvable : l'extension URI de PHP 8.5 est requise.",
        );
    }
}
