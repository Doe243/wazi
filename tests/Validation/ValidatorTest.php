<?php

declare(strict_types=1);

namespace Wazi\Tests\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Validation\Validator;

final class ValidatorTest extends TestCase
{
    // --- Un formulaire bien rempli ------------------------------------------

    public function testEachFieldComesBackInItsOwnType(): void
    {
        $v = new Validator([
            'nom' => '  Alice  ',
            'age' => '42',
            'prix' => '12,50',
            'email' => 'alice@exemple.com',
            'sujet' => 'devis',
            'message' => "Bonjour,\r\nsur deux lignes.",
            'conditions' => '1',
            'naissance' => '1990-02-28',
            'mot_de_passe' => ' secret avec espaces ',
        ]);

        self::assertSame('Alice', $v->text('nom', max: 80));
        self::assertSame(42, $v->integer('age', min: 18));
        self::assertSame(12.5, $v->decimal('prix'));
        self::assertSame('alice@exemple.com', $v->email('email'));
        self::assertSame('devis', $v->choice('sujet', ['devis', 'question']));
        self::assertSame("Bonjour,\nsur deux lignes.", $v->longText('message'));
        self::assertTrue($v->checkbox('conditions'));
        self::assertSame('1990-02-28 00:00:00', $v->date('naissance')?->format('Y-m-d H:i:s'));
        self::assertSame(' secret avec espaces ', $v->password('mot_de_passe'), 'Un mot de passe est pris tel quel.');

        self::assertFalse($v->fails());
        self::assertSame([], $v->errors());
    }

    public function testValuesOnlyContainsWhatWasChecked(): void
    {
        $v = new Validator(['nom' => 'Alice', 'role' => 'admin', 'id' => '1']);
        $v->text('nom');

        // Sécurité : « role » et « id », ajoutés à la main par le visiteur,
        // n'ont jamais été demandés : ils n'entrent pas dans les valeurs.
        self::assertSame(['nom' => 'Alice'], $v->values());
    }

    // --- Champs obligatoires et facultatifs -----------------------------------

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function emptyForms(): iterable
    {
        yield 'champ absent' => [[]];
        yield 'champ vide' => [['champ' => '']];
        yield 'des espaces seulement' => [['champ' => "  \t "]];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('emptyForms')]
    public function testAFieldIsRequiredByDefault(array $data): void
    {
        foreach (['text', 'longText', 'integer', 'decimal', 'email', 'date'] as $method) {
            $v = new Validator($data);
            $v->$method('champ');

            self::assertSame(['champ' => 'Ce champ est obligatoire.'], $v->errors(), $method);
            self::assertSame([], $v->values(), $method);
        }

        $v = new Validator($data);
        $v->choice('champ', ['a']);

        self::assertSame(['champ' => 'Ce champ est obligatoire.'], $v->errors());
    }

    /**
     * Un mot de passe est pris tel quel : des espaces sont des caractères
     * comme les autres. Seul un champ vraiment vide est « manquant ».
     */
    public function testAPasswordIsRequiredButSpacesCount(): void
    {
        foreach ([[], ['champ' => '']] as $data) {
            $v = new Validator($data);

            self::assertSame('', $v->password('champ'));
            self::assertSame(['champ' => 'Ce champ est obligatoire.'], $v->errors());
        }

        $v = new Validator(['champ' => '   ']);
        $v->password('champ');

        self::assertSame(['champ' => 'Écrivez au moins 8 caractères.'], $v->errors());
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('emptyForms')]
    public function testAnOptionalFieldLeftEmptyIsNotAnError(array $data): void
    {
        $v = new Validator($data);

        self::assertSame('', $v->text('champ', required: false));
        self::assertSame('', $v->longText('champ', required: false));
        self::assertNull($v->integer('champ', required: false));
        self::assertNull($v->decimal('champ', required: false));
        self::assertSame('', $v->email('champ', required: false));
        self::assertSame('', $v->choice('champ', ['a'], required: false));
        self::assertNull($v->date('champ', required: false));
        self::assertFalse($v->fails());
    }

    // --- Textes --------------------------------------------------------------------

    public function testATextIsBoundedByDefault(): void
    {
        $v = new Validator(['court' => str_repeat('é', 256), 'long' => str_repeat('é', 5001)]);

        $v->text('court');
        $v->longText('long');

        self::assertSame('Écrivez 255 caractères au maximum (vous en avez écrit 256).', $v->errors()['court']);
        self::assertSame('Écrivez 5000 caractères au maximum (vous en avez écrit 5001).', $v->errors()['long']);
    }

    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        $v = new Validator(['nom' => 'éééé']);

        self::assertSame('éééé', $v->text('nom', max: 4));
        self::assertFalse($v->fails());
    }

    public function testAMinimumLengthCanBeAsked(): void
    {
        $v = new Validator(['nom' => 'ab']);
        $v->text('nom', min: 3);

        self::assertSame('Écrivez au moins 3 caractères.', $v->errors()['nom']);
    }

    public function testAShortTextHoldsOnOneLine(): void
    {
        $v = new Validator(['nom' => "Alice\nBob"]);

        self::assertSame('', $v->text('nom'));
        self::assertSame('Ce champ tient sur une seule ligne.', $v->errors()['nom']);
    }

    public function testInALongTextALineBreakCountsForOneCharacter(): void
    {
        $v = new Validator(['message' => "a\r\nb"]);

        self::assertSame("a\nb", $v->longText('message', max: 3));
        self::assertFalse($v->fails());
    }

    // --- Nombres --------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function notIntegers(): iterable
    {
        yield 'lettres' => ['douze'];
        yield 'notation scientifique' => ['1e3'];
        yield 'hexadécimal' => ['0x1A'];
        yield 'décimal' => ['12.5'];
        yield 'virgule' => ['12,5'];
        yield 'chiffres puis lettres' => ['12abc'];
        yield 'espace au milieu' => ['1 000'];
        yield 'zéro devant' => ['007'];
        yield 'signe plus' => ['+12'];
        yield 'deux signes' => ['--12'];
        yield 'trop grand pour PHP' => ['99999999999999999999'];
        yield 'chiffres d\'une autre écriture' => ['١٢'];
    }

    /**
     * Sécurité : PHP convertit « 12abc » en 12 et « 1e3 » en 1000. Ici, seuls
     * des chiffres font un nombre.
     */
    #[DataProvider('notIntegers')]
    public function testOnlyDigitsMakeAnInteger(string $typed): void
    {
        $v = new Validator(['age' => $typed]);

        self::assertNull($v->integer('age'));
        self::assertSame('Écrivez un nombre entier, en chiffres.', $v->errors()['age']);
    }

    public function testIntegersCanBeNegativeOrZero(): void
    {
        $v = new Validator(['a' => '-12', 'b' => '0', 'c' => ' 7 ']);

        self::assertSame(-12, $v->integer('a'));
        self::assertSame(0, $v->integer('b'));
        self::assertSame(7, $v->integer('c'));
    }

    /**
     * @return iterable<string, array{string, int|null, int|null, string}>
     */
    public static function outOfRange(): iterable
    {
        yield 'sous le minimum' => ['17', 18, null, 'Écrivez un nombre à partir de 18.'];
        yield 'au-dessus du maximum' => ['121', null, 120, 'Écrivez un nombre jusqu\'à 120.'];
        yield 'hors des deux bornes' => ['17', 18, 120, 'Écrivez un nombre entre 18 et 120.'];
    }

    #[DataProvider('outOfRange')]
    public function testANumberCanBeBounded(string $typed, ?int $min, ?int $max, string $expected): void
    {
        $v = new Validator(['age' => $typed]);

        self::assertNull($v->integer('age', min: $min, max: $max));
        self::assertSame($expected, $v->errors()['age']);
    }

    public function testBoundsAreIncluded(): void
    {
        $v = new Validator(['a' => '18', 'b' => '120']);

        self::assertSame(18, $v->integer('a', min: 18, max: 120));
        self::assertSame(120, $v->integer('b', min: 18, max: 120));
    }

    public function testADecimalAcceptsACommaOrADot(): void
    {
        $v = new Validator(['a' => '12,5', 'b' => '12.5', 'c' => '12', 'd' => '-0,25']);

        self::assertSame(12.5, $v->decimal('a'));
        self::assertSame(12.5, $v->decimal('b'));
        self::assertSame(12.0, $v->decimal('c'));
        self::assertSame(-0.25, $v->decimal('d'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notDecimals(): iterable
    {
        yield 'lettres' => ['douze'];
        yield 'notation scientifique' => ['1e3'];
        yield 'deux virgules' => ['1,2,3'];
        yield 'séparateur de milliers' => ['1 234,5'];
        yield 'virgule finale' => ['12,'];
        yield 'infini' => ['INF'];
        yield 'pas un nombre' => ['NAN'];
        yield 'symbole' => ['12 €'];
    }

    #[DataProvider('notDecimals')]
    public function testADecimalHasAStrictForm(string $typed): void
    {
        $v = new Validator(['prix' => $typed]);

        self::assertNull($v->decimal('prix'));
        self::assertTrue($v->fails());
    }

    public function testADecimalBoundIsWrittenTheFrenchWay(): void
    {
        $v = new Validator(['prix' => '0,2']);
        $v->decimal('prix', min: 0.5);

        self::assertSame('Écrivez un nombre à partir de 0,5.', $v->errors()['prix']);
    }

    // --- Adresse e-mail, liste, case, date -------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function badEmails(): iterable
    {
        yield 'sans arobase' => ['alice.exemple.com'];
        yield 'sans domaine' => ['alice@'];
        yield 'espace' => ['alice @exemple.com'];
        yield 'deux arobases' => ['a@b@exemple.com'];
        yield 'en-tête glissé' => ["alice@exemple.com\nBcc: pirate@exemple.com"];
        yield 'démesurée' => [str_repeat('a', 250) . '@exemple.com'];
        yield 'balise' => ['<script>@exemple.com'];
    }

    #[DataProvider('badEmails')]
    public function testAMalformedEmailIsRefused(string $typed): void
    {
        $v = new Validator(['email' => $typed]);

        self::assertSame('', $v->email('email'));
        self::assertTrue($v->fails());
    }

    /**
     * Sécurité : la liste montrée par le formulaire ne protège rien. Un
     * visiteur envoie ce qu'il veut ; seule la liste donnée ici compte.
     */
    public function testAChoiceMustBeOneOfTheAllowedValues(): void
    {
        $v = new Validator(['role' => 'admin', 'casse' => 'Lecteur', 'nombre' => '1']);

        self::assertSame('', $v->choice('role', ['lecteur', 'auteur']));
        self::assertSame('', $v->choice('casse', ['lecteur', 'auteur']), 'La casse compte.');
        self::assertSame('1', $v->choice('nombre', ['1', '2']));
        self::assertSame('Choisissez une des valeurs proposées.', $v->errors()['role']);
    }

    public function testAnUncheckedBoxIsNeverAnError(): void
    {
        $v = new Validator(['a' => '1', 'b' => 'on', 'c' => '0', 'd' => ['1'], 'e' => 'autre']);

        self::assertTrue($v->checkbox('a'));
        self::assertTrue($v->checkbox('b'));
        self::assertFalse($v->checkbox('c'));
        self::assertFalse($v->checkbox('d'));
        self::assertFalse($v->checkbox('e'));
        self::assertFalse($v->checkbox('absente'));
        self::assertFalse($v->fails());
        self::assertFalse($v->values()['absente']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badDates(): iterable
    {
        yield '31 février' => ['2026-02-31'];
        yield '13e mois' => ['2026-13-01'];
        yield 'jour zéro' => ['2026-10-00'];
        yield 'à la française' => ['04/10/2026'];
        yield 'avec une heure' => ['2026-10-04 12:00'];
        yield 'en mots' => ['tomorrow'];
        yield 'année courte' => ['26-10-04'];
        yield '29 février d\'une année ordinaire' => ['2026-02-29'];
    }

    /**
     * PHP accepte « tomorrow » et reporte le 31 février en mars. Ici, une
     * date est une date qui existe, écrite comme l'envoie un champ de date.
     */
    #[DataProvider('badDates')]
    public function testADateMustExist(string $typed): void
    {
        $v = new Validator(['jour' => $typed]);

        self::assertNull($v->date('jour'));
        self::assertTrue($v->fails());
    }

    public function testADateCanBeBounded(): void
    {
        $min = new \DateTimeImmutable('2026-01-01');
        $max = new \DateTimeImmutable('2026-12-31');
        $v = new Validator(['a' => '2025-12-31', 'b' => '2027-01-01', 'c' => '2026-01-01', 'd' => '2024-02-29']);

        $v->date('a', min: $min);
        $v->date('b', max: $max);

        self::assertSame('Choisissez une date à partir du 01/01/2026.', $v->errors()['a']);
        self::assertSame('Choisissez une date jusqu\'au 31/12/2026.', $v->errors()['b']);
        self::assertNotNull($v->date('c', min: $min, max: $max), 'La borne est comprise.');
        self::assertNotNull($v->date('d'), 'Le 29 février d\'une année bissextile existe.');
    }

    // --- Mots de passe --------------------------------------------------------------

    public function testAPasswordHasAMinimumLength(): void
    {
        $v = new Validator(['a' => '1234567', 'b' => '12345678']);

        self::assertSame('', $v->password('a'));
        self::assertSame('12345678', $v->password('b'));
        self::assertSame('Écrivez au moins 8 caractères.', $v->errors()['a']);
    }

    /**
     * Sécurité : un mot de passe ne se réaffiche jamais, qu'il soit accepté ou non.
     */
    public function testAPasswordIsNeverGivenBackToRedisplay(): void
    {
        $v = new Validator(['nom' => 'Alice', 'bon' => 'mot de passe correct', 'court' => 'abc']);
        $v->text('nom');
        $v->password('bon');
        $v->password('court');

        self::assertSame(['nom' => 'Alice'], $v->input());
        self::assertStringNotContainsString('abc', implode(' ', $v->errors()));
    }

    // --- Sécurité : ce qui n'est pas un texte propre ------------------------------------

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notTexts(): iterable
    {
        yield 'tableau' => [['x']];
        yield 'tableau de tableaux' => [['a' => ['b' => 'c']]];
        yield 'nombre' => [12];
        yield 'vrai' => [true];
        yield 'objet' => [new \stdClass()];
    }

    /**
     * Sécurité : « nom[]=x » envoie un tableau. trim() échouerait dessus ;
     * ici, c'est une erreur du formulaire, proprement signalée.
     */
    #[DataProvider('notTexts')]
    public function testAFieldThatIsNotATextIsRefused(mixed $value): void
    {
        foreach (['text', 'longText', 'integer', 'decimal', 'email', 'date'] as $method) {
            $v = new Validator(['champ' => $value]);
            $v->$method('champ');

            self::assertSame(['champ' => 'Ce champ n\'a pas la forme attendue.'], $v->errors(), $method);
            self::assertSame(['champ' => ''], $v->input(), $method);
        }

        $v = new Validator(['champ' => $value]);

        self::assertSame('', $v->choice('champ', ['x']));
        self::assertSame('', $v->password('champ'));
        self::assertTrue($v->fails());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dirtyTexts(): iterable
    {
        yield 'octet nul' => ["Alice\0"];
        yield 'séquence d\'échappement' => ["Alice\e[2J"];
        yield 'retour arrière' => ["Alice\x08"];
        yield 'UTF-8 invalide' => ["Alice\xC3\x28"];
        yield 'UTF-8 tronqué' => ["Alice\xE2\x82"];
    }

    /**
     * Sécurité : ces caractères abîment les pages, les journaux et les
     * fichiers. Ils ne sont ni gardés, ni réaffichés.
     */
    #[DataProvider('dirtyTexts')]
    public function testControlCharactersAndInvalidUtf8AreRefused(string $value): void
    {
        foreach (['text', 'longText', 'email'] as $method) {
            $v = new Validator(['champ' => $value]);

            self::assertSame('', $v->$method('champ'), $method);
            self::assertSame(['champ' => 'Ce champ contient des caractères qui ne sont pas acceptés.'], $v->errors(), $method);
            self::assertSame(['champ' => ''], $v->input(), $method);
        }

        $v = new Validator(['champ' => $value . ' suffisamment long']);

        self::assertSame('', $v->password('champ'));
    }

    /**
     * Sécurité : un message d'erreur est affiché dans la page. Il ne recopie
     * jamais ce que le visiteur a envoyé.
     */
    public function testAnErrorMessageNeverRepeatsTheValue(): void
    {
        $attack = '<script>alert(1)</script>';
        $v = new Validator(array_fill_keys(['a', 'b', 'c', 'd', 'e', 'f'], $attack));

        $v->text('a', max: 5);
        $v->integer('b');
        $v->decimal('c');
        $v->email('d');
        $v->choice('e', ['x']);
        $v->date('f');

        self::assertCount(6, $v->errors());
        self::assertStringNotContainsString('script', implode(' ', $v->errors()));
    }

    // --- Messages et règles de l'application --------------------------------------------

    public function testAMessageCanBeReplaced(): void
    {
        $v = new Validator(['age' => 'x']);
        $v->text('nom', message: 'Comment vous appelez-vous ?');
        $v->integer('age', message: 'Votre âge, en chiffres.');

        self::assertSame(['nom' => 'Comment vous appelez-vous ?', 'age' => 'Votre âge, en chiffres.'], $v->errors());
    }

    public function testAnApplicationRuleAddsAnError(): void
    {
        $v = new Validator(['email' => 'alice@exemple.com']);
        $email = $v->email('email');

        $v->check('email', $email !== 'alice@exemple.com', 'Cette adresse est déjà utilisée.');

        self::assertSame(['email' => 'Cette adresse est déjà utilisée.'], $v->errors());
        self::assertSame([], $v->values(), 'Un champ refusé ne compte plus parmi les valeurs.');
        self::assertSame(['email' => 'alice@exemple.com'], $v->input(), 'Mais il est réaffiché.');
    }

    public function testTheFirstErrorOfAFieldIsTheOneShown(): void
    {
        $v = new Validator([]);
        $v->text('nom');
        $v->check('nom', false, 'Autre reproche.');

        self::assertSame(['nom' => 'Ce champ est obligatoire.'], $v->errors());
    }

    public function testARuleThatHoldsChangesNothing(): void
    {
        $v = new Validator(['nom' => 'Alice']);
        $v->text('nom');
        $v->check('nom', true, 'Jamais affiché.');

        self::assertFalse($v->fails());
    }

    // --- Réafficher ------------------------------------------------------------------------

    public function testWhatWasTypedIsGivenBackEvenWhenRefused(): void
    {
        $v = new Validator(['nom' => '  Alice  ', 'age' => 'douze', 'sujet' => 'autre']);
        $v->text('nom');
        $v->integer('age');
        $v->choice('sujet', ['devis']);
        $v->text('absent', required: false);

        self::assertSame(['nom' => 'Alice', 'age' => 'douze', 'sujet' => 'autre', 'absent' => ''], $v->input());
    }

    // --- Ce qu'on lui donne -------------------------------------------------------------------

    /**
     * getParsedBody() rend null sans formulaire, et parfois un objet : dans
     * les deux cas, il n'y a simplement aucun champ.
     */
    public function testWithoutAFormThereIsSimplyNoField(): void
    {
        foreach ([null, new \stdClass()] as $data) {
            $v = new Validator($data);
            $v->text('nom');

            self::assertSame(['nom' => 'Ce champ est obligatoire.'], $v->errors());
        }
    }
}
