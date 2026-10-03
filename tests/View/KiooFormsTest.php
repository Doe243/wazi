<?php

declare(strict_types=1);

namespace Wazi\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Http\CsrfToken;
use Wazi\View\Kioo;

/**
 * Le jeton de protection que Kioo ajoute de lui-même aux formulaires.
 */
final class KiooFormsTest extends TestCase
{
    private CsrfToken $token;

    protected function setUp(): void
    {
        $this->token = new CsrfToken();
        $this->token->start(null);
    }

    public function testAPostFormReceivesTheTokenAsAHiddenField(): void
    {
        $html = $this->render('<form method="post" action="/notes"><input name="texte"></form>');

        self::assertSame(
            '<form method="post" action="/notes"><input type="hidden" name="_csrf" value="' . $this->token->value() . '"><input name="texte"></form>',
            $html,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ownPostForms(): iterable
    {
        yield 'sans action : la page elle-même' => ['<form method="post">'];
        yield 'action vide' => ['<form method="post" action="">'];
        yield 'chemin' => ['<form method="post" action="/notes/3/supprimer">'];
        yield 'chemin relatif' => ['<form method="post" action="supprimer">'];
        yield 'requête seule' => ['<form method="post" action="?etape=2">'];
        yield 'majuscules' => ['<FORM METHOD="POST" ACTION="/notes">'];
        yield 'méthode avec des espaces' => ['<form method=" post " action="/notes">'];
        yield 'action calculée' => ['<form method="post" action="/notes/{id}">'];
        yield 'méthode calculée' => ['<form method="{methode}" action="/notes">'];
        yield 'deux-points après une barre' => ['<form method="post" action="/heure/12:30">'];
    }

    #[DataProvider('ownPostForms')]
    public function testAPostFormToThisSiteReceivesTheToken(string $openingTag): void
    {
        $html = $this->render($openingTag . '</form>', ['id' => 3, 'methode' => 'post']);

        self::assertSame(1, substr_count($html, 'name="_csrf"'));
        self::assertStringContainsString($this->token->value(), $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function formsThatMustNotReceiveTheToken(): iterable
    {
        yield 'formulaire GET : le jeton finirait dans l\'adresse' => ['<form method="get" action="/recherche">'];
        yield 'sans méthode (GET par défaut)' => ['<form action="/recherche">'];
        yield 'vers un autre site' => ['<form method="post" action="https://pirate.com/vol">'];
        yield 'vers un autre site, en http' => ['<form method="post" action="http://pirate.com/vol">'];
        yield 'sans protocole' => ['<form method="post" action="//pirate.com/vol">'];
        yield 'barre oblique inversée' => ['<form method="post" action="/\\pirate.com/vol">'];
        yield 'deux barres inversées' => ['<form method="post" action="\\\\pirate.com/vol">'];
        yield 'espace devant le protocole' => ['<form method="post" action=" https://pirate.com/vol">'];
        yield 'courriel' => ['<form method="post" action="mailto:pirate@exemple.com">'];
        yield 'autre site donné par une valeur' => ['<form method="post" action="{ailleurs}">'];
        yield 'autre site sans protocole, donné par une valeur' => ['<form method="post" action="{sans_protocole}">'];
    }

    /**
     * Sécurité : envoyé à un autre site, le jeton lui permettrait d'agir à la
     * place du visiteur. Dans une adresse (formulaire GET), il se retrouverait
     * dans l'historique et dans les journaux.
     */
    #[DataProvider('formsThatMustNotReceiveTheToken')]
    public function testTheTokenNeverLeavesTheSite(string $openingTag): void
    {
        $token = $this->token->value();

        $html = $this->render($openingTag . '</form>', ['ailleurs' => 'https://pirate.com/vol', 'sans_protocole' => '//pirate.com/vol']);

        self::assertStringNotContainsString('_csrf', $html);
        self::assertStringNotContainsString($token, $html);
    }

    public function testEveryFormOfThePageReceivesTheSameToken(): void
    {
        $html = $this->render('<form method="post" action="/a"></form><form method="post" action="/b"></form>');

        self::assertSame(2, substr_count($html, 'value="' . $this->token->value() . '"'));
    }

    public function testFormsInsideLoopsAndConditionsReceiveTheToken(): void
    {
        $html = $this->render('<form k:for="id in ids" method="post" action="/notes/{id}/supprimer"></form>', ['ids' => [1, 2, 3]]);

        self::assertSame(3, substr_count($html, 'name="_csrf"'));
    }

    public function testOtherElementsAreLeftAlone(): void
    {
        $html = $this->render('<div method="post"><input name="a"></div><formulaire method="post"></formulaire>');

        self::assertStringNotContainsString('_csrf', $html);
    }

    /**
     * Une page sans formulaire ne crée pas de jeton : le visiteur qui ne fait
     * que lire ne reçoit donc aucun cookie.
     */
    public function testAPageWithoutPostFormCreatesNoToken(): void
    {
        $this->render('<h1>Titre</h1><form action="/recherche"><input name="q"></form>');

        self::assertNull($this->token->toSend());
    }

    public function testAVisitorWhoAlreadyHasATokenKeepsIt(): void
    {
        $known = str_repeat('ab', 32);
        $this->token->start($known);

        $html = $this->render('<form method="post" action="/notes"></form>');

        self::assertStringContainsString('value="' . $known . '"', $html);
        self::assertNull($this->token->toSend(), 'Aucun nouveau cookie à envoyer.');
    }

    public function testWithoutTokenNothingIsAdded(): void
    {
        $template = '<form method="post" action="/notes"></form>';

        self::assertSame($template, new Kioo()->renderString($template));
    }

    public function testWithATokenThatIsNotStartedNothingIsAdded(): void
    {
        $template = '<form method="post" action="/notes"></form>';

        self::assertSame($template, new Kioo(csrf: new CsrfToken())->renderString($template));
    }

    public function testAFormComingFromAValueNeverReceivesTheToken(): void
    {
        $html = $this->render('<div>{html | unsafe_raw}</div>', ['html' => '<form method="post" action="/notes"></form>']);

        self::assertStringNotContainsString('_csrf', $html);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(string $template, array $variables = []): string
    {
        return new Kioo(csrf: $this->token)->renderString($template, $variables);
    }
}
