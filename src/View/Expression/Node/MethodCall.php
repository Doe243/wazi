<?php

declare(strict_types=1);

namespace Wazi\View\Expression\Node;

use Wazi\View\Expression\Node;

/**
 * L'appel d'une méthode publique d'un objet : note.resume(80).
 */
final readonly class MethodCall implements Node
{
    /**
     * @param list<Node> $arguments
     */
    public function __construct(public Node $target, public string $name, public array $arguments, private int $position) {}

    public function position(): int
    {
        return $this->position;
    }
}
