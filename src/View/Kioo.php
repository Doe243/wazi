<?php

declare(strict_types=1);

namespace Wazi\View;

use Wazi\View\Exception\KiooException;
use Wazi\View\Expression\Evaluator;
use Wazi\View\Template\TemplateParser;

/**
 * Kioo, le moteur de templates de Wazi (« kioo » : vitre, miroir en swahili).
 *
 * Un template Kioo est une page HTML ordinaire. On y affiche une valeur en
 * l'écrivant entre accolades :
 *
 *     <h1>{titre | upper}</h1>
 *     <a href="/notes/{note.id}" class="note {note.type}">{note.texte}</a>
 *
 * Le trajet d'un template, en trois étapes que vous pouvez ouvrir une à une :
 *
 *     texte du template
 *          │  TemplateParser : reconnaît balises, attributs et affichages
 *          ▼
 *     arbre du template
 *          │  Renderer : calcule chaque affichage (Evaluator) et l'échappe (Escaper)
 *          ▼
 *     page HTML
 *
 * Rien n'est traduit en PHP ni gardé en cache : le template est lu et exécuté
 * directement (ADR-019).
 */
final readonly class Kioo
{
    private TemplateParser $parser;

    private Renderer $renderer;

    /**
     * @param array<string, \Closure> $filters vos propres filtres, en plus de ceux de Filters : nom => fonction
     */
    public function __construct(array $filters = [])
    {
        $this->parser = new TemplateParser();
        $this->renderer = new Renderer(new Evaluator([
            ...Filters::defaults(),
            ...$filters,
            // Défini en dernier : aucun filtre de l'application ne peut prendre ce nom.
            'unsafe_raw' => self::unsafeRaw(...),
        ]));
    }

    /**
     * Produit une page à partir du texte d'un template.
     *
     * @param array<string, mixed> $variables ce que le template peut afficher : nom => valeur
     * @param string               $name      le nom du template, cité dans les messages d'erreur
     *
     * @throws KiooException si le template est mal écrit, ou si une expression ne peut pas être calculée
     */
    public function renderString(string $source, array $variables = [], string $name = 'template'): string
    {
        return $this->renderer->render($this->parser->parse($source, $name), $variables, $name);
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
