<?php

declare(strict_types=1);

namespace Wazi\View;

use Wazi\View\Exception\KiooException;
use Wazi\View\Expression\Evaluator;
use Wazi\View\Template\Attribute;
use Wazi\View\Template\Element;
use Wazi\View\Template\Interpolation;
use Wazi\View\Template\Raw;
use Wazi\View\Template\TemplateNode;
use Wazi\View\Template\Text;

/**
 * Écrit la page : parcourt l'arbre d'un template et produit le HTML final.
 *
 * Le texte fixe du template est recopié tel quel. Chaque affichage {…} est
 * calculé, puis échappé selon l'endroit où il se trouve :
 *
 *     <p>{nom}</p>                    texte          → échappé
 *     <p class="{type}">              attribut       → échappé
 *     <a href="{lien}">               adresse        → échappé, et protocole vérifié
 *     <input disabled="{bloque}">     vrai / faux    → l'attribut est écrit, ou retiré
 *
 * Sécurité (ADR-019) : il n'existe aucun moyen d'écrire une valeur sans
 * échappement, sauf le filtre « unsafe_raw », dans le texte uniquement.
 */
final readonly class Renderer
{
    public function __construct(private Evaluator $evaluator) {}

    /**
     * @param list<TemplateNode>   $nodes
     * @param array<string, mixed> $variables
     * @param string               $template  le nom du template, cité dans les messages d'erreur
     *
     * @throws KiooException si une expression ne peut pas être calculée ou affichée
     */
    public function render(array $nodes, array $variables, string $template = 'template'): string
    {
        $html = '';

        foreach ($nodes as $node) {
            $html .= match (true) {
                $node instanceof Raw => $node->source,
                $node instanceof Text => $this->renderText($node, $variables, $template),
                $node instanceof Element => $this->renderElement($node, $variables, $template),
                default => '',
            };
        }

        return $html;
    }

    // ------------------------------------------------------------------
    // Texte
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $variables
     */
    private function renderText(Text $text, array $variables, string $template): string
    {
        $html = '';

        foreach ($text->parts as $part) {
            if (is_string($part)) {
                $html .= $part;

                continue;
            }

            $value = $this->evaluate($part, $variables, $template);

            // Le seul endroit où une valeur est écrite sans échappement, et
            // seulement si le template l'a demandé par « unsafe_raw ».
            $html .= $value instanceof RawHtml
                ? $value->html
                : Escaper::html($this->toText($value, $part, $template));
        }

        return $html;
    }

    // ------------------------------------------------------------------
    // Balises et attributs
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $variables
     */
    private function renderElement(Element $element, array $variables, string $template): string
    {
        $html = '<' . $element->name;

        foreach ($element->attributes as $attribute) {
            $html .= $this->renderAttribute($attribute, $variables, $template);
        }

        if ($element->selfClosing) {
            return $html . ' />';
        }

        $html .= '>' . $this->render($element->children, $variables, $template);

        return $element->closed ? $html . '</' . $element->name . '>' : $html;
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function renderAttribute(Attribute $attribute, array $variables, string $template): string
    {
        if ($attribute->parts === null) {
            return ' ' . $attribute->name;
        }

        // Une valeur faite d'un seul affichage peut allumer ou éteindre
        // l'attribut : disabled="{bloque}" écrit « disabled » si c'est vrai,
        // et rien du tout si c'est faux ou null.
        if (count($attribute->parts) === 1 && $attribute->parts[0] instanceof Interpolation) {
            $value = $this->evaluate($attribute->parts[0], $variables, $template);

            if ($value === false || $value === null) {
                return '';
            }

            if ($value === true) {
                return ' ' . $attribute->name;
            }
        }

        $escaped = '';
        $plain = '';
        $hasValue = false;

        foreach ($attribute->parts as $part) {
            if (is_string($part)) {
                $escaped .= $part;
                // Le texte fixe du template est déjà du HTML : « &amp; » y désigne « & ».
                $plain .= html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                continue;
            }

            $value = $this->evaluate($part, $variables, $template);

            if ($value instanceof RawHtml) {
                throw KiooException::at(KiooException::rawHtmlInAttribute($attribute->name), $template, $part->line);
            }

            $text = $this->toText($value, $part, $template);
            $escaped .= Escaper::html($text);
            $plain .= $text;
            $hasValue = true;
        }

        // Sécurité : une adresse remplie par une valeur ne doit pas devenir
        // « javascript:... ». Si c'est le cas, elle est remplacée par « # » :
        // le lien ne mène nulle part, et rien ne s'exécute.
        if ($hasValue && Escaper::isUrlAttribute($attribute->name) && !Escaper::isSafeUrl($plain)) {
            $escaped = '#';
        }

        $quote = $attribute->quote === '' ? '' : $attribute->quote;

        return ' ' . $attribute->name . '=' . $quote . $escaped . $quote;
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $variables
     */
    private function evaluate(Interpolation $interpolation, array $variables, string $template): mixed
    {
        try {
            return $this->evaluator->evaluate($interpolation->expression, $variables);
        } catch (KiooException $exception) {
            throw KiooException::at($exception, $template, $interpolation->line);
        }
    }

    /**
     * Ce qui peut s'afficher : un texte, un nombre, ou un objet qui sait
     * s'écrire en texte. null n'affiche rien. Le reste est une erreur :
     * afficher « 1 » pour vrai ou « Array » pour une liste n'aide personne.
     */
    private function toText(mixed $value, Interpolation $interpolation, string $template): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            $value === null => '',
            $value instanceof \Stringable => (string) $value,
            default => throw KiooException::at(KiooException::notDisplayable(get_debug_type($value)), $template, $interpolation->line),
        };
    }
}
