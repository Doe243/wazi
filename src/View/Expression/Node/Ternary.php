<?php

declare(strict_types=1);

namespace Wazi\View\Expression\Node;

use Wazi\View\Expression\Node;

/**
 * Un choix : condition ? siVrai : siFaux.
 */
final readonly class Ternary implements Node
{
    public function __construct(public Node $condition, public Node $then, public Node $else, private int $position) {}

    public function position(): int
    {
        return $this->position;
    }
}
