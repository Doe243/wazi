<?php

declare(strict_types=1);

namespace Wazi\View\Template;

use Wazi\View\Expression\Node;

/**
 * Un affichage : ce qui est écrit entre { et } dans un template.
 */
final readonly class Interpolation
{
    /**
     * @param Node $expression l'arbre de l'expression, prêt à être calculé
     * @param int  $line       la ligne du template, pour situer une erreur
     */
    public function __construct(public Node $expression, public int $line) {}
}
