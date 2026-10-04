<?php

declare(strict_types=1);

namespace Wazi\View\Expression;

use Wazi\View\Exception\KiooException;

/**
 * Découpe le texte d'une expression en « mots » (des jetons).
 *
 *     note.prix * 2 >= 10
 *
 * devient la liste :
 *
 *     nom(note)  .  nom(prix)  *  nombre(2)  >=  nombre(10)  fin
 *
 * C'est la première étape : l'analyseur (Parser) n'a plus à se soucier des
 * espaces ni des guillemets, il ne voit que des jetons.
 *
 * Chaque jeton est un tableau : son type, sa valeur, et sa position dans le
 * texte (pour situer une erreur).
 */
final class Lexer
{
    public const string NUMBER = 'nombre';
    public const string STRING = 'texte';
    public const string NAME = 'nom';
    public const string OPERATOR = 'opérateur';
    public const string END = 'fin';

    /**
     * Ce qui peut commencer à un endroit de l'expression, en une seule recherche :
     *   1. des espaces ;
     *   2. un nombre : 42 ou 3.14 (le point n'en fait partie que s'il est suivi d'un chiffre) ;
     *   3. un nom : une variable, une propriété, un filtre, ou un mot du langage (and, true...) ;
     *   4. un opérateur, les plus longs d'abord : « >= » doit être reconnu avant « > ».
     *
     * « \G » ancre la recherche à la position demandée. L'expression est
     * écrite ici, une fois pour toutes : rien n'y vient d'un template.
     */
    private const string TOKEN = '/\G(?:(\s+)|(\d+(?:\.\d+)?)|([a-zA-Z_][a-zA-Z0-9_]*)|(==|!=|<=|>=|\?\?|[<>+\-*\/%()\[\].,?:|]))/';

    /** Dans un texte entre guillemets, « \n » désigne un retour à la ligne, etc. */
    private const array ESCAPES = ['n' => "\n", 't' => "\t", '\\' => '\\', "'" => "'", '"' => '"'];

    /**
     * @return list<array{type: string, value: string|int|float, position: int}>
     *
     * @throws KiooException si l'expression contient un caractère inconnu ou un texte non refermé
     */
    public function tokenize(string $expression): array
    {
        $tokens = [];
        $position = 0;
        $length = strlen($expression);

        while ($position < $length) {
            $character = $expression[$position];

            if ($character === "'" || $character === '"') {
                [$text, $end] = $this->readString($expression, $position);
                $tokens[] = ['type' => self::STRING, 'value' => $text, 'position' => $position];
                $position = $end;

                continue;
            }

            if (preg_match(self::TOKEN, $expression, $match, PREG_UNMATCHED_AS_NULL, $position) !== 1) {
                throw KiooException::unexpectedCharacter($expression, $position);
            }

            // Un seul des quatre groupes a trouvé quelque chose.
            if (isset($match[2])) {
                $tokens[] = [
                    'type' => self::NUMBER,
                    'value' => str_contains($match[2], '.') ? (float) $match[2] : (int) $match[2],
                    'position' => $position,
                ];
            } elseif (isset($match[3])) {
                $tokens[] = ['type' => self::NAME, 'value' => $match[3], 'position' => $position];
            } elseif (isset($match[4])) {
                $tokens[] = ['type' => self::OPERATOR, 'value' => $match[4], 'position' => $position];
            }

            $position += strlen($match[0]);
        }

        $tokens[] = ['type' => self::END, 'value' => '', 'position' => $length];

        return $tokens;
    }

    /**
     * Lit un texte entre guillemets à partir de son guillemet ouvrant.
     *
     * @return array{string, int} le texte, puis la position juste après le guillemet fermant
     */
    private function readString(string $expression, int $start): array
    {
        $quote = $expression[$start];
        $text = '';
        $position = $start + 1;
        $length = strlen($expression);

        while ($position < $length) {
            $character = $expression[$position];

            if ($character === $quote) {
                return [$text, $position + 1];
            }

            // Une barre inversée change le sens du caractère suivant.
            if ($character === '\\' && $position + 1 < $length) {
                $next = $expression[$position + 1];
                $text .= self::ESCAPES[$next] ?? '\\' . $next;
                $position += 2;

                continue;
            }

            $text .= $character;
            $position++;
        }

        throw KiooException::unterminatedString($expression, $start);
    }

}
