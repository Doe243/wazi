<?php

declare(strict_types=1);

namespace Wazi\View\Expression;

use Wazi\View\Exception\KiooException;
use Wazi\View\Expression\Node\Binary;
use Wazi\View\Expression\Node\Filter;
use Wazi\View\Expression\Node\Index;
use Wazi\View\Expression\Node\Literal;
use Wazi\View\Expression\Node\MethodCall;
use Wazi\View\Expression\Node\Property;
use Wazi\View\Expression\Node\Ternary;
use Wazi\View\Expression\Node\Unary;
use Wazi\View\Expression\Node\Variable;

/**
 * Calcule la valeur d'une expression, à partir de son arbre et des variables
 * données au template.
 *
 * Il parcourt l'arbre de bas en haut : pour calculer « prix * 2 », il calcule
 * d'abord « prix », puis « 2 », puis les multiplie. Une méthode par sorte
 * d'élément, et rien d'autre.
 *
 * Les règles du langage (ADR-019) sont volontairement strictes :
 *   - une variable, une clé ou une propriété inconnue est une erreur ;
 *   - on ne calcule qu'avec des nombres, on ne compare que des valeurs comparables ;
 *   - Kioo ne transforme jamais un texte en nombre en silence.
 *
 * Sécurité : un template ne peut rien exécuter d'autre que les filtres
 * enregistrés ici et les méthodes publiques des objets qu'on lui a donnés.
 */
final readonly class Evaluator
{
    /**
     * @param array<string, \Closure> $filters nom du filtre => fonction
     */
    public function __construct(private array $filters) {}

    /**
     * @param array<string, mixed> $variables les variables données au template
     *
     * @throws KiooException si l'expression ne peut pas être calculée
     */
    public function evaluate(Node $node, array $variables): mixed
    {
        return match (true) {
            $node instanceof Literal => $node->value,
            $node instanceof Variable => $this->variable($node, $variables),
            $node instanceof Property => $this->property($node, $variables),
            $node instanceof Index => $this->index($node, $variables),
            $node instanceof MethodCall => $this->methodCall($node, $variables),
            $node instanceof Unary => $this->unary($node, $variables),
            $node instanceof Binary => $this->binary($node, $variables),
            $node instanceof Ternary => $this->isTruthy($this->evaluate($node->condition, $variables))
                ? $this->evaluate($node->then, $variables)
                : $this->evaluate($node->else, $variables),
            $node instanceof Filter => $this->filter($node, $variables),
            default => throw new \LogicException('Élément d\'expression inconnu : ' . $node::class),
        };
    }

    /**
     * Ce qui compte pour « faux » dans un k:if ou devant and, or, not, ? :
     * false, null, le texte vide, le nombre zéro et la liste vide. Tout le reste est vrai.
     */
    public function isTruthy(mixed $value): bool
    {
        return !in_array($value, [false, null, '', 0, 0.0, []], true);
    }

    // ------------------------------------------------------------------
    // Lire une valeur
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $variables
     */
    private function variable(Variable $node, array $variables): mixed
    {
        if (!array_key_exists($node->name, $variables)) {
            throw KiooException::undefinedVariable($node->name, array_keys($variables));
        }

        return $variables[$node->name];
    }

    /**
     * note.texte : la clé « texte » d'un tableau, ou la propriété publique « texte » d'un objet.
     *
     * @param array<string, mixed> $variables
     */
    private function property(Property $node, array $variables): mixed
    {
        $target = $this->evaluate($node->target, $variables);

        if ($target === null) {
            throw KiooException::accessOnNull($node->name);
        }

        if (is_array($target)) {
            if (!array_key_exists($node->name, $target)) {
                throw KiooException::undefinedProperty($node->name, 'un tableau', self::names(array_keys($target)));
            }

            return $target[$node->name];
        }

        if (is_object($target)) {
            // Appelée d'ici, get_object_vars() ne voit que les propriétés PUBLIQUES de l'objet.
            $properties = get_object_vars($target);

            if (!array_key_exists($node->name, $properties)) {
                throw KiooException::undefinedProperty($node->name, 'un objet ' . $target::class, self::names(array_keys($properties)));
            }

            return $properties[$node->name];
        }

        throw KiooException::notAccessible($node->name, get_debug_type($target));
    }

    /**
     * notes[0], prix[devise] : un élément d'un tableau, par une clé calculée.
     *
     * @param array<string, mixed> $variables
     */
    private function index(Index $node, array $variables): mixed
    {
        $target = $this->evaluate($node->target, $variables);
        $key = $this->evaluate($node->key, $variables);

        if ($target === null) {
            throw KiooException::accessOnNull('[...]');
        }

        if (!is_array($target)) {
            throw KiooException::notAccessible('[...]', get_debug_type($target));
        }

        if ((!is_int($key) && !is_string($key)) || !array_key_exists($key, $target)) {
            throw KiooException::undefinedIndex(array_is_list($target) ? 'une liste de ' . count($target) . ' éléments' : 'un tableau');
        }

        return $target[$key];
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function methodCall(MethodCall $node, array $variables): mixed
    {
        $target = $this->evaluate($node->target, $variables);

        if ($target === null) {
            throw KiooException::accessOnNull($node->name . '()');
        }

        if (!is_object($target) || !self::isPublicMethod($target, $node->name)) {
            throw KiooException::undefinedMethod(
                $node->name,
                is_object($target) ? 'un objet ' . $target::class : get_debug_type($target),
                is_object($target) ? self::publicMethods($target) : [],
            );
        }

        $arguments = array_map(fn(Node $argument): mixed => $this->evaluate($argument, $variables), $node->arguments);

        return $target->{$node->name}(...$arguments);
    }

    // ------------------------------------------------------------------
    // Opérateurs
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $variables
     */
    private function unary(Unary $node, array $variables): mixed
    {
        $value = $this->evaluate($node->operand, $variables);

        if ($node->operator === 'not') {
            return !$this->isTruthy($value);
        }

        return -$this->number('-', $value);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function binary(Binary $node, array $variables): mixed
    {
        // Ces trois opérateurs ne calculent pas toujours leur partie droite :
        // « a and b » ne regarde pas b si a est faux.
        if ($node->operator === '??') {
            return $this->coalesce($node, $variables);
        }

        if ($node->operator === 'and') {
            return $this->isTruthy($this->evaluate($node->left, $variables))
                && $this->isTruthy($this->evaluate($node->right, $variables));
        }

        if ($node->operator === 'or') {
            return $this->isTruthy($this->evaluate($node->left, $variables))
                || $this->isTruthy($this->evaluate($node->right, $variables));
        }

        $left = $this->evaluate($node->left, $variables);
        $right = $this->evaluate($node->right, $variables);

        return match ($node->operator) {
            '+' => $this->number('+', $left) + $this->number('+', $right),
            '-' => $this->number('-', $left) - $this->number('-', $right),
            '*' => $this->number('*', $left) * $this->number('*', $right),
            '/' => $this->divide('/', $left, $right),
            '%' => $this->divide('%', $left, $right),
            '==' => $this->areEqual($left, $right),
            '!=' => !$this->areEqual($left, $right),
            default => $this->compare($node->operator, $left, $right),
        };
    }

    /**
     * a ?? b : la valeur de a, ou celle de b si a est absente ou vaut null.
     *
     * @param array<string, mixed> $variables
     */
    private function coalesce(Binary $node, array $variables): mixed
    {
        try {
            $left = $this->evaluate($node->left, $variables);
        } catch (KiooException $exception) {
            // Seule une valeur ABSENTE est rattrapée. Une autre erreur (un
            // calcul sur un texte, par exemple) reste une erreur.
            if (!$exception->missingValue) {
                throw $exception;
            }

            $left = null;
        }

        return $left ?? $this->evaluate($node->right, $variables);
    }

    private function divide(string $operator, mixed $left, mixed $right): int|float
    {
        $left = $this->number($operator, $left);
        $right = $this->number($operator, $right);

        if ($right === 0 || $right === 0.0) {
            throw KiooException::divisionByZero();
        }

        if ($operator === '/') {
            return $left / $right;
        }

        // Le reste d'une division n'a de sens qu'entre nombres entiers.
        return is_int($left) && is_int($right) ? $left % $right : fmod($left, $right);
    }

    /**
     * Deux valeurs sont égales si elles ont le même type et la même valeur.
     * Seule souplesse : un nombre entier et un nombre à virgule se comparent (2 == 2.0).
     */
    private function areEqual(mixed $left, mixed $right): bool
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return (float) $left === (float) $right;
        }

        return $left === $right;
    }

    private function compare(string $operator, mixed $left, mixed $right): bool
    {
        $bothNumbers = (is_int($left) || is_float($left)) && (is_int($right) || is_float($right));

        if (!$bothNumbers && !(is_string($left) && is_string($right))) {
            throw KiooException::notComparable($operator, get_debug_type($left), get_debug_type($right));
        }

        return match ($operator) {
            '<' => $left < $right,
            '>' => $left > $right,
            '<=' => $left <= $right,
            default => $left >= $right,
        };
    }

    private function number(string $operator, mixed $value): int|float
    {
        return is_int($value) || is_float($value)
            ? $value
            : throw KiooException::numberExpected($operator, get_debug_type($value));
    }

    // ------------------------------------------------------------------
    // Filtres
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $variables
     */
    private function filter(Filter $node, array $variables): mixed
    {
        $filter = $this->filters[$node->name]
            ?? throw KiooException::unknownFilter($node->name, self::names(array_keys($this->filters)));

        $arguments = array_map(fn(Node $argument): mixed => $this->evaluate($argument, $variables), $node->arguments);

        return $filter($this->evaluate($node->input, $variables), ...$arguments);
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * Les clés d'un tableau, en texte, pour proposer la plus proche dans un message d'erreur.
     *
     * @param list<int|string> $keys
     *
     * @return list<string>
     */
    private static function names(array $keys): array
    {
        return array_map(static fn(int|string $key): string => (string) $key, $keys);
    }

    /**
     * Sécurité : seule une méthode publique, réellement écrite dans la classe,
     * peut être appelée. Ni méthode « magique » (__destruct, __call...), ni
     * méthode privée ou protégée.
     *
     * On interroge la déclaration de la méthode (ReflectionMethod) plutôt que
     * is_callable() : si la classe possède un __call, is_callable() répond
     * « oui » pour n'importe quel nom, y compris celui d'une méthode privée.
     */
    private static function isPublicMethod(object $object, string $name): bool
    {
        return !str_starts_with($name, '__')
            && method_exists($object, $name)
            && new \ReflectionMethod($object, $name)->isPublic();
    }

    /**
     * @return list<string>
     */
    private static function publicMethods(object $object): array
    {
        return array_values(array_filter(
            get_class_methods($object),
            static fn(string $method): bool => !str_starts_with($method, '__'),
        ));
    }
}
