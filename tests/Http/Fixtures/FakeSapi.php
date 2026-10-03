<?php

declare(strict_types=1);

namespace Wazi\Tests\Http\Fixtures;

/**
 * Remplace, le temps d'un test, ce que PHP fait d'une réponse.
 *
 * En ligne de commande, PHP n'envoie aucun en-tête : header() ne laisse aucune
 * trace, et headers_sent() répond « oui » dès que PHPUnit a affiché quelque
 * chose. Pour tester ResponseEmitter, le fichier sapi_functions.php définit
 * donc des fonctions header() et headers_sent() dans le namespace Wazi\Http,
 * que PHP préfère aux fonctions natives, et qui notent tout ici.
 */
final class FakeSapi
{
    /** @var list<array{string, bool, int}> Chaque appel à header() : texte, remplacement, code de statut. */
    public static array $headers = [];

    /** @var array{string, int}|null Le fichier et la ligne où la page aurait déjà commencé, ou null. */
    public static ?array $sentAt = null;

    public static function reset(): void
    {
        self::$headers = [];
        self::$sentAt = null;
    }

    /**
     * @return list<string>
     */
    public static function headerLines(): array
    {
        return array_column(self::$headers, 0);
    }
}
