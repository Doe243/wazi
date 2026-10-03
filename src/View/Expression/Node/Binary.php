<?php

declare(strict_types=1);

namespace Wazi\View\Expression\Node;

use Wazi\View\Expression\Node;

/**
 * Un opérateur entre deux valeurs : a + b, a == b, a and b, a ?? b.
 */
final readonly class Binary implements Node
{
    public function __construct(public string $operator, public Node $left, public Node $right, private int $position) {}

    public function position(): int
    {
        return $this->position;
    }
}
