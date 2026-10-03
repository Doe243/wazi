<?php

declare(strict_types=1);

namespace Wazi\View\Expression\Node;

use Wazi\View\Expression\Node;

/**
 * L'accès à une clé de tableau ou à une propriété publique : note.texte.
 */
final readonly class Property implements Node
{
    public function __construct(public Node $target, public string $name, private int $position) {}

    public function position(): int
    {
        return $this->position;
    }
}
