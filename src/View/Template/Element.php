<?php

declare(strict_types=1);

namespace Wazi\View\Template;

/**
 * Une balise, ses attributs et ce qu'elle contient.
 */
final readonly class Element implements TemplateNode
{
    /**
     * @param list<Attribute>    $attributes
     * @param list<TemplateNode> $children
     * @param bool               $selfClosing vrai si la balise est écrite <br /> ou <k:include />
     * @param bool               $closed      vrai si une balise fermante </nom> était écrite : on la réécrit alors à la sortie
     */
    public function __construct(
        public string $name,
        public array $attributes,
        public array $children,
        public bool $selfClosing,
        public bool $closed,
        public int $line,
    ) {}
}
