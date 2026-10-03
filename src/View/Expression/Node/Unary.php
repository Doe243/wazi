<?php

declare(strict_types=1);

namespace Wazi\View\Expression\Node;

use Wazi\View\Expression\Node;

/**
 * Un opérateur devant une seule valeur : -prix, not visible.
 */
final readonly class Unary implements Node
{
    public function __construct(public string $operator, public Node $operand, private int $position) {}

    public function position(): int
    {
        return $this->position;
    }
}
