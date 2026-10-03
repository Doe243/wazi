<?php

declare(strict_types=1);

namespace Wazi\View\Template;

use Wazi\View\Expression\Node;

/**
 * Ce que dit un attribut k:for="cle, note in notes" : quoi parcourir, et sous
 * quel nom chaque élément est disponible à l'intérieur de la balise.
 */
final readonly class Loop
{
    /**
     * @param string      $item le nom donné à chaque élément (« note »)
     * @param string|null $key  le nom donné à sa clé ou à son numéro (« cle »), ou null
     * @param Node        $list l'expression qui donne la liste à parcourir (« notes »)
     */
    public function __construct(public string $item, public ?string $key, public Node $list) {}
}
