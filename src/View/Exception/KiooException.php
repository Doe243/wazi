<?php

declare(strict_types=1);

namespace Wazi\View\Exception;

/**
 * Levée quand un template Kioo est mal écrit, ou qu'une expression ne peut pas être calculée.
 *
 * Chaque message suit la règle des erreurs pédagogiques de Wazi : ce qui s'est
 * passé, pourquoi, et comment corriger. Les messages citent ce que VOUS avez
 * écrit dans le template (un nom de variable, un opérateur), jamais la valeur
 * d'une variable : elle peut venir d'un visiteur ou être un secret (ADR-006).
 */
final class KiooException extends \RuntimeException
{
    /**
     * @param bool $missingValue vrai quand l'erreur signale une valeur absente (variable, clé, propriété) : c'est ce que « ?? » sait rattraper
     */
    private function __construct(string $message, public readonly bool $missingValue = false)
    {
        parent::__construct($message);
    }

    // ------------------------------------------------------------------
    // Expression mal écrite
    // ------------------------------------------------------------------

    public static function unexpectedCharacter(string $expression, int $position): self
    {
        return new self(sprintf(
            'L\'expression « %s » contient un caractère que Kioo ne comprend pas, à la position %d.'
            . ' Une expression s\'écrit avec des noms de variables, des nombres, des textes entre guillemets'
            . ' et les opérateurs + - * / %% == != < > <= >= and or not ? : ?? |',
            $expression,
            $position + 1,
        ));
    }

    public static function unterminatedString(string $expression, int $position): self
    {
        return new self(sprintf(
            'Dans l\'expression « %s », le texte ouvert à la position %d n\'est pas refermé :'
            . ' il manque le guillemet de fin.',
            $expression,
            $position + 1,
        ));
    }

    public static function unexpectedToken(string $expression, int $position, string $found, string $expected): self
    {
        return new self(sprintf(
            'L\'expression « %s » est mal écrite à la position %d : Kioo attendait %s, et a trouvé %s.',
            $expression,
            $position + 1,
            $expected,
            $found,
        ));
    }

    public static function chainedComparison(string $expression, int $position): self
    {
        return new self(sprintf(
            'Dans l\'expression « %s », deux comparaisons se suivent (position %d). Kioo ne sait pas lire'
            . ' « a < b < c » : écrivez « a < b and b < c ».',
            $expression,
            $position + 1,
        ));
    }

    // ------------------------------------------------------------------
    // Valeur absente
    // ------------------------------------------------------------------

    /**
     * @param list<string> $available les noms qui existent, pour proposer le plus proche
     */
    public static function undefinedVariable(string $name, array $available): self
    {
        return new self(sprintf(
            'La variable « %s » n\'existe pas dans ce template.%s Vérifiez son nom, et qu\'elle est bien donnée'
            . ' au template. Pour prévoir son absence, écrivez {%s ?? \'valeur par défaut\'}.',
            $name,
            self::suggestion($name, $available),
            $name,
        ), true);
    }

    /**
     * @param list<string> $available
     */
    public static function undefinedProperty(string $name, string $ownerType, array $available): self
    {
        return new self(sprintf(
            '« %s » n\'existe pas ici : la valeur de gauche (%s) n\'a ni clé ni propriété publique de ce nom.%s'
            . ' Pour appeler une méthode, ajoutez des parenthèses : .%s()',
            $name,
            $ownerType,
            self::suggestion($name, $available),
            $name,
        ), true);
    }

    public static function undefinedIndex(string $ownerType): self
    {
        return new self(sprintf(
            'L\'élément demandé entre crochets n\'existe pas dans cette valeur (%s). Vérifiez la clé,'
            . ' ou prévoyez son absence avec « ?? ».',
            $ownerType,
        ), true);
    }

    public static function accessOnNull(string $name): self
    {
        return new self(sprintf(
            'Impossible de lire « %s » : la valeur de gauche vaut null (elle est vide). Vérifiez qu\'elle est bien'
            . ' remplie, ou prévoyez ce cas avec « ?? ».',
            $name,
        ), true);
    }

    // ------------------------------------------------------------------
    // Valeur du mauvais type
    // ------------------------------------------------------------------

    public static function notAccessible(string $name, string $givenType): self
    {
        return new self(sprintf(
            'Impossible de lire « %s » : la valeur de gauche est de type %s. Le point et les crochets ne'
            . ' s\'utilisent que sur un tableau ou un objet.',
            $name,
            $givenType,
        ));
    }

    /**
     * @param list<string> $available
     */
    public static function undefinedMethod(string $name, string $ownerType, array $available): self
    {
        return new self(sprintf(
            'La méthode « %s() » ne peut pas être appelée sur cette valeur (%s) : elle n\'existe pas, ou n\'est pas'
            . ' publique.%s Un template ne peut appeler que les méthodes publiques des objets qu\'on lui donne.',
            $name,
            $ownerType,
            self::suggestion($name, $available),
        ));
    }

    public static function numberExpected(string $operator, string $givenType): self
    {
        return new self(sprintf(
            'L\'opérateur « %s » ne calcule qu\'avec des nombres, et a reçu une valeur de type %s.'
            . ' Kioo ne transforme pas un texte en nombre tout seul : donnez un nombre au template.',
            $operator,
            $givenType,
        ));
    }

    public static function divisionByZero(): self
    {
        return new self(
            'Division par zéro dans une expression. Vérifiez le diviseur, par exemple avec'
            . ' {total > 0 ? somme / total : 0}.',
        );
    }

    public static function notComparable(string $operator, string $leftType, string $rightType): self
    {
        return new self(sprintf(
            'L\'opérateur « %s » ne peut pas comparer une valeur de type %s avec une valeur de type %s.'
            . ' Il compare deux nombres, ou deux textes. Attention : le nombre 3 et le texte \'3\' sont'
            . ' deux valeurs différentes.',
            $operator,
            $leftType,
            $rightType,
        ));
    }

    // ------------------------------------------------------------------
    // Filtres
    // ------------------------------------------------------------------

    /**
     * @param list<string> $available
     */
    public static function unknownFilter(string $name, array $available): self
    {
        sort($available);

        return new self(sprintf(
            'Le filtre « %s » n\'existe pas.%s Filtres disponibles : %s.',
            $name,
            self::suggestion($name, $available),
            implode(', ', $available),
        ));
    }

    public static function filterExpects(string $filter, string $expected, string $givenType): self
    {
        return new self(sprintf(
            'Le filtre « %s » attend %s, et a reçu une valeur de type %s.',
            $filter,
            $expected,
            $givenType,
        ));
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * Propose le nom existant le plus proche de celui qui a été écrit :
     * la plupart des erreurs sont des fautes de frappe.
     *
     * @param list<string> $available
     */
    private static function suggestion(string $name, array $available): string
    {
        $closest = null;
        $shortest = 3;

        foreach ($available as $candidate) {
            // levenshtein() compte les lettres à changer pour passer d'un mot à l'autre.
            $distance = levenshtein(strtolower($name), strtolower($candidate));

            if ($distance < $shortest) {
                $closest = $candidate;
                $shortest = $distance;
            }
        }

        return $closest === null ? '' : sprintf(' Vouliez-vous écrire « %s » ?', $closest);
    }
}
