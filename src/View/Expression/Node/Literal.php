<?php

declare(strict_types=1);

namespace Wazi\View\Expression\Node;

use Wazi\View\Expression\Node;

/**
 * Une valeur écrite telle quelle : 42, 'bonjour', true, null.
 */
final readonly class Literal implements Node
{
    public function __construct(public string|int|float|bool|null $value, private int $position) {}

    public function position(): int
    {
        return $this->position;
    }
}
