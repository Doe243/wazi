<?php

declare(strict_types=1);

namespace Wazi\Routing;

/**
 * Ce qu'un paramètre de route a le droit de contenir.
 *
 *     /articles/{id:int}      ← {nom:contrainte}
 *     /articles/{slug:slug}
 *     /profil/{pseudo}        ← sans contrainte : Constraint::Any
 *
 * Sécurité (ADR-006 et ADR-009) : les contraintes sont une liste fermée, pas
 * des expressions régulières libres. Une expression mal écrite peut mettre
 * des secondes à répondre sur une adresse piégée (attaque dite « ReDoS ») ;
 * celles d'ici sont écrites une fois, relues, et bornées en longueur.
 */
enum Constraint: string
{
    /** N'importe quel texte, sans barre oblique. */
    case Any = 'any';

    /** Un nombre entier positif, sans zéro inutile devant : 0, 7, 42. Le contrôleur reçoit un int. */
    case Int = 'int';

    /** Un texte d'adresse : minuscules, chiffres et tirets. Exemple : mon-premier-article */
    case Slug = 'slug';

    /** Un identifiant universel. Exemple : 123e4567-e89b-12d3-a456-426614174000 */
    case Uuid = 'uuid';

    /** Aucun paramètre n'a besoin d'être plus long ; au-delà, c'est une adresse anormale. */
    private const int MAX_LENGTH = 255;

    public function accepts(string $value): bool
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        return match ($this) {
            self::Any => true,
            // 18 chiffres au plus : le nombre tient toujours dans un entier PHP.
            self::Int => preg_match('/^(?:0|[1-9]\d{0,17})$/D', $value) === 1,
            self::Slug => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value) === 1,
            self::Uuid => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value) === 1,
        };
    }

    /**
     * La valeur telle que le contrôleur la recevra : un int pour {id:int}, le texte sinon.
     */
    public function convert(string $value): string|int
    {
        return $this === self::Int ? (int) $value : $value;
    }

    /**
     * Les noms utilisables dans une route, pour les messages d'erreur : « any, int, slug, uuid ».
     */
    public static function names(): string
    {
        return implode(', ', array_column(self::cases(), 'value'));
    }
}
