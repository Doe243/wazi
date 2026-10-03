<?php

declare(strict_types=1);

namespace Wazi\View;

use Wazi\View\Exception\KiooException;

/**
 * Les filtres fournis avec Kioo : de petites fonctions qui transforment une
 * valeur avant son affichage.
 *
 *     {titre | upper}            BONJOUR
 *     {prix | number(2)}         1 234,50
 *     {date | date('d/m/Y')}     03/10/2026
 *     {auteurs | join(', ')}     Alice, Bob
 *
 * Un filtre reçoit la valeur à sa gauche, puis ses arguments. Il vérifie le
 * type de ce qu'il reçoit et le dit clairement quand il ne convient pas.
 *
 * Sécurité (ADR-019) : un template ne peut appeler QUE les filtres de cette
 * liste et ceux que votre application enregistre. Aucune fonction de PHP
 * n'est accessible depuis un template.
 */
final class Filters
{
    /**
     * @return array<string, \Closure>
     */
    public static function defaults(): array
    {
        return [
            'upper' => static fn(mixed $value): string => mb_strtoupper(self::text('upper', $value)),
            'lower' => static fn(mixed $value): string => mb_strtolower(self::text('lower', $value)),
            'capitalize' => self::capitalize(...),
            'trim' => static fn(mixed $value): string => trim(self::text('trim', $value)),
            'length' => self::length(...),
            'number' => self::number(...),
            'date' => self::date(...),
            'join' => self::join(...),
            'first' => static fn(mixed $value): mixed => self::edge('first', $value, true),
            'last' => static fn(mixed $value): mixed => self::edge('last', $value, false),
        ];
    }

    /**
     * La première lettre en majuscule : « bonjour le monde » devient « Bonjour le monde ».
     */
    private static function capitalize(mixed $value): string
    {
        $text = self::text('capitalize', $value);

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /**
     * Le nombre de caractères d'un texte, ou d'éléments d'une liste.
     */
    private static function length(mixed $value): int
    {
        return match (true) {
            is_string($value) => mb_strlen($value),
            is_countable($value) => count($value),
            default => throw KiooException::filterExpects('length', 'un texte ou une liste', get_debug_type($value)),
        };
    }

    /**
     * Un nombre écrit à la française : 1234.5 devient « 1 234,50 » avec number(2).
     * L'espace entre les milliers est insécable : le nombre ne sera pas coupé en fin de ligne.
     */
    private static function number(mixed $value, mixed $decimals = 0): string
    {
        if (!is_int($value) && !is_float($value)) {
            throw KiooException::filterExpects('number', 'un nombre', get_debug_type($value));
        }

        if (!is_int($decimals) || $decimals < 0 || $decimals > 10) {
            throw KiooException::filterExpects('number', 'un nombre de décimales entre 0 et 10 en argument', get_debug_type($decimals));
        }

        return number_format($value, $decimals, ',', "\u{00A0}");
    }

    /**
     * Une date mise en forme. Le format est celui de PHP : d (jour), m (mois), Y (année), H (heures), i (minutes).
     */
    private static function date(mixed $value, mixed $format = 'd/m/Y'): string
    {
        if (!$value instanceof \DateTimeInterface) {
            throw KiooException::filterExpects('date', 'une date (un objet DateTime ou DateTimeImmutable)', get_debug_type($value));
        }

        if (!is_string($format)) {
            throw KiooException::filterExpects('date', 'un format en texte en argument, par exemple \'d/m/Y\'', get_debug_type($format));
        }

        return $value->format($format);
    }

    /**
     * Les éléments d'une liste réunis en un seul texte : ['a', 'b'] devient « a, b ».
     */
    private static function join(mixed $value, mixed $separator = ', '): string
    {
        if (!is_array($value)) {
            throw KiooException::filterExpects('join', 'une liste', get_debug_type($value));
        }

        if (!is_string($separator)) {
            throw KiooException::filterExpects('join', 'un séparateur en texte en argument', get_debug_type($separator));
        }

        $items = [];

        foreach ($value as $item) {
            if (!is_scalar($item)) {
                throw KiooException::filterExpects('join', 'une liste de textes ou de nombres', 'liste contenant ' . get_debug_type($item));
            }

            $items[] = (string) $item;
        }

        return implode($separator, $items);
    }

    /**
     * Le premier ou le dernier élément d'une liste ; null si elle est vide.
     */
    private static function edge(string $filter, mixed $value, bool $first): mixed
    {
        if (!is_array($value)) {
            throw KiooException::filterExpects($filter, 'une liste', get_debug_type($value));
        }

        if ($value === []) {
            return null;
        }

        return $first ? array_first($value) : array_last($value);
    }

    /**
     * Un texte, ou un nombre qu'on accepte de lire comme un texte.
     */
    private static function text(string $filter, mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => throw KiooException::filterExpects($filter, 'un texte', get_debug_type($value)),
        };
    }
}
