<?php

declare(strict_types=1);

namespace Wazi\View\Template;

/**
 * Une balise entièrement fixe : ni elle ni ce qu'elle contient ne dépend d'une valeur.
 *
 *     <svg class="icone"><use href="/icones.svg#loupe"></use></svg>
 *
 * Son HTML est écrit une fois pour toutes à la lecture du template. Au
 * moment d'afficher la page, il n'y a plus rien à calculer : on le recopie.
 * Dans une boucle de cent tours, c'est cent calculs évités.
 */
final readonly class StaticElement implements TemplateNode
{
    public function __construct(public string $html) {}
}
