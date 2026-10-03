<?php

declare(strict_types=1);

namespace Wazi\Tests\View\Expression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\View\Exception\KiooException;
use Wazi\View\Expression\Node;
use Wazi\View\Expression\Node\Binary;
use Wazi\View\Expression\Node\Filter;
use Wazi\View\Expression\Node\Index;
use Wazi\View\Expression\Node\Literal;
use Wazi\View\Expression\Node\MethodCall;
use Wazi\View\Expression\Node\Property;
use Wazi\View\Expression\Node\Ternary;
use Wazi\View\Expression\Node\Unary;
use Wazi\View\Expression\Node\Variable;
use Wazi\View\Expression\Parser;

/**
 * L'arbre construit par l'analyseur est comparé sous une forme écrite, où
 * chaque opération est entourée de parenthèses : on y lit la priorité.
 */
final class ParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function expressions(): iterable
    {
        yield 'nombre entier' => ['42', '42'];
        yield 'nombre à virgule' => ['3.14', '3.14'];
        yield 'texte entre guillemets simples' => ["'bonjour'", '"bonjour"'];
        yield 'texte entre guillemets doubles' => ['"bonjour"', '"bonjour"'];
        yield 'guillemet échappé' => ["'l\\'été'", '"l\'été"'];
        yield 'retour à la ligne échappé' => ['"a\nb"', '"a\nb"'];
        yield 'true' => ['true', 'true'];
        yield 'false' => ['false', 'false'];
        yield 'null' => ['null', 'null'];
        yield 'variable' => ['titre', 'titre'];
        yield 'espaces ignorés' => ['  a  +  b  ', '(a + b)'];

        yield 'propriété' => ['note.texte', 'note.texte'];
        yield 'propriétés enchaînées' => ['note.auteur.nom', 'note.auteur.nom'];
        yield 'crochets' => ['notes[0]', 'notes[0]'];
        yield 'crochets avec une expression' => ['prix[devise ?? "eur"]', 'prix[(devise ?? "eur")]'];
        yield 'méthode sans argument' => ['note.resume()', 'note.resume()'];
        yield 'méthode avec arguments' => ['note.resume(80, true)', 'note.resume(80, true)'];
        yield 'accès mélangés' => ['notes[0].auteur.nom()', 'notes[0].auteur.nom()'];
        yield 'un nombre suivi d\'un point n\'est pas un nombre à virgule' => ['notes[1].id', 'notes[1].id'];

        yield 'la multiplication avant l\'addition' => ['1 + 2 * 3', '(1 + (2 * 3))'];
        yield 'les parenthèses changent l\'ordre' => ['(1 + 2) * 3', '((1 + 2) * 3)'];
        yield 'de gauche à droite' => ['10 - 2 - 3', '((10 - 2) - 3)'];
        yield 'division et reste' => ['a / b % c', '((a / b) % c)'];
        yield 'signe moins' => ['-a * 2', '((-a) * 2)'];
        yield 'deux signes moins' => ['a - -b', '(a - (-b))'];
        yield 'le calcul avant la comparaison' => ['a + 1 > b * 2', '((a + 1) > (b * 2))'];
        yield 'la comparaison avant and' => ['a > 1 and b < 2', '((a > 1) and (b < 2))'];
        yield 'and avant or' => ['a or b and c', '(a or (b and c))'];
        yield 'not avant and' => ['not a and b', '((not a) and b)'];
        yield 'not porte sur la comparaison' => ['not a == b', '(not (a == b))'];
        yield 'défaut' => ['a ?? b', '(a ?? b)'];
        yield 'défauts enchaînés, de droite à gauche' => ['a ?? b ?? c', '(a ?? (b ?? c))'];
        yield 'or avant le défaut' => ['a or b ?? c', '((a or b) ?? c)'];
        yield 'choix' => ['a ? b : c', '(a ? b : c)'];
        yield 'choix imbriqués' => ['a ? b : c ? d : e', '(a ? b : (c ? d : e))'];
        yield 'le défaut avant le choix' => ['a ?? b ? c : d', '((a ?? b) ? c : d)'];

        yield 'filtre' => ['titre | upper', '(titre | upper)'];
        yield 'filtre avec arguments' => ['prix | number(2)', '(prix | number(2))'];
        yield 'filtres enchaînés' => ['titre | trim | upper', '((titre | trim) | upper)'];
        yield 'le filtre porte sur toute l\'expression' => ['prix * 2 | number', '((prix * 2) | number)'];
        yield 'le filtre porte sur tout le choix' => ['a ? b : c | upper', '((a ? b : c) | upper)'];
        yield 'filtrer une partie demande des parenthèses' => ['a + (b | length)', '(a + (b | length))'];
        yield 'nom contenant un mot du langage' => ['android or notes', '(android or notes)'];
    }

    #[DataProvider('expressions')]
    public function testItBuildsTheTreeOfAnExpression(string $expression, string $expected): void
    {
        self::assertSame($expected, self::write(new Parser()->parse($expression)));
    }

    public function testEachNodeKnowsWhereItStarts(): void
    {
        $node = new Parser()->parse('prix * taux');

        self::assertInstanceOf(Binary::class, $node);
        self::assertSame(5, $node->position(), 'La position de l\'opérateur « * ».');
        self::assertSame(0, $node->left->position());
        self::assertSame(7, $node->right->position());
    }

    public function testAParserCanBeReused(): void
    {
        $parser = new Parser();

        self::assertSame('(a + b)', self::write($parser->parse('a + b')));
        self::assertSame('c', self::write($parser->parse('c')));
    }

    // --- Expressions mal écrites -------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidExpressions(): iterable
    {
        yield 'vide' => ['', 'une valeur'];
        yield 'opérateur seul' => ['+', 'une valeur'];
        yield 'opérateur sans suite' => ['a +', 'la fin de l\'expression'];
        yield 'deux valeurs qui se suivent' => ['a b', 'la fin de l\'expression ou un opérateur'];
        yield 'parenthèse non fermée' => ['(a + b', 'refermer la parenthèse'];
        yield 'parenthèse en trop' => ['a + b)', 'la fin de l\'expression ou un opérateur'];
        yield 'crochet non fermé' => ['notes[0', 'refermer les crochets'];
        yield 'point sans nom' => ['note.', 'un nom de propriété'];
        yield 'point suivi d\'un nombre' => ['note.0', 'un nom de propriété'];
        yield 'choix sans deux-points' => ['a ? b', '« : »'];
        yield 'filtre sans nom' => ['a |', 'le nom d\'un filtre'];
        yield 'arguments non fermés' => ['a | number(2', 'refermer la liste des arguments'];
        yield 'virgule en trop' => ['note.resume(1,)', 'une valeur'];
        yield 'mot du langage à la place d\'une valeur' => ['a + and', 'une valeur'];
        yield 'texte non refermé' => ["'bonjour", 'n\'est pas refermé'];
        yield 'caractère inconnu' => ['a # b', 'caractère que Kioo ne comprend pas'];
        yield 'signe égal seul' => ['a = b', 'caractère que Kioo ne comprend pas'];
        yield 'opérateurs de PHP' => ['a && b', 'caractère que Kioo ne comprend pas'];
        yield 'variable de PHP' => ['$titre', 'caractère que Kioo ne comprend pas'];
        yield 'comparaisons enchaînées' => ['1 < a < 10', 'a < b and b < c'];
        yield 'appel de fonction' => ['strtoupper(titre)', 'la fin de l\'expression ou un opérateur'];
        yield 'accolade' => ['{a}', 'caractère que Kioo ne comprend pas'];
    }

    #[DataProvider('invalidExpressions')]
    public function testAnInvalidExpressionIsExplained(string $expression, string $expectedHint): void
    {
        try {
            new Parser()->parse($expression);
            self::fail('Une exception était attendue.');
        } catch (KiooException $exception) {
            self::assertStringContainsString($expectedHint, $exception->getMessage());
            self::assertFalse($exception->missingValue);
        }
    }

    public function testTheErrorTellsWhereTheProblemIs(): void
    {
        try {
            new Parser()->parse('prix * (taux +');
            self::fail('Une exception était attendue.');
        } catch (KiooException $exception) {
            self::assertStringContainsString('« prix * (taux + »', $exception->getMessage());
            self::assertStringContainsString('position 15', $exception->getMessage());
        }
    }

    /**
     * Sécurité : un template ne peut appeler aucune fonction. « nom(...) »
     * n'existe pas dans le langage ; seuls existent les méthodes d'objets et les filtres.
     */
    public function testThereIsNoWayToCallAFunction(): void
    {
        foreach (['system("id")', 'phpinfo()', 'file_get_contents("/etc/passwd")', 'eval("x")'] as $expression) {
            try {
                new Parser()->parse($expression);
                self::fail('Une exception était attendue pour ' . $expression);
            } catch (KiooException $exception) {
                self::assertStringContainsString('mal écrite', $exception->getMessage());
            }
        }
    }

    // --- Outils ------------------------------------------------------------

    /**
     * Écrit un arbre sous forme de texte, chaque opération entre parenthèses.
     */
    private static function write(Node $node): string
    {
        return match (true) {
            $node instanceof Literal => json_encode($node->value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $node instanceof Variable => $node->name,
            $node instanceof Property => self::write($node->target) . '.' . $node->name,
            $node instanceof Index => self::write($node->target) . '[' . self::write($node->key) . ']',
            $node instanceof MethodCall => self::write($node->target) . '.' . $node->name . '(' . self::writeAll($node->arguments) . ')',
            $node instanceof Unary => '(' . $node->operator . ($node->operator === 'not' ? ' ' : '') . self::write($node->operand) . ')',
            $node instanceof Binary => '(' . self::write($node->left) . ' ' . $node->operator . ' ' . self::write($node->right) . ')',
            $node instanceof Ternary => '(' . self::write($node->condition) . ' ? ' . self::write($node->then) . ' : ' . self::write($node->else) . ')',
            $node instanceof Filter => '(' . self::write($node->input) . ' | ' . $node->name
                . ($node->arguments === [] ? '' : '(' . self::writeAll($node->arguments) . ')') . ')',
            default => '?',
        };
    }

    /**
     * @param list<Node> $nodes
     */
    private static function writeAll(array $nodes): string
    {
        return implode(', ', array_map(self::write(...), $nodes));
    }
}
