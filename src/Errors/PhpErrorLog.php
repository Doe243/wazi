<?php

declare(strict_types=1);

namespace Wazi\Errors;

/**
 * Écrit dans le journal d'erreurs de PHP, avec error_log().
 *
 * C'est le choix par défaut, car il ne demande aucune configuration : avec le
 * serveur de développement (php -S), le compte rendu s'affiche dans le
 * terminal ; en production, il va dans le fichier indiqué par le réglage
 * error_log du php.ini, ou dans le journal du serveur web.
 */
final class PhpErrorLog implements ErrorLog
{
    public function write(string $entry): void
    {
        error_log($entry);
    }
}
