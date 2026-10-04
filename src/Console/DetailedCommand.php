<?php

declare(strict_types=1);

namespace Wazi\Console;

/**
 * Une commande qui s'explique davantage quand on tape « wazi nom --help ».
 *
 * Une commande ordinaire (Command) donne une phrase de description. Celle-ci
 * ajoute un texte d'aide et des exemples, affichés par la console :
 *
 *     Description :
 *       Lance le site sur votre ordinateur, pour développer.
 *
 *     Utilisation :
 *       wazi serve [--options]
 *
 *     Exemples :
 *       wazi serve --port=8080   Le site, sur un autre port
 *
 *     Aide :
 *       Le serveur n'écoute que votre ordinateur...
 *
 * C'est facultatif : une commande qui n'implémente que Command fonctionne
 * exactement pareil, avec une aide plus courte.
 */
interface DetailedCommand extends Command
{
    /**
     * Quelques phrases : ce que fait la commande, quand s'en servir, ce
     * qu'elle ne fait pas. Les retours à la ligne sont gardés.
     */
    public function help(): string;

    /**
     * Des lignes à recopier, chacune avec ce qu'elle fait.
     * Ce qui suit le nom de la commande seulement : la console écrit le début.
     *
     * @return array<string, string> ce qu'on tape après le nom de la commande => ce que cela fait
     */
    public function examples(): array;
}
