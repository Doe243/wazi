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
 * Transforme le texte d'une expression en arbre (voir Node).
 *
 * La difficulté est la priorité des opérateurs : dans « 1 + 2 * 3 », la
 * multiplication se fait d'abord. La technique utilisée ici est la plus simple
 * qui soit : une méthode par niveau de priorité, chacune appelant la suivante.
 * La première appelée est la MOINS prioritaire ; plus on descend, plus
 * l'opérateur « serre » ses voisins.
 *
 *     filtre        valeur | filtre            ← le moins prioritaire
 *     choix         a ? b : c
 *     défaut        a ?? b
 *     ou            a or b
 *     et            a and b
 *     négation      not a
 *     comparaison   a == b, a < b...
 *     addition      a + b, a - b
 *     produit       a * b, a / b, a % b
 *     signe         -a
 *     accès         a.b, a[b], a.b()
 *     valeur        42, 'texte', variable, (…)  ← le plus prioritaire
 *
 * Le filtre est tout en haut : il s'applique à TOUTE l'expression à sa gauche.
 * {prix * 2 | number} formate le produit, pas seulement le 2 (ADR-019).
 */
final class Parser
{
    private const array COMPARISONS = ['==', '!=', '<', '>', '<=', '>='];

    /** @var list<array{type: string, value: string|int|float, position: int}> */
    private array $tokens = [];

    /** Le numéro du jeton en cours de lecture. */
    private int $cursor = 0;

    private string $expression = '';

    public function __construct(private readonly Lexer $lexer = new Lexer()) {}

    /**
     * @throws KiooException si l'expression est mal écrite
     */
    public function parse(string $expression): Node
    {
        $this->expression = $expression;
        $this->tokens = $this->lexer->tokenize($expression);
        $this->cursor = 0;

        $node = $this->parseFilter();

        if ($this->current()['type'] !== Lexer::END) {
            // « notes | length > 1 » : le filtre porte sur tout ce qui le précède,
            // et rien ne peut le suivre. On l'explique plutôt que de laisser deviner.
            throw $node instanceof Filter && $this->current()['type'] === Lexer::OPERATOR
                ? KiooException::operatorAfterFilter($expression, $node->name, $this->current()['position'])
                : $this->unexpected('la fin de l\'expression ou un opérateur');
        }

        return $node;
    }

    // ------------------------------------------------------------------
    // Une méthode par niveau de priorité, du moins au plus prioritaire
    // ------------------------------------------------------------------

    private function parseFilter(): Node
    {
        $node = $this->parseTernary();

        while ($this->isOperator('|')) {
            $position = $this->advance()['position'];
            $name = $this->expectName('le nom d\'un filtre, par exemple « upper »');
            $arguments = $this->isOperator('(') ? $this->parseArguments() : [];

            $node = new Filter($node, $name, $arguments, $position);
        }

        return $node;
    }

    private function parseTernary(): Node
    {
        $condition = $this->parseCoalesce();

        if (!$this->isOperator('?')) {
            return $condition;
        }

        $position = $this->advance()['position'];
        $then = $this->parseTernary();
        $this->expectOperator(':', '« : » suivi de la valeur à utiliser quand la condition est fausse');
        $else = $this->parseTernary();

        return new Ternary($condition, $then, $else, $position);
    }

    private function parseCoalesce(): Node
    {
        $left = $this->parseOr();

        if (!$this->isOperator('??')) {
            return $left;
        }

        $position = $this->advance()['position'];

        // « a ?? b ?? c » se lit « a ?? (b ?? c) » : on rappelle cette même méthode pour la droite.
        return new Binary('??', $left, $this->parseCoalesce(), $position);
    }

    private function parseOr(): Node
    {
        $left = $this->parseAnd();

        while ($this->isName('or')) {
            $position = $this->advance()['position'];
            $left = new Binary('or', $left, $this->parseAnd(), $position);
        }

        return $left;
    }

    private function parseAnd(): Node
    {
        $left = $this->parseNot();

        while ($this->isName('and')) {
            $position = $this->advance()['position'];
            $left = new Binary('and', $left, $this->parseNot(), $position);
        }

        return $left;
    }

    private function parseNot(): Node
    {
        if ($this->isName('not')) {
            $position = $this->advance()['position'];

            return new Unary('not', $this->parseNot(), $position);
        }

        return $this->parseComparison();
    }

    private function parseComparison(): Node
    {
        $left = $this->parseAdditive();

        if (!$this->isOperator(...self::COMPARISONS)) {
            return $left;
        }

        $operator = $this->advance();
        $node = new Binary((string) $operator['value'], $left, $this->parseAdditive(), $operator['position']);

        // Une seconde comparaison juste après : « a < b < c » n'a pas de sens clair.
        $next = $this->current();

        if ($next['type'] === Lexer::OPERATOR && in_array($next['value'], self::COMPARISONS, true)) {
            throw KiooException::chainedComparison($this->expression, $next['position']);
        }

        return $node;
    }

    private function parseAdditive(): Node
    {
        $left = $this->parseMultiplicative();

        while ($this->isOperator('+', '-')) {
            $operator = $this->advance();
            $left = new Binary((string) $operator['value'], $left, $this->parseMultiplicative(), $operator['position']);
        }

        return $left;
    }

    private function parseMultiplicative(): Node
    {
        $left = $this->parseUnary();

        while ($this->isOperator('*', '/', '%')) {
            $operator = $this->advance();
            $left = new Binary((string) $operator['value'], $left, $this->parseUnary(), $operator['position']);
        }

        return $left;
    }

    private function parseUnary(): Node
    {
        if ($this->isOperator('-')) {
            $position = $this->advance()['position'];

            return new Unary('-', $this->parseUnary(), $position);
        }

        return $this->parseAccess();
    }

    /**
     * Les accès s'enchaînent de gauche à droite : note.auteur.nom, notes[0].texte, note.resume(80).
     */
    private function parseAccess(): Node
    {
        $node = $this->parseValue();

        while (true) {
            if ($this->isOperator('.')) {
                $position = $this->advance()['position'];
                $name = $this->expectName('un nom de propriété ou de méthode après le point');

                $node = $this->isOperator('(')
                    ? new MethodCall($node, $name, $this->parseArguments(), $position)
                    : new Property($node, $name, $position);

                continue;
            }

            if ($this->isOperator('[')) {
                $position = $this->advance()['position'];
                $key = $this->parseTernary();
                $this->expectOperator(']', '« ] » pour refermer les crochets');
                $node = new Index($node, $key, $position);

                continue;
            }

            return $node;
        }
    }

    private function parseValue(): Node
    {
        $token = $this->current();

        if ($token['type'] === Lexer::NUMBER || $token['type'] === Lexer::STRING) {
            $this->advance();

            return new Literal($token['value'], $token['position']);
        }

        if ($token['type'] === Lexer::NAME) {
            $this->advance();

            return match ($token['value']) {
                'true' => new Literal(true, $token['position']),
                'false' => new Literal(false, $token['position']),
                'null' => new Literal(null, $token['position']),
                'and', 'or', 'not', 'in' => throw $this->unexpectedAt($token, 'une valeur'),
                default => new Variable((string) $token['value'], $token['position']),
            };
        }

        if ($this->isOperator('(')) {
            $this->advance();
            $node = $this->parseFilter();
            $this->expectOperator(')', '« ) » pour refermer la parenthèse');

            return $node;
        }

        throw $this->unexpected('une valeur (un nombre, un texte entre guillemets, une variable)');
    }

    /**
     * Les arguments d'une méthode ou d'un filtre : (a, b, c).
     *
     * @return list<Node>
     */
    private function parseArguments(): array
    {
        $this->expectOperator('(', '« ( »');
        $arguments = [];

        if (!$this->isOperator(')')) {
            $arguments[] = $this->parseTernary();

            while ($this->isOperator(',')) {
                $this->advance();
                $arguments[] = $this->parseTernary();
            }
        }

        $this->expectOperator(')', '« ) » pour refermer la liste des arguments');

        return $arguments;
    }

    // ------------------------------------------------------------------
    // Lecture des jetons
    // ------------------------------------------------------------------

    /**
     * @return array{type: string, value: string|int|float, position: int}
     */
    private function current(): array
    {
        return $this->tokens[$this->cursor];
    }

    /**
     * Retourne le jeton en cours, puis passe au suivant.
     *
     * @return array{type: string, value: string|int|float, position: int}
     */
    private function advance(): array
    {
        $token = $this->tokens[$this->cursor];

        // Le dernier jeton est toujours « fin » : on ne le dépasse jamais.
        if ($token['type'] !== Lexer::END) {
            $this->cursor++;
        }

        return $token;
    }

    private function isOperator(string ...$operators): bool
    {
        $token = $this->current();

        return $token['type'] === Lexer::OPERATOR && in_array($token['value'], $operators, true);
    }

    private function isName(string $name): bool
    {
        $token = $this->current();

        return $token['type'] === Lexer::NAME && $token['value'] === $name;
    }

    private function expectOperator(string $operator, string $expected): void
    {
        if (!$this->isOperator($operator)) {
            throw $this->unexpected($expected);
        }

        $this->advance();
    }

    private function expectName(string $expected): string
    {
        $token = $this->current();

        if ($token['type'] !== Lexer::NAME) {
            throw $this->unexpected($expected);
        }

        $this->advance();

        return (string) $token['value'];
    }

    private function unexpected(string $expected): KiooException
    {
        return $this->unexpectedAt($this->current(), $expected);
    }

    /**
     * @param array{type: string, value: string|int|float, position: int} $token
     */
    private function unexpectedAt(array $token, string $expected): KiooException
    {
        $found = $token['type'] === Lexer::END
            ? 'la fin de l\'expression'
            : sprintf('« %s »', is_float($token['value']) ? rtrim(sprintf('%F', $token['value']), '0') : $token['value']);

        return KiooException::unexpectedToken($this->expression, $token['position'], $found, $expected);
    }
}
