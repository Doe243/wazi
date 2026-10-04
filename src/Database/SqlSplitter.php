<?php

declare(strict_types=1);

namespace Wazi\Database;

/**
 * Lit un texte SQL juste assez pour savoir où un caractère « compte ».
 *
 * Un « ; » ne sépare deux requêtes, et un « ? » n'attend une valeur, que
 * s'ils sont écrits « à nu ». Ils ne comptent pas quand ils se trouvent :
 *   - dans un texte entre apostrophes :   'a;b'
 *   - dans un nom entre guillemets :      "a;b"   `a;b`
 *   - dans un commentaire :               -- a;b      (ou entre barre-étoile et étoile-barre)
 *   - dans un texte entre dollars (PostgreSQL) :   $$ a;b $$
 *
 * Trois usages :
 *   - un fichier de migration contient plusieurs requêtes, exécutées une à une ;
 *   - Database refuse une requête qui en cache une seconde (ADR-032) ;
 *   - Database compte les marqueurs d'une requête, là où la base ne le fait pas.
 *
 * Cette lecture ne comprend pas le SQL. Le corps d'un déclencheur ou d'une
 * procédure, qui contient des « ; » à nu, serait coupé en morceaux.
 *
 * @internal réservé aux classes de Wazi\Database
 */
final class SqlSplitter
{
    private const int CODE = 0;
    private const int SEPARATOR = 1;
    private const int QUOTED = 2;
    private const int COMMENT = 3;

    /**
     * Les requêtes d'un texte SQL, découpé sur les « ; » à nu.
     *
     * @param bool $backslashEscapes true pour MySQL, où « \' » écrit une apostrophe dans un texte
     *
     * @return list<string> les requêtes, sans leur « ; » final ; celles qui ne contiennent que des commentaires sont omises
     */
    public static function split(string $sql, bool $backslashEscapes = false): array
    {
        $statements = [];
        $current = '';
        // Vrai dès que la requête en cours contient autre chose que des espaces et des commentaires.
        $hasContent = false;

        foreach (self::pieces($sql, $backslashEscapes) as [$kind, $text]) {
            if ($kind === self::SEPARATOR) {
                if ($hasContent) {
                    $statements[] = trim($current);
                }

                $current = '';
                $hasContent = false;

                continue;
            }

            $current .= $text;
            $hasContent = $hasContent || $kind === self::QUOTED || ($kind === self::CODE && trim($text) !== '');
        }

        if ($hasContent) {
            $statements[] = trim($current);
        }

        return $statements;
    }

    /**
     * Le SQL « à nu » : sans ses textes, ses noms entre guillemets ni ses commentaires.
     * Ce qui reste est ce que la base lit comme du langage.
     */
    public static function bare(string $sql, bool $backslashEscapes = false): string
    {
        $bare = '';

        foreach (self::pieces($sql, $backslashEscapes) as [$kind, $text]) {
            // Un espace à la place de ce qui est retiré : deux mots ne se collent pas.
            $bare .= $kind === self::CODE || $kind === self::SEPARATOR ? $text : ' ';
        }

        return $bare;
    }

    /**
     * Le texte SQL, morceau par morceau, chacun avec sa nature.
     *
     * @return list<array{self::CODE|self::SEPARATOR|self::QUOTED|self::COMMENT, string}>
     */
    private static function pieces(string $sql, bool $backslashEscapes): array
    {
        $pieces = [];
        $length = strlen($sql);
        $position = 0;

        while ($position < $length) {
            // On saute d'un coup jusqu'au prochain caractère qui peut changer quelque chose.
            $plain = strcspn($sql, ';\'"`-/$', $position);

            if ($plain > 0) {
                $pieces[] = [self::CODE, substr($sql, $position, $plain)];
                $position += $plain;

                continue;
            }

            $character = $sql[$position];
            $next = $sql[$position + 1] ?? '';

            // Ce qui commence ici, et où cela finit.
            [$kind, $end] = match (true) {
                $character === ';' => [self::SEPARATOR, $position + 1],
                $character === '\'' => [self::QUOTED, self::endOfQuoted($sql, $position, '\'', $backslashEscapes)],
                $character === '"' => [self::QUOTED, self::endOfQuoted($sql, $position, '"', $backslashEscapes)],
                $character === '`' => [self::QUOTED, self::endOfQuoted($sql, $position, '`', false)],
                $character === '-' && $next === '-' => [self::COMMENT, self::endOfLine($sql, $position)],
                $character === '/' && $next === '*' => [self::COMMENT, self::endOfBlockComment($sql, $position)],
                $character === '$' => self::dollarQuoted($sql, $position),
                // Un « - » ou un « / » seul : une soustraction, une division.
                default => [self::CODE, $position + 1],
            };

            $pieces[] = [$kind, substr($sql, $position, $end - $position)];
            $position = $end;
        }

        return $pieces;
    }

    /**
     * La position qui suit la fin d'un texte ou d'un nom entre guillemets.
     * Un guillemet doublé (« '' ») écrit un guillemet : il ne ferme rien.
     */
    private static function endOfQuoted(string $sql, int $start, string $quote, bool $backslashEscapes): int
    {
        $length = strlen($sql);
        $position = $start + 1;

        while ($position < $length) {
            $character = $sql[$position];

            if ($backslashEscapes && $character === '\\') {
                $position += 2;

                continue;
            }

            if ($character === $quote) {
                if (($sql[$position + 1] ?? '') === $quote) {
                    $position += 2;

                    continue;
                }

                return $position + 1;
            }

            ++$position;
        }

        // Guillemet jamais fermé : tout le reste en fait partie. La base le dira.
        return $length;
    }

    private static function endOfLine(string $sql, int $start): int
    {
        $end = strpos($sql, "\n", $start);

        return $end === false ? strlen($sql) : $end + 1;
    }

    private static function endOfBlockComment(string $sql, int $start): int
    {
        $end = strpos($sql, '*/', $start + 2);

        return $end === false ? strlen($sql) : $end + 2;
    }

    /**
     * PostgreSQL : un texte peut s'écrire entre « $$ » ou entre « $nom$ ».
     * Un « $ » qui n'ouvre pas un tel texte (« $1 ») est un caractère ordinaire.
     *
     * @return array{self::CODE|self::QUOTED, int} la nature du morceau, et la position qui le suit
     */
    private static function dollarQuoted(string $sql, int $start): array
    {
        if (preg_match('/\G\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $match, 0, $start) !== 1) {
            return [self::CODE, $start + 1];
        }

        $tag = $match[0];
        $end = strpos($sql, $tag, $start + strlen($tag));

        return [self::QUOTED, $end === false ? strlen($sql) : $end + strlen($tag)];
    }
}
