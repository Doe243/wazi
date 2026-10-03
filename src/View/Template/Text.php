<?php

declare(strict_types=1);

namespace Wazi\View\Template;

/**
 * Du texte entre deux balises, découpé en morceaux : du texte fixe, recopié
 * tel quel, et des affichages {…}, calculés puis échappés.
 */
final readonly class Text implements TemplateNode
{
    /**
     * @param list<string|Interpolation> $parts
     */
    public function __construct(public array $parts) {}
}
