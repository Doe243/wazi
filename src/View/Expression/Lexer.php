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

    /** Les opérateurs, du plus long au plus court : « >= » doit être reconnu avant « > ». */
    private const array OPERATORS = ['==', '!=', '<=', '>=', '??', '<', '>', '+', '-', '*', '/', '%', '(', ')', '[', ']', '.', ',', '?', ':', '|'];

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

            if (ctype_space($character)) {
                $position++;

                continue;
            }

            // Un nombre : 42 ou 3.14. Le point n'en fait partie que s'il est suivi d'un chiffre.
            if (preg_match('/\G\d+(?:\.\d+)?/', $expression, $match, 0, $position) === 1) {
                $tokens[] = [
                    'type' => self::NUMBER,
                    'value' => str_contains($match[0], '.') ? (float) $match[0] : (int) $match[0],
                    'position' => $position,
                ];
                $position += strlen($match[0]);

                continue;
            }

            // Un nom : une variable, une propriété, un filtre, ou un mot du langage (and, true...).
            if (preg_match('/\G[a-zA-Z_][a-zA-Z0-9_]*/', $expression, $match, 0, $position) === 1) {
                $tokens[] = ['type' => self::NAME, 'value' => $match[0], 'position' => $position];
                $position += strlen($match[0]);

                continue;
            }

            if ($character === "'" || $character === '"') {
                [$text, $end] = $this->readString($expression, $position);
                $tokens[] = ['type' => self::STRING, 'value' => $text, 'position' => $position];
                $position = $end;

                continue;
            }

            $operator = $this->operatorAt($expression, $position);

            if ($operator === null) {
                throw KiooException::unexpectedCharacter($expression, $position);
            }

            $tokens[] = ['type' => self::OPERATOR, 'value' => $operator, 'position' => $position];
            $position += strlen($operator);
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

    private function operatorAt(string $expression, int $position): ?string
    {
        foreach (self::OPERATORS as $operator) {
            if (substr_compare($expression, $operator, $position, strlen($operator)) === 0) {
                return $operator;
            }
        }

        return null;
    }
}
