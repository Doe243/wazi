<?php

declare(strict_types=1);

namespace Wazi\Tests\Routing\Fixtures;

use Wazi\Http\Response;

/**
 * Un contrôleur qui répond à n'importe quel nom de méthode par __call :
 * Wazi ne doit pas s'en servir.
 */
final class MagicController
{
    /**
     * @param array<array-key, mixed> $arguments
     */
    public function __call(string $name, array $arguments): Response
    {
        return new Response(200, [], 'magie : ' . $name);
    }

    private function prive(): Response
    {
        return new Response(200, [], 'ne doit jamais être atteint');
    }

    public function appellePrive(): Response
    {
        return $this->prive();
    }
}
