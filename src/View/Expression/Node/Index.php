<?php

declare(strict_types=1);

namespace Wazi\View\Expression\Node;

use Wazi\View\Expression\Node;

/**
 * L'accès à un élément par une clé calculée : notes[0], prix[devise].
 */
final readonly class Index implements Node
{
    public function __construct(public Node $target, public Node $key, private int $position) {}

    public function position(): int
    {
        return $this->position;
    }
}
