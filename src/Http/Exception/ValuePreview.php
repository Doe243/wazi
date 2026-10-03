<?php

declare(strict_types=1);

namespace Wazi\Http\Exception;

/**
 * Prépare une valeur reçue de l'extérieur avant de l'insérer dans un message d'erreur.
 *
 * Sécurité (ADR-006) : les caractères de contrôle sont remplacés, pour qu'une
 * valeur malveillante ne puisse pas falsifier les journaux (en y glissant un
 * retour à la ligne suivi d'une fausse entrée), et la longueur est limitée.
 * L'affichage HTML d'un message doit, lui, toujours être échappé.
 *
 * @internal réservé aux exceptions de Wazi
 */
final class ValuePreview
{
    private const int MAX_LENGTH = 80;

    public static function of(string $value): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '?', $value) ?? '';

        return strlen($clean) > self::MAX_LENGTH
            ? substr($clean, 0, self::MAX_LENGTH - 3) . '...'
            : $clean;
    }
}
