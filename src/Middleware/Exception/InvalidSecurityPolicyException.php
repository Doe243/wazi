<?php

declare(strict_types=1);

namespace Wazi\Middleware\Exception;

/**
 * Levée quand les réglages donnés à SecurityHeaders sont invalides.
 *
 * Les valeurs citées dans ces messages sont celles que VOUS avez écrites dans
 * votre code, pas des valeurs venues d'une requête.
 */
final class InvalidSecurityPolicyException extends \InvalidArgumentException
{
    public static function invalidScriptSource(string $source): self
    {
        return new self(sprintf(
            '« %s » ne peut pas servir de source de scripts. Donnez l\'adresse du site qui héberge le script,'
            . ' en https et sans rien d\'autre : par exemple \'https://cdn.jsdelivr.net\'. Les mots-clés comme'
            . ' \'unsafe-inline\', les jokers (« * ») et les adresses en http sont refusés ici, car ils'
            . ' annuleraient la protection. Si vous en avez vraiment besoin, écrivez la politique complète'
            . ' vous-même : new SecurityHeaders(contentSecurityPolicy: \'...\').',
            preg_replace('/[\x00-\x1F\x7F]/', '?', $source) ?? '',
        ));
    }

    public static function scriptsWithCustomPolicy(): self
    {
        return new self(
            'SecurityHeaders a reçu à la fois des sources de scripts et une politique complète : la politique'
            . ' complète l\'emporterait et vos sources seraient ignorées sans que rien ne le dise.'
            . ' Gardez l\'un ou l\'autre : soit scripts: [...], soit contentSecurityPolicy: \'...\''
            . ' (en y écrivant vous-même la directive script-src).',
        );
    }
}
