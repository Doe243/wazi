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
 * lu et exécuté directement (ADR-019).
 */
final readonly class Kioo
{
    private TemplateLoader $loader;

    private Renderer $renderer;

    /**
     * @param string|null             $viewsDirectory le dossier qui contient vos fichiers .kioo
     * @param array<string, \Closure> $filters        vos propres filtres, en plus de ceux de Filters : nom => fonction
     * @param CspNonce|null           $nonce          le jeton à poser sur les balises <script> de vos templates ; le noyau le fournit lui-même
     * @param CsrfToken|null          $csrf           le jeton à ajouter à vos formulaires ; le noyau le fournit lui-même
     */
    public function __construct(?string $viewsDirectory = null, array $filters = [], ?CspNonce $nonce = null, ?CsrfToken $csrf = null)
    {
        $this->loader = new TemplateLoader($viewsDirectory);
        $this->renderer = new Renderer(
            new Evaluator([
                ...Filters::defaults(),
                ...$filters,
                // Défini en dernier : aucun filtre de l'application ne peut prendre ce nom.
                'unsafe_raw' => self::unsafeRaw(...),
            ]),
            $this->loader,
            $nonce?->value,
            $csrf,
        );
    }

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
        return $this->renderer->render($this->loader->load($name), $variables, $name);
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
        return $this->renderer->render($this->loader->parse($source, $name), $variables, $name);
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
