<?php

declare(strict_types=1);

namespace Wazi\View\Expression\Node;

use Wazi\View\Expression\Node;

/**
 * Un filtre appliqué à une valeur : texte | upper, prix | number(2).
 */
final readonly class Filter implements Node
{
    /**
     * @param list<Node> $arguments
     */
    public function __construct(public Node $input, public string $name, public array $arguments, private int $position) {}

    public function position(): int
    {
        return $this->position;
    }
}
