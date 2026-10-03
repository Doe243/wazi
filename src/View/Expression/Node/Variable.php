<?php

declare(strict_types=1);

namespace Wazi\View\Expression\Node;

use Wazi\View\Expression\Node;

/**
 * Une variable donnée au template : titre.
 */
final readonly class Variable implements Node
{
    public function __construct(public string $name, private int $position) {}

    public function position(): int
    {
        return $this->position;
    }
}
