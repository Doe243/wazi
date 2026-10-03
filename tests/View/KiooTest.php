<?php

declare(strict_types=1);

namespace Wazi\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Tests\View\Fixtures\Note;
use Wazi\View\Exception\KiooException;
use Wazi\View\Kioo;

/**
 * Chaque test écrit un template comme dans un fichier .kioo, et vérifie la page produite.
 */
final class KiooTest extends TestCase
{
    // --- Le HTML ordinaire traverse Kioo sans changer ----------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function plainHtml(): iterable
    {
        yield 'texte seul' => ['Bonjour le monde'];
        yield 'balises imbriquées' => ['<div><p>Un <strong>mot</strong> important.</p></div>'];
        yield 'attributs' => ['<a href="/contact" class="lien principal" id=\'c\'>Contact</a>'];
        yield 'attribut sans valeur' => ['<input type="checkbox" checked disabled>'];
        yield 'attribut sans guillemets' => ['<input type=text maxlength=10>'];
        yield 'balises sans contenu' => ['<p>Ligne 1<br>Ligne 2</p><img src="a.png" alt=""><hr>'];
        yield 'balise auto-fermante' => ['<br />'];
        yield 'doctype et commentaire' => ["<!DOCTYPE html>\n<!-- un commentaire avec {accolades} -->\n<html lang=\"fr\"></html>"];
        yield 'entités' => ['<p>Fromage &amp; dessert &lt;3 &copy; 2026</p>'];
        yield 'entités dans un attribut' => ['<a href="/recherche?a=1&amp;b=2">Chercher</a>'];
        yield 'signe plus petit dans le texte' => ['<p>3 < 4 et 5 > 2</p>'];
        yield 'balises non fermées, comme HTML le permet' => ['<ul><li>Un<li>Deux</ul><p>Paragraphe'];
        yield 'script recopié tel quel' => ['<script>if (a < b) { x = {}; y = "</p>"; }</script>'];
        yield 'style recopié tel quel' => ['<style>body { margin: 0 } a > b { color: red }</style>'];
        yield 'majuscules conservées' => ['<DIV Class="x">Texte</DIV>'];
        yield 'retours à la ligne et indentation' => ["<ul>\n    <li>Un</li>\n\t<li>Deux</li>\n</ul>\n"];
        yield 'page complète' => ["<!DOCTYPE html>\n<html lang=\"fr\">\n<head>\n<meta charset=\"utf-8\">\n<title>Page</title>\n</head>\n<body>\n<h1>Titre</h1>\n</body>\n</html>\n"];
    }

    #[DataProvider('plainHtml')]
    public function testPlainHtmlComesOutUnchanged(string $html): void
    {
        self::assertSame($html, new Kioo()->renderString($html));
    }

    // --- Affichage ---------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function templates(): iterable
    {
        yield 'dans le texte' => ['<h1>{titre}</h1>', '<h1>Mon carnet</h1>'];
        yield 'plusieurs affichages' => ['<p>{prenom} {nom}, {age} ans</p>', '<p>René Mumba, 30 ans</p>'];
        yield 'espaces dans les accolades' => ['<p>{  titre  }</p>', '<p>Mon carnet</p>'];
        yield 'expression complète' => ['<p>{age >= 18 ? "majeur" : "mineur"}</p>', '<p>majeur</p>'];
        yield 'comparaison avec < dans le texte' => ['<p>{age < 18 ? "mineur" : "majeur"}</p>', '<p>majeur</p>'];
        yield 'accolade fermante dans un texte' => ["<p>{age > 0 ? '}' : '{'}</p>", '<p>}</p>'];
        yield 'filtre' => ['<p>{titre | upper}</p>', '<p>MON CARNET</p>'];
        yield 'objet' => ['<p>{note.texte} ({note.resume(3)})</p>', '<p>Acheter du pain (Ach)</p>'];
        yield 'nombre à virgule' => ['<p>{prix}</p>', '<p>12.5</p>'];
        yield 'null n\'affiche rien' => ['<p>[{rien}]</p>', '<p>[]</p>'];
        yield 'objet qui sait s\'écrire' => ['<p>{date}</p>', '<p>3 octobre</p>'];
        yield 'sur plusieurs lignes' => ["<p>{\n  titre\n  | upper\n}</p>", '<p>MON CARNET</p>'];
        yield 'en dehors de toute balise' => ['{titre} !', 'Mon carnet !'];

        yield 'dans un attribut' => ['<p class="{type}">x</p>', '<p class="important">x</p>'];
        yield 'mêlé à du texte fixe' => ['<p class="note {type} grande">x</p>', '<p class="note important grande">x</p>'];
        yield 'dans une adresse' => ['<a href="/notes/{note.id}?page={age}">x</a>', '<a href="/notes/7?page=30">x</a>'];
        yield 'guillemets simples conservés' => ["<p class='{type}'>x</p>", "<p class='important'>x</p>"];
        yield 'guillemets doubles dans l\'expression d\'un attribut' => ['<p class="{age > 18 ? "adulte" : "enfant"}">x</p>', '<p class="adulte">x</p>'];
        yield 'attribut data-' => ['<li data-id="{note.id}">x</li>', '<li data-id="7">x</li>'];

        yield 'accolade écrite telle quelle' => ['<p>\{titre} et \{</p>', '<p>{titre} et {</p>'];
        yield 'accolade écrite telle quelle dans un attribut' => ['<p title="\{x}">y</p>', '<p title="{x}">y</p>'];
        yield 'barre inversée ordinaire' => ['<p>C:\Sites\wazi</p>', '<p>C:\Sites\wazi</p>'];
        yield 'accolades dans un script : jamais interprétées' => ['<script>var a = {titre};</script>', '<script>var a = {titre};</script>'];
        yield 'accolades dans un commentaire : jamais interprétées' => ['<!-- {titre} -->', '<!-- {titre} -->'];
    }

    #[DataProvider('templates')]
    public function testItDisplaysValues(string $template, string $expected): void
    {
        self::assertSame($expected, $this->render($template));
    }

    // --- Attributs allumés ou éteints --------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function booleanAttributes(): iterable
    {
        yield 'vrai : l\'attribut est écrit' => ['<input disabled="{oui}">', '<input disabled>'];
        yield 'faux : l\'attribut disparaît' => ['<input disabled="{non}">', '<input>'];
        yield 'null : l\'attribut disparaît' => ['<input value="{rien}">', '<input>'];
        yield 'condition' => ['<option selected="{age == 30}">30</option>', '<option selected>30</option>'];
        yield 'texte vide : l\'attribut reste' => ['<input value="{vide}">', '<input value="">'];
        yield 'zéro : l\'attribut reste' => ['<input value="{zero}">', '<input value="0">'];
        yield 'les autres attributs ne bougent pas' => ['<input type="text" disabled="{non}" name="a">', '<input type="text" name="a">'];
    }

    #[DataProvider('booleanAttributes')]
    public function testAnAttributeMadeOfOneValueCanBeSwitchedOnOrOff(string $template, string $expected): void
    {
        self::assertSame($expected, $this->render($template));
    }

    // --- Sécurité : échappement --------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function attacks(): iterable
    {
        yield 'balise script' => ['<script>alert(1)</script>'];
        yield 'image piégée' => ['<img src=x onerror=alert(1)>'];
        yield 'sortie d\'attribut, guillemets doubles' => ['" onmouseover="alert(1)'];
        yield 'sortie d\'attribut, guillemets simples' => ["' onmouseover='alert(1)"];
        yield 'fermeture de balise' => ['"><script>alert(1)</script>'];
        yield 'fermeture de la balise textarea' => ['</textarea><script>alert(1)</script>'];
        yield 'entité déjà écrite' => ['&lt;script&gt;'];
        yield 'accolades : jamais réinterprétées' => ['{secret}'];
    }

    /**
     * Une valeur ne peut jamais ajouter une balise ni sortir de son attribut.
     */
    #[DataProvider('attacks')]
    public function testAValueCanNeverBecomeMarkup(string $attack): void
    {
        $html = new Kioo()->renderString(
            '<p title="{valeur}" class=\'{valeur}\'>{valeur}</p><textarea>{valeur}</textarea><title>{valeur}</title>',
            ['valeur' => $attack, 'secret' => 'mot-de-passe'],
        );

        self::assertSame(1, substr_count($html, '<p '), 'Une seule balise <p>.');
        self::assertSame(1, substr_count($html, '</textarea>'));
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('mot-de-passe', $html);
        self::assertSame($attack, html_entity_decode(self::between($html, '<textarea>', '</textarea>'), ENT_QUOTES | ENT_HTML5));

        // On relit la page comme le ferait un navigateur : la balise <p> ne doit
        // avoir que ses deux attributs, et chacun doit contenir la valeur entière.
        $paragraph = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><body>' . $html, LIBXML_NOERROR)->querySelector('p');

        self::assertNotNull($paragraph);
        self::assertSame(2, $paragraph->attributes->length, 'Aucun attribut n\'a été ajouté.');
        self::assertSame($attack, $paragraph->getAttribute('title'));
        self::assertSame($attack, $paragraph->getAttribute('class'));
        self::assertSame($attack, $paragraph->textContent);
        self::assertSame(0, $paragraph->children->length, 'Aucune balise n\'a été ajoutée dans le paragraphe.');
    }

    public function testInvalidBytesDoNotEmptyThePage(): void
    {
        self::assertSame('<p>a' . "\u{FFFD}" . 'b</p>', new Kioo()->renderString('<p>{valeur}</p>', ['valeur' => "a\xFFb"]));
    }

    public function testFixedTextOfTheTemplateIsNeverEscaped(): void
    {
        self::assertSame('<p>&copy; <em>René</em> &amp; fils</p>', $this->render('<p>&copy; <em>{prenom}</em> &amp; fils</p>'));
    }

    // --- Sécurité : adresses -----------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function dangerousUrls(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'majuscules' => ['JaVaScRiPt:alert(1)'];
        yield 'tabulation dans le protocole' => ["java\tscript:alert(1)"];
        yield 'retour à la ligne dans le protocole' => ["java\nscript:alert(1)"];
        yield 'espaces devant' => ['   javascript:alert(1)'];
        yield 'caractère de contrôle devant' => ["\x01javascript:alert(1)"];
        yield 'data' => ['data:text/html,<script>alert(1)</script>'];
        yield 'vbscript' => ['vbscript:msgbox(1)'];
        yield 'protocole inconnu' => ['steam://run/1'];
    }

    #[DataProvider('dangerousUrls')]
    public function testADangerousUrlIsReplaced(string $url): void
    {
        $html = new Kioo()->renderString(
            '<a href="{url}">a</a><img src="{url}"><form action="{url}"></form><button formaction=\'{url}\'>b</button>',
            ['url' => $url],
        );

        self::assertSame('<a href="#">a</a><img src="#"><form action="#"></form><button formaction=\'#\'>b</button>', $html);
    }

    public function testADangerousUrlBuiltFromSeveralPartsIsReplaced(): void
    {
        $html = new Kioo()->renderString('<a href="{debut}script:{fin}">a</a>', ['debut' => 'java', 'fin' => 'alert(1)']);

        self::assertSame('<a href="#">a</a>', $html);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function safeUrls(): iterable
    {
        yield 'chemin' => ['/notes/3', '/notes/3'];
        yield 'chemin relatif' => ['page.html', 'page.html'];
        yield 'ancre' => ['#haut', '#haut'];
        yield 'requête' => ['?page=2&tri=date', '?page=2&amp;tri=date'];
        yield 'https' => ['https://exemple.com/a?b=1', 'https://exemple.com/a?b=1'];
        yield 'http' => ['http://exemple.com', 'http://exemple.com'];
        yield 'sans protocole' => ['//exemple.com/a', '//exemple.com/a'];
        yield 'courriel' => ['mailto:contact@exemple.com', 'mailto:contact@exemple.com'];
        yield 'téléphone' => ['tel:+243000000000', 'tel:+243000000000'];
        yield 'deux-points après une barre' => ['/heure/12:30', '/heure/12:30'];
        yield 'deux-points dans la requête' => ['recherche?q=a:b', 'recherche?q=a:b'];
        yield 'vide' => ['', ''];
    }

    #[DataProvider('safeUrls')]
    public function testASafeUrlIsKept(string $url, string $expected): void
    {
        self::assertSame('<a href="' . $expected . '">a</a>', new Kioo()->renderString('<a href="{url}">a</a>', ['url' => $url]));
    }

    public function testAnAddressWrittenInTheTemplateIsNeverTouched(): void
    {
        $template = '<a href="javascript:void(0)">a</a>';

        self::assertSame($template, $this->render($template), 'Ce que VOUS écrivez dans le template reste votre décision.');
    }

    public function testOtherAttributesAreNotTreatedAsAddresses(): void
    {
        self::assertSame('<p title="javascript:alert(1)">x</p>', new Kioo()->renderString('<p title="{t}">x</p>', ['t' => 'javascript:alert(1)']));
    }

    // --- Sécurité : endroits où l'affichage est refusé ---------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forbiddenPlaces(): iterable
    {
        yield 'attribut d\'événement' => ['<button onclick="supprimer({note.id})">x</button>', 'attribut d\'événement « onclick »'];
        yield 'attribut d\'événement en majuscules' => ['<body ONLOAD="{code}">', 'attribut d\'événement « ONLOAD »'];
        yield 'attribut style' => ['<p style="color: {couleur}">x</p>', 'l\'attribut « style »'];
        yield 'attribut srcdoc' => ['<iframe srcdoc="{html}"></iframe>', 'l\'attribut « srcdoc »'];
        yield 'attribut sans guillemets' => ['<p class={type}>x</p>', 'n\'est pas entre guillemets'];
        yield 'nom d\'attribut' => ['<p {attribut}="x">y</p>', 'dans le nom d\'un attribut'];
    }

    #[DataProvider('forbiddenPlaces')]
    public function testDisplayingAValueIsRefusedWhereNoEscapingIsSafe(string $template, string $expectedHint): void
    {
        try {
            $this->render($template);
            self::fail('Une exception était attendue.');
        } catch (KiooException $exception) {
            self::assertStringContainsString('Kioo refuse d\'afficher une valeur', $exception->getMessage());
            self::assertStringContainsString($expectedHint, $exception->getMessage());
        }
    }

    public function testAnEventAttributeWithoutValueToDisplayIsAllowed(): void
    {
        $template = '<button onclick="ouvrir()" style="color: red">x</button>';

        self::assertSame($template, $this->render($template));
    }

    public function testAnEscapedBraceIsAllowedInAnEventAttribute(): void
    {
        self::assertSame('<button onclick="if (a) { b() }">x</button>', $this->render('<button onclick="if (a) \{ b() }">x</button>'));
    }

    // --- Sécurité : sortie non échappée ------------------------------------

    public function testUnsafeRawWritesHtmlAsIs(): void
    {
        self::assertSame('<div><em>brut</em></div>', new Kioo()->renderString('<div>{html | unsafe_raw}</div>', ['html' => '<em>brut</em>']));
    }

    public function testUnsafeRawIsRefusedInAnAttribute(): void
    {
        $this->expectException(KiooException::class);
        $this->expectExceptionMessage('ne peut pas servir dans l\'attribut « title »');

        new Kioo()->renderString('<p title="{html | unsafe_raw}">x</p>', ['html' => '" onclick="alert(1)']);
    }

    public function testUnsafeRawOnlyAcceptsText(): void
    {
        $this->expectException(KiooException::class);
        $this->expectExceptionMessage('un texte contenant du HTML');

        new Kioo()->renderString('<p>{liste | unsafe_raw}</p>', ['liste' => ['a']]);
    }

    /**
     * Un filtre de l'application ne peut pas prendre le nom « unsafe_raw »
     * pour en changer le sens.
     */
    public function testAnApplicationFilterCannotReplaceUnsafeRaw(): void
    {
        $kioo = new Kioo(['unsafe_raw' => static fn(mixed $value): string => 'détourné', 'euros' => static fn(mixed $value): string => '12 €']);

        self::assertSame('<p><b>x</b> 12 €</p>', $kioo->renderString('<p>{html | unsafe_raw} {prix | euros}</p>', ['html' => '<b>x</b>', 'prix' => 12]));
    }

    // --- Erreurs pédagogiques ----------------------------------------------

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function failingTemplates(): iterable
    {
        yield 'variable inconnue' => ["<h1>Titre</h1>\n<p>{tittre}</p>", 2, 'Vouliez-vous écrire « titre » ?'];
        yield 'variable inconnue dans un attribut' => ["<p>a</p>\n\n<p class=\"{typ}\">b</p>", 3, 'Vouliez-vous écrire « type » ?'];
        yield 'expression mal écrite' => ["<p>\n{age +}\n</p>", 2, 'est mal écrite'];
        yield 'accolades vides' => ['<p>{}</p>', 1, 'sont vides'];
        yield 'accolades avec des espaces' => ['<p>{   }</p>', 1, 'sont vides'];
        yield 'accolade non fermée' => ["<p>a</p>\n<p>{titre</p>", 2, 'il manque « } »'];
        yield 'booléen affiché' => ['<p>{oui}</p>', 1, 'de type bool'];
        yield 'liste affichée' => ['<p>{liste}</p>', 1, 'de type array'];
        yield 'objet affiché' => ['<p>{note}</p>', 1, 'ne sait pas afficher'];
        yield 'balise fermante en trop' => ["<div>\n<p>a</p>\n</section>", 3, '</section> ne ferme aucune balise'];
        yield 'commentaire non terminé' => ["<p>a</p>\n<!-- oubli", 2, 'il manque « --> »'];
        yield 'guillemet non refermé' => ["<p>a</p>\n<p class=\"x>b</p>", 2, 'La valeur de l\'attribut « class »'];
        yield 'balise non terminée' => ['<p class="x"', 1, 'il manque « > »'];
        yield 'script non terminé' => ["<p>a</p>\n<script>var a = 1;", 2, 'il manque « </script> »'];
        yield 'erreur sur la troisième ligne d\'un même texte' => ["<p>\n{titre}\n{inconnue}\n</p>", 3, 'n\'existe pas'];
    }

    #[DataProvider('failingTemplates')]
    public function testAnErrorTellsTheTemplateAndTheLine(string $template, int $line, string $expectedHint): void
    {
        try {
            new Kioo()->renderString($template, $this->variables(), 'pages/accueil.kioo');
            self::fail('Une exception était attendue.');
        } catch (KiooException $exception) {
            self::assertStringContainsString('Dans le template « pages/accueil.kioo », ligne ' . $line . ' :', $exception->getMessage());
            self::assertStringContainsString($expectedHint, $exception->getMessage());
            self::assertInstanceOf(KiooException::class, $exception->getPrevious());
        }
    }

    public function testErrorMessagesNeverContainTheValueOfAVariable(): void
    {
        $variables = ['secret' => 'mot-de-passe-tres-secret', 'liste' => ['mot-de-passe-tres-secret']];

        foreach (['<p>{secret + 1}</p>', '<p>{liste}</p>', '<p title="{secret | unsafe_raw}">x</p>', '<p>{secret.x}</p>'] as $template) {
            try {
                new Kioo()->renderString($template, $variables);
                self::fail('Une exception était attendue pour ' . $template);
            } catch (KiooException $exception) {
                self::assertStringNotContainsString('mot-de-passe', $exception->getMessage());
            }
        }
    }

    public function testAKiooEngineCanRenderSeveralTemplates(): void
    {
        $kioo = new Kioo();

        self::assertSame('<p>a</p>', $kioo->renderString('<p>{x}</p>', ['x' => 'a']));
        self::assertSame('<b>b</b>', $kioo->renderString('<b>{x}</b>', ['x' => 'b']));
    }

    // --- Outils ------------------------------------------------------------

    private function render(string $template): string
    {
        return new Kioo()->renderString($template, $this->variables());
    }

    /**
     * @return array<string, mixed>
     */
    private function variables(): array
    {
        return [
            'titre' => 'Mon carnet',
            'prenom' => 'René',
            'nom' => 'Mumba',
            'age' => 30,
            'prix' => 12.5,
            'type' => 'important',
            'oui' => true,
            'non' => false,
            'rien' => null,
            'vide' => '',
            'zero' => 0,
            'liste' => ['a', 'b'],
            'note' => new Note(),
            'date' => new class implements \Stringable {
                public function __toString(): string
                {
                    return '3 octobre';
                }
            },
        ];
    }

    private static function between(string $text, string $start, string $end): string
    {
        $from = strpos($text, $start);
        $to = strpos($text, $end);

        return $from === false || $to === false ? '' : substr($text, $from + strlen($start), $to - $from - strlen($start));
    }
}
