<?php

declare(strict_types=1);

namespace Wazi\Tests\View\Expression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Tests\View\Fixtures\Note;
use Wazi\View\Exception\KiooException;
use Wazi\View\Expression\Evaluator;
use Wazi\View\Expression\Parser;
use Wazi\View\Filters;

/**
 * Chaque test écrit une expression comme dans un template, et vérifie sa valeur.
 */
final class EvaluatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function expressions(): iterable
    {
        // Lire des valeurs.
        yield 'variable' => ['titre', 'Mon carnet'];
        yield 'clé d\'un tableau' => ['auteur.nom', 'Mumba'];
        yield 'clés enchaînées' => ['auteur.ville.nom', 'Kinshasa'];
        yield 'propriété publique d\'un objet' => ['note.texte', 'Acheter du pain'];
        yield 'objet dans un objet' => ['note.suivante.texte', 'Lire le routeur'];
        yield 'élément d\'une liste' => ['prix[1]', 20];
        yield 'clé calculée' => ['auteur[champ]', 'Mumba'];
        yield 'liste d\'objets' => ['notes[0].id', 7];
        yield 'méthode sans argument' => ['note.resume()', 'Achet'];
        yield 'méthode avec argument' => ['note.resume(7)', 'Acheter'];
        yield 'méthode avec un argument calculé' => ['note.resume(total - 97)', 'Ach'];
        yield 'méthode qui retourne un booléen' => ['note.estLongue()', true];

        // Calculer.
        yield 'addition' => ['1 + 2', 3];
        yield 'priorité' => ['1 + 2 * 3', 7];
        yield 'parenthèses' => ['(1 + 2) * 3', 9];
        yield 'soustraction' => ['total - 0.5', 99.5];
        yield 'division entière' => ['total / 4', 25];
        yield 'division à virgule' => ['7 / 2', 3.5];
        yield 'reste' => ['7 % 3', 1];
        yield 'reste à virgule' => ['7.5 % 2', 1.5];
        yield 'signe moins' => ['-total', -100];
        yield 'calcul avec une variable' => ['prix[0] * 2 + prix[1]', 40];

        // Comparer.
        yield 'égal' => ['total == 100', true];
        yield 'entier égal à virgule' => ['total == 100.0', true];
        yield 'différent' => ['total != 100', false];
        yield 'textes égaux' => ["titre == 'Mon carnet'", true];
        yield 'la casse compte' => ["titre == 'mon carnet'", false];
        yield 'un nombre n\'est pas un texte' => ["total == '100'", false];
        yield 'zéro n\'est pas false' => ['0 == false', false];
        yield 'null n\'est égal qu\'à null' => ['rien == null', true];
        yield 'le texte vide n\'est pas null' => ["vide == null", false];
        yield 'plus petit' => ['prix[0] < prix[1]', true];
        yield 'plus grand ou égal' => ['total >= 100', true];
        yield 'textes dans l\'ordre alphabétique' => ["'abricot' < 'banane'", true];

        // Logique : ce qui est vrai, ce qui est faux.
        yield 'and' => ['total > 50 and total < 200', true];
        yield 'or' => ['total > 500 or actif', true];
        yield 'not' => ['not actif', false];
        yield 'le texte vide est faux' => ['vide ? 1 : 2', 2];
        yield 'zéro est faux' => ['0 ? 1 : 2', 2];
        yield 'null est faux' => ['rien ? 1 : 2', 2];
        yield 'la liste vide est fausse' => ['aucune ? 1 : 2', 2];
        yield 'le texte « 0 » est vrai' => ["'0' ? 1 : 2", 1];
        yield 'une liste remplie est vraie' => ['prix ? 1 : 2', 1];
        yield 'un objet est vrai' => ['note ? 1 : 2', 1];
        yield 'and ne regarde pas la suite si le début est faux' => ['false and inconnue', false];
        yield 'or ne regarde pas la suite si le début est vrai' => ['true or inconnue', true];
        yield 'le choix ne calcule que la branche retenue' => ['actif ? titre : inconnue', 'Mon carnet'];

        // Prévoir l'absence.
        yield 'défaut pour une variable inconnue' => ["inconnue ?? 'défaut'", 'défaut'];
        yield 'défaut pour une clé inconnue' => ["auteur.age ?? 'non précisé'", 'non précisé'];
        yield 'défaut pour une propriété inconnue' => ['note.absente ?? 0', 0];
        yield 'défaut pour un élément inconnu' => ['prix[9] ?? 0', 0];
        yield 'défaut pour null' => ["rien ?? 'vide'", 'vide'];
        yield 'défaut à travers null' => ["rien.nom ?? 'personne'", 'personne'];
        yield 'défaut à travers un objet absent' => ["note.suivante.suivante.texte ?? 'fin'", 'fin'];
        yield 'pas de défaut quand la valeur existe' => ["titre ?? 'défaut'", 'Mon carnet'];
        yield 'le texte vide existe' => ["vide ?? 'défaut'", ''];
        yield 'false existe' => ['faux ?? true', false];
        yield 'défauts enchaînés' => ["inconnue ?? rien ?? 'dernier'", 'dernier'];

        // Filtres.
        yield 'filtre' => ['titre | upper', 'MON CARNET'];
        yield 'filtres enchaînés' => ['titre | lower | capitalize', 'Mon carnet'];
        yield 'filtre avec argument' => ['1234.5 | number(2)', "1\u{00A0}234,50"];
        yield 'le filtre porte sur tout le calcul' => ['prix[0] * 2 | number', '20'];
        yield 'filtre sur une partie' => ['total + (titre | length)', 110];
        yield 'filtre après un défaut' => ["inconnue ?? 'défaut' | upper", 'DÉFAUT'];
        yield 'filtre de l\'application' => ['prix[1] | euros', '20 €'];
    }

    #[DataProvider('expressions')]
    public function testItComputesAnExpression(string $expression, mixed $expected): void
    {
        self::assertSame($expected, $this->evaluate($expression));
    }

    // --- Erreurs pédagogiques ----------------------------------------------

    public function testAnUnknownVariableIsAnErrorWithASuggestion(): void
    {
        try {
            $this->evaluate('tittre');
            self::fail('Une exception était attendue.');
        } catch (KiooException $exception) {
            self::assertStringContainsString('« tittre » n\'existe pas', $exception->getMessage());
            self::assertStringContainsString('Vouliez-vous écrire « titre » ?', $exception->getMessage());
            self::assertStringContainsString('??', $exception->getMessage());
            self::assertTrue($exception->missingValue);
        }
    }

    public function testNoSuggestionWhenNothingIsClose(): void
    {
        try {
            $this->evaluate('xyzxyz');
            self::fail('Une exception était attendue.');
        } catch (KiooException $exception) {
            self::assertStringNotContainsString('Vouliez-vous', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failingExpressions(): iterable
    {
        yield 'clé inconnue, avec suggestion' => ['auteur.nmo', 'Vouliez-vous écrire « nom » ?'];
        yield 'propriété inconnue, avec suggestion' => ['note.text', 'Vouliez-vous écrire « texte » ?'];
        yield 'propriété prise pour une méthode' => ['note.resume', '.resume()'];
        yield 'élément hors de la liste' => ['prix[9]', 'une liste de 2 éléments'];
        yield 'lecture sur null' => ['rien.nom', 'vaut null'];
        yield 'méthode sur null' => ['rien.resume()', 'vaut null'];
        yield 'crochets sur null' => ['rien[0]', 'vaut null'];
        yield 'point sur un texte' => ['titre.longueur', 'de type string'];
        yield 'crochets sur un nombre' => ['total[0]', 'de type int'];
        yield 'méthode inconnue, avec suggestion' => ['note.resum()', 'Vouliez-vous écrire « resume » ?'];
        yield 'méthode sur un tableau' => ['auteur.nom()', 'ne peut pas être appelée'];
        yield 'calcul avec un texte' => ["total + '1'", 'ne calcule qu\'avec des nombres'];
        yield 'calcul avec null' => ['total * rien', 'de type null'];
        yield 'signe moins sur un texte' => ['-titre', 'ne calcule qu\'avec des nombres'];
        yield 'division par zéro' => ['total / 0', 'Division par zéro'];
        yield 'reste par zéro' => ['total % 0', 'Division par zéro'];
        yield 'comparer un nombre et un texte' => ["total < '200'", 'le nombre 3 et le texte \'3\''];
        yield 'comparer un nombre et null' => ['total > rien', 'ne peut pas comparer'];
        yield 'filtre inconnu, avec suggestion' => ['titre | uper', 'Vouliez-vous écrire « upper » ?'];
        yield 'filtre inconnu : la liste est donnée' => ['titre | xyz', 'Filtres disponibles : capitalize, date, euros, first'];
        yield 'filtre sur le mauvais type' => ['prix | upper', 'attend un texte'];
    }

    #[DataProvider('failingExpressions')]
    public function testAFailingExpressionIsExplained(string $expression, string $expectedHint): void
    {
        try {
            $this->evaluate($expression);
            self::fail('Une exception était attendue.');
        } catch (KiooException $exception) {
            self::assertStringContainsString($expectedHint, $exception->getMessage());
        }
    }

    /**
     * « ?? » rattrape une valeur ABSENTE, pas une vraie erreur : un calcul
     * impossible ne doit pas être masqué par une valeur par défaut.
     */
    public function testTheDefaultOperatorDoesNotHideRealErrors(): void
    {
        foreach (["total + 'x' ?? 0", '(total / 0) ?? 0', '(titre | xyz) ?? 0', '(titre.x.y) ?? 0'] as $expression) {
            try {
                $this->evaluate($expression);
                self::fail('Une exception était attendue pour ' . $expression);
            } catch (KiooException $exception) {
                self::assertFalse($exception->missingValue);
            }
        }
    }

    public function testAnExceptionThrownByAMethodIsNotSwallowed(): void
    {
        $this->expectException(\DomainException::class);

        $this->evaluate('note.echoue() ?? 0');
    }

    // --- Sécurité ----------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function expressionsReachingPrivateData(): iterable
    {
        yield 'propriété privée' => ['note.secret'];
        yield 'propriété protégée' => ['note.brouillon'];
        yield 'méthode privée' => ['note.secret()'];
        yield 'méthode protégée' => ['note.brouillon()'];
        yield 'propriété privée par crochets' => ["note['secret']"];
    }

    /**
     * Un template ne lit et n'appelle que ce qui est public.
     */
    #[DataProvider('expressionsReachingPrivateData')]
    public function testOnlyPublicMembersAreReachable(string $expression): void
    {
        try {
            $value = $this->evaluate($expression);
            self::fail('Une exception était attendue, valeur obtenue : ' . json_encode($value));
        } catch (KiooException $exception) {
            self::assertStringNotContainsString('mot-de-passe-tres-secret', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function magicMethodCalls(): iterable
    {
        yield 'destructeur' => ['note.__destruct()'];
        yield 'constructeur' => ["note.__construct('x')"];
        yield 'appel magique direct' => ["note.__call('x', prix)"];
        yield 'méthode inexistante, rattrapée par __call' => ['note.nimporteQuoi()'];
    }

    /**
     * Les méthodes « magiques » de PHP ne sont jamais appelables.
     */
    #[DataProvider('magicMethodCalls')]
    public function testMagicMethodsCanNeverBeCalled(string $expression): void
    {
        $destroyedBefore = Note::$destroyed;

        try {
            $this->evaluate($expression);
            self::fail('Une exception était attendue.');
        } catch (KiooException $exception) {
            self::assertStringContainsString('ne peut pas être appelée', $exception->getMessage());
        }

        self::assertSame($destroyedBefore, Note::$destroyed);
    }

    /**
     * Les messages citent ce qui est écrit dans le template, jamais la valeur
     * d'une variable : elle peut être un secret ou venir d'un visiteur.
     */
    public function testErrorMessagesNeverContainTheValueOfAVariable(): void
    {
        $variables = ['secret' => 'mot-de-passe-tres-secret', 'liste' => ['mot-de-passe-tres-secret']];

        foreach (['secret + 1', 'secret.x', 'secret[0]', 'secret < 1', 'secret | number', 'liste | upper', 'liste.x', 'secret.x()'] as $expression) {
            try {
                $this->evaluate($expression, $variables);
                self::fail('Une exception était attendue pour ' . $expression);
            } catch (KiooException $exception) {
                self::assertStringNotContainsString('mot-de-passe', $exception->getMessage(), $expression);
            }
        }
    }

    // --- Outils ------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $variables
     */
    private function evaluate(string $expression, ?array $variables = null): mixed
    {
        $filters = Filters::defaults() + [
            'euros' => static fn(mixed $value): string => (is_int($value) ? $value : 0) . ' €',
        ];

        return new Evaluator($filters)->evaluate(new Parser()->parse($expression), $variables ?? [
            'titre' => 'Mon carnet',
            'total' => 100,
            'actif' => true,
            'faux' => false,
            'vide' => '',
            'rien' => null,
            'aucune' => [],
            'champ' => 'nom',
            'prix' => [10, 20],
            'auteur' => ['nom' => 'Mumba', 'ville' => ['nom' => 'Kinshasa']],
            'note' => new Note('Acheter du pain', 7, new Note('Lire le routeur', 8)),
            'notes' => [new Note()],
        ]);
    }
}
