<?php

declare(strict_types=1);

namespace Wazi\Tests\Routing\Fixtures;

/**
 * Un service ordinaire, dont un contrôleur a besoin.
 */
final class Greeter
{
    public function greet(string $name): string
    {
        return 'Bonjour ' . $name;
    }
}
