<?php

declare(strict_types=1);

namespace Wazi\View;

use Wazi\Http\CspNonce;
use Wazi\Http\CsrfToken;
use Wazi\Http\Response;
use Wazi\View\Exception\KiooException;
use Wazi\View\Expression\Evaluator;

/**
 * Kioo, le moteur de templates de Wazi (« kioo » : vitre, miroir en swahili).
 *
 * Un template Kioo est une page HTML ordinaire, rangée dans un fichier .kioo.
 * On y affiche une valeur entre accolades, et quelques attributs k: décident
 * de ce qui est écrit :
 *
 *     <k:layout name="base">
 *     <k:block name="titre">Mon carnet</k:block>
 *
 *     <ul>
 *         <li k:for="note in notes" class="note {note.type}">
 *             <a href="/notes/{note.id}">{note.texte | upper}</a>
 *         </li>
 *         <li k:else>Aucune note pour l'instant.</li>
 *     </ul>
 *
 *     <k:include file="partiels/pied" annee="{annee}">
 *
 * Dans un contrôleur, Kioo se demande dans le constructeur, et une méthode
 * retourne une page en une ligne :
 *
 *     public function __construct(private readonly Kioo $kioo) {}
 *
 *     public function liste(): ResponseInterface
 *     {
 *         return $this->kioo->page('notes/liste', ['notes' => $notes, 'annee' => 2026]);
 *     }
 *
 * Il suffit d'avoir indiqué le dossier des vues au noyau : new Kernel(views: __DIR__ . '/../views').
 *
 * Deux réglages se font une fois, au démarrage de l'application :
 *
 *     $kioo = $app->container->get(Kioo::class);
 *
 *     // Un filtre de plus : {prix | euros}
 *     $kioo->addFilter('euros', fn (mixed $prix) => number_format((float) $prix, 2, ',', ' ') . ' €');
 *
 *     // Une variable que TOUS les templates voient, sans la passer à chaque page.
 *     $kioo->share('annee', 2026);
 *     $kioo->share('utilisateur', fn () => $session->get('utilisateur'));   // calculée au moment d'afficher
 *
 * Le trajet d'un template, en étapes que vous pouvez ouvrir une à une :
 *
 *     nom du template
 *          │  TemplateLoader : trouve le fichier dans le dossier des vues
 *          ▼
 *     texte du template
 *          │  TemplateParser : reconnaît balises, attributs et affichages
 *          ▼
 *     arbre du template
 *          │  Renderer : calcule chaque affichage (Evaluator) et l'échappe (Escaper)
 *          ▼
 *     page HTML
 *
 * Rien n'est traduit en PHP ni gardé en cache sur le disque : le template est
 * lu et exécuté directement (ADR-019). Ce qui ne dépend d'aucune valeur est
 * préparé dès la lecture, pour ne pas être recalculé à l'affichage (ADR-025).
 */
final class Kioo
{
    /** Le nom d'un filtre ou d'une variable : des lettres, des chiffres et « _ ». */
    private const string NAME = '/^[a-zA-Z_][a-zA-Z0-9_]*$/D';

    private readonly TemplateLoader $loader;

    private Evaluator $evaluator;

    /** @var array<string, \Closure> Les filtres disponibles dans les templates : nom => fonction. */
    private array $filters;

    /** @var array<string, mixed> Les variables partagées avec tous les templates (voir share()). */
    private array $shared = [];

    private readonly ?string $scriptNonce;

    /**
     * @param string|null             $viewsDirectory le dossier qui contient vos fichiers .kioo
     * @param array<string, \Closure> $filters        vos propres filtres, en plus de ceux de Filters : nom => fonction
     * @param CspNonce|null           $nonce          le jeton à poser sur les balises <script> de vos templates ; le noyau le fournit lui-même
     * @param CsrfToken|null          $csrf           le jeton à ajouter à vos formulaires ; le noyau le fournit lui-même
     */
    public function __construct(?string $viewsDirectory = null, array $filters = [], ?CspNonce $nonce = null, private readonly ?CsrfToken $csrf = null)
    {
        $this->loader = new TemplateLoader($viewsDirectory);
        $this->scriptNonce = $nonce?->value;
        $this->filters = [
            ...Filters::defaults(),
            ...$filters,
            // Défini en dernier : aucun filtre de l'application ne peut prendre ce nom.
            'unsafe_raw' => self::unsafeRaw(...),
        ];
        $this->evaluator = new Evaluator($this->filters);
    }

    // ------------------------------------------------------------------
    // Régler Kioo, une fois, au démarrage
    // ------------------------------------------------------------------

    /**
     * Ajoute un filtre : une fonction qui transforme une valeur avant son affichage.
     *
     *     $kioo->addFilter('euros', fn (mixed $prix) => number_format((float) $prix, 2, ',', ' ') . ' €');
     *
     *     {article.prix | euros}        12,50 €
     *
     * La fonction reçoit la valeur écrite à gauche de la barre, puis les
     * arguments écrits entre parenthèses. Ce qu'elle retourne est échappé
     * comme toute valeur affichée.
     *
     * @throws KiooException si le nom est mal formé, ou déjà pris par un autre filtre
     */
    public function addFilter(string $name, \Closure $filter): void
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw KiooException::invalidName('filtre', $name);
        }

        // Sécurité : on ne remplace pas un filtre existant. Sinon, un filtre
        // ajouté par mégarde pourrait prendre la place de « unsafe_raw ».
        if (array_key_exists($name, $this->filters)) {
            throw KiooException::filterAlreadyExists($name);
        }

        $this->filters[$name] = $filter;
        $this->evaluator = new Evaluator($this->filters);
    }

    /**
     * Partage une variable avec TOUS les templates : pages, mises en page et
     * morceaux inclus. Utile pour ce que chaque page affiche (le nom du
     * visiteur dans le bandeau, l'année dans le pied de page).
     *
     *     $kioo->share('annee', 2026);
     *
     * Si la valeur n'est connue qu'au moment d'afficher la page, donnez une
     * fonction : elle est appelée une fois par page affichée.
     *
     *     $kioo->share('utilisateur', fn () => $session->get('utilisateur'));
     *
     * Une variable de même nom donnée à une page l'emporte sur celle-ci.
     *
     * @throws KiooException si le nom est mal formé
     */
    public function share(string $name, mixed $value): void
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw KiooException::invalidName('variable', $name);
        }

        $this->shared[$name] = $value;
    }

    // ------------------------------------------------------------------
    // Afficher
    // ------------------------------------------------------------------

    /**
     * Produit la réponse HTML d'un template : c'est ce qu'un contrôleur retourne.
     *
     *     return $this->kioo->page('notes/liste', ['notes' => $notes]);
     *
     * @param array<string, mixed> $variables ce que le template peut afficher : nom => valeur
     * @param int                  $status    le code de statut, 200 par défaut (404 pour une page « introuvable » maison)
     *
     * @throws KiooException si le template est introuvable ou mal écrit, ou si une expression ne peut pas être calculée
     */
    public function page(string $name, array $variables = [], int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], $this->render($name, $variables));
    }

    /**
     * Produit la page d'un template du dossier des vues.
     *
     * @param string               $name      le nom du template, sans extension : « accueil », « notes/liste »
     * @param array<string, mixed> $variables ce que le template peut afficher : nom => valeur
     *
     * @throws KiooException si le template est introuvable ou mal écrit, ou si une expression ne peut pas être calculée
     */
    public function render(string $name, array $variables = []): string
    {
        $renderer = $this->renderer();

        return $renderer->render($this->loader->load($name), [...$renderer->shared, ...$variables], $name);
    }

    /**
     * Produit une page à partir du texte d'un template, sans passer par un fichier.
     *
     * @param array<string, mixed> $variables ce que le template peut afficher : nom => valeur
     * @param string               $name      le nom du template, cité dans les messages d'erreur
     *
     * @throws KiooException si le template est mal écrit, ou si une expression ne peut pas être calculée
     */
    public function renderString(string $source, array $variables = [], string $name = 'template'): string
    {
        $renderer = $this->renderer();

        return $renderer->render($this->loader->parse($source, $name), [...$renderer->shared, ...$variables], $name);
    }

    /**
     * Ce qui écrit une page. Les variables partagées sont calculées ici, une
     * fois pour toute la page : mise en page et morceaux inclus reçoivent les mêmes.
     */
    private function renderer(): Renderer
    {
        $shared = [];

        foreach ($this->shared as $name => $value) {
            $shared[$name] = $value instanceof \Closure ? $value() : $value;
        }

        return new Renderer($this->evaluator, $this->loader, $this->scriptNonce, $this->csrf, $shared);
    }

    /**
     * Le filtre « unsafe_raw » : déclare qu'un texte est du HTML sûr, à écrire tel quel.
     */
    private static function unsafeRaw(mixed $value): RawHtml
    {
        return is_string($value)
            ? new RawHtml($value)
            : throw KiooException::filterExpects('unsafe_raw', 'un texte contenant du HTML', get_debug_type($value));
    }
}
