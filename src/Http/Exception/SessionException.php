<?php

declare(strict_types=1);

namespace Wazi\Http\Exception;

/**
 * Levée quand la session est mal utilisée. C'est une erreur dans votre code.
 *
 * Sécurité (ADR-006) : aucun message ne contient une valeur rangée en session.
 */
final class SessionException extends \LogicException
{
    public static function notStarted(): self
    {
        return new self(
            'La session n\'est pas démarrée : aucune requête n\'est passée par le middleware des sessions.'
            . ' Indiquez au noyau où ranger les sessions : new Kernel(sessions: __DIR__ . \'/../var/sessions\').',
        );
    }

    public static function unsupportedValue(string $key, string $givenType): self
    {
        return new self(sprintf(
            'La valeur donnée à la clé « %s » de la session est de type %s. Une session ne garde que des textes,'
            . ' des nombres, des vrai/faux, null, et des tableaux qui n\'en contiennent pas d\'autres. Pour retenir'
            . ' un objet (un utilisateur, par exemple), gardez son identifiant : $session->set(\'user_id\', $user->id).',
            ValuePreview::of($key),
            $givenType,
        ));
    }

    public static function reservedKey(string $key): self
    {
        return new self(sprintf(
            'La clé « %s » ne peut pas être utilisée : les clés de session qui commencent par « _ » sont réservées'
            . ' à Wazi (il y range le jeton de protection des formulaires). Choisissez un autre nom.',
            ValuePreview::of($key),
        ));
    }
}
