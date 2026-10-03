<?php

declare(strict_types=1);

namespace Wazi\Errors;

/**
 * L'endroit où le détail d'une erreur est consigné, à l'abri des visiteurs.
 *
 * Une interface plutôt qu'une classe, pour pouvoir écrire ailleurs que dans
 * le journal de PHP (un fichier de l'application, par exemple) sans toucher
 * au gestionnaire d'erreurs.
 */
interface ErrorLog
{
    /**
     * @param string $entry le compte rendu d'une erreur, sur plusieurs lignes, déjà nettoyé
     */
    public function write(string $entry): void;
}
