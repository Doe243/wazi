<?php

declare(strict_types=1);

namespace Wazi\Http;

/**
 * Reconnaît un chemin de fichier ordinaire, par opposition à une adresse à protocole.
 *
 * Sécurité (ADR-006) : les fonctions de fichiers de PHP (fopen, rename...)
 * acceptent bien plus que des chemins. « php://filter/... » lit n'importe quel
 * fichier en le transformant, « phar://... » peut exécuter du code, « http://... »
 * fait partir une requête depuis le serveur. Wazi ne passe à ces fonctions que
 * des chemins vérifiés ici.
 *
 * @internal réservé aux classes de Wazi\Http
 */
final class LocalPath
{
    /** Un protocole suivi de « :// ». Une lettre seule désigne un lecteur Windows (C:) : elle est permise. */
    private const string WRAPPER = '#^[a-zA-Z][a-zA-Z0-9+.\-]+://#';

    private const string CONTROL_CHARACTER = '/[\x00-\x1F\x7F]/';

    public static function isPlain(string $path): bool
    {
        return $path !== ''
            && preg_match(self::WRAPPER, $path) !== 1
            && preg_match(self::CONTROL_CHARACTER, $path) !== 1;
    }
}
