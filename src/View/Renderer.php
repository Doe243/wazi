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
 * Les structures décident si une balise est écrite, et combien de fois :
 *
 *     <li k:for="note in notes">      une fois par élément de la liste
 *     <p k:if="notes">                seulement si la condition est vraie
 *     <p k:else>                      sinon (condition fausse, ou liste vide)
 *
 * La mise en page assemble plusieurs fichiers :
 *
 *     <k:layout name="base">          la page se place dans base.kioo
 *     <k:block name="titre">          remplit l'emplacement « titre » de la mise en page
 *     <k:include file="pied">         écrit ici le template pied.kioo
 *
 * Sécurité (ADR-019) :
 *   - aucune valeur n'est écrite sans échappement, sauf par le filtre
 *     « unsafe_raw », dans le texte uniquement ;
 *   - un template inclus ne voit que les variables qu'on lui passe.
 */
final readonly class Renderer
{
    /** Au-delà de cette profondeur d'inclusion, c'est une boucle. */
    private const int MAX_DEPTH = 20;

    /** Le nom du bloc qui reçoit tout ce qui, dans une page, n'est pas dans un <k:block>. */
    private const string CONTENT_BLOCK = 'content';

    public function __construct(private Evaluator $evaluator, private TemplateLoader $loader) {}

    /**
     * Produit la page d'un template, mise en page comprise.
     *
     * @param list<TemplateNode>   $nodes     l'arbre du template
     * @param array<string, mixed> $variables
     * @param string               $template  le nom du template, cité dans les messages d'erreur
     *
     * @throws KiooException si une expression ne peut pas être calculée ou affichée
     */
    public function render(array $nodes, array $variables, string $template = 'template', int $depth = 0): string
    {
        $layout = self::layoutOf($nodes);

        if ($layout === null) {
            return $this->renderNodes($nodes, $variables, $template, [], $depth);
        }

        // La page utilise une mise en page. On écrit d'abord chacun de ses
        // blocs ; tout le reste forme le bloc « content ».
        $blocks = [];
        $content = [];

        foreach ($nodes as $node) {
            if ($node === $layout) {
                continue;
            }

            if ($node instanceof Element && $node->lowerName() === 'k:block') {
                $name = (string) $node->staticAttribute('name');

                if ($name === self::CONTENT_BLOCK) {
                    throw KiooException::at(KiooException::reservedBlock(), $template, $node->line);
                }

                $blocks[$name] = $this->renderNodes($node->children, $variables, $template, [], $depth);

                continue;
            }

            $content[] = $node;
        }

        $blocks[self::CONTENT_BLOCK] = trim($this->renderNodes($content, $variables, $template, [], $depth));

        // Puis on écrit la mise en page, qui place chaque bloc à son emplacement.
        $layoutName = (string) $layout->staticAttribute('name');
        $layoutNodes = $this->load($layoutName, $template, $layout->line);

        if (self::layoutOf($layoutNodes) !== null) {
            throw KiooException::at(KiooException::nestedLayout($layoutName), $template, $layout->line);
        }

        return $this->renderNodes($layoutNodes, $variables, $layoutName, $blocks, $depth);
    }

    // ------------------------------------------------------------------
    // Parcours de l'arbre
    // ------------------------------------------------------------------

    /**
     * @param list<TemplateNode>    $nodes
     * @param array<string, mixed>  $variables
     * @param array<string, string> $blocks    les blocs déjà écrits par la page, à placer dans la mise en page
     */
    private function renderNodes(array $nodes, array $variables, string $template, array $blocks, int $depth): string
    {
        $html = '';

        foreach ($nodes as $node) {
            $html .= match (true) {
                $node instanceof Raw => $node->source,
                $node instanceof Text => $this->renderText($node, $variables, $template),
                $node instanceof Element => $this->renderStructure($node, $variables, $template, $blocks, $depth),
                default => '',
            };
        }

        return $html;
    }

    /**
     * Applique k:for ou k:if avant d'écrire la balise.
     *
     * @param array<string, mixed>  $variables
     * @param array<string, string> $blocks
     */
    private function renderStructure(Element $element, array $variables, string $template, array $blocks, int $depth): string
    {
        if ($element->loop !== null) {
            return $this->renderLoop($element, $variables, $template, $blocks, $depth);
        }

        if ($element->condition === null) {
            return $this->renderElement($element, $variables, $template, $blocks, $depth);
        }

        try {
            $isTrue = $this->evaluator->isTruthy($this->evaluator->evaluate($element->condition, $variables));
        } catch (KiooException $exception) {
            throw KiooException::at($exception, $template, $element->line);
        }

        if ($isTrue) {
            return $this->renderElement($element, $variables, $template, $blocks, $depth);
        }

        return $element->otherwise !== null
            ? $this->renderElement($element->otherwise, $variables, $template, $blocks, $depth)
            : '';
    }

    /**
     * @param array<string, mixed>  $variables
     * @param array<string, string> $blocks
     */
    private function renderLoop(Element $element, array $variables, string $template, array $blocks, int $depth): string
    {
        $loop = $element->loop;

        if ($loop === null) {
            return '';
        }

        try {
            $list = $this->evaluator->evaluate($loop->list, $variables);
        } catch (KiooException $exception) {
            throw KiooException::at($exception, $template, $element->line);
        }

        if (!is_iterable($list)) {
            throw KiooException::at(KiooException::notIterable(get_debug_type($list)), $template, $element->line);
        }

        $html = '';
        $count = 0;

        foreach ($list as $key => $item) {
            // L'élément (et sa clé) ne sont visibles que dans cette balise :
            // ils s'ajoutent aux variables, sans les modifier pour la suite.
            $inside = [...$variables, $loop->item => $item];

            if ($loop->key !== null) {
                $inside[$loop->key] = $key;
            }

            $html .= $this->renderElement($element, $inside, $template, $blocks, $depth);
            $count++;
        }

        // Liste vide : c'est la balise k:else, s'il y en a une, qui est écrite.
        if ($count === 0 && $element->otherwise !== null) {
            return $this->renderElement($element->otherwise, $variables, $template, $blocks, $depth);
        }

        return $html;
    }

    // ------------------------------------------------------------------
    // Balises
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed>  $variables
     * @param array<string, string> $blocks
     */
    private function renderElement(Element $element, array $variables, string $template, array $blocks, int $depth): string
    {
        $name = $element->lowerName();

        if ($name === 'k:include') {
            return $this->renderInclude($element, $variables, $template, $depth);
        }

        if ($name === 'k:block') {
            // Dans une mise en page : le bloc de la page s'il existe, sinon le
            // contenu par défaut écrit entre les deux balises.
            return $blocks[(string) $element->staticAttribute('name')]
                ?? $this->renderNodes($element->children, $variables, $template, $blocks, $depth);
        }

        // <k:layout> ne s'écrit pas : il a déjà été traité par render().
        if ($name === 'k:layout') {
            return '';
        }

        $html = '<' . $element->name;

        foreach ($element->attributes as $attribute) {
            $html .= $this->renderAttribute($attribute, $variables, $template);
        }

        if ($element->selfClosing) {
            return $html . ' />';
        }

        $html .= '>' . $this->renderNodes($element->children, $variables, $template, $blocks, $depth);

        return $element->closed ? $html . '</' . $element->name . '>' : $html;
    }

    /**
     * <k:include file="pied" annee="{2026}"> : écrit ici un autre template.
     *
     * Sécurité : le template inclus ne reçoit QUE les attributs écrits sur la
     * balise. Il ne voit aucune des variables de la page qui l'inclut.
     *
     * @param array<string, mixed> $variables
     */
    private function renderInclude(Element $element, array $variables, string $template, int $depth): string
    {
        if ($depth >= self::MAX_DEPTH) {
            throw KiooException::at(KiooException::includeTooDeep(self::MAX_DEPTH), $template, $element->line);
        }

        $file = (string) $element->staticAttribute('file');
        $given = [];

        foreach ($element->attributes as $attribute) {
            if ($attribute->name !== 'file') {
                $given[$attribute->name] = $this->attributeValue($attribute, $variables, $template);
            }
        }

        return $this->render($this->load($file, $template, $element->line), $given, $file, $depth + 1);
    }

    /**
     * La valeur d'un attribut de <k:include>, telle qu'elle sera donnée au
     * template inclus : la valeur elle-même pour note="{note}", un texte pour titre="Note {id}".
     *
     * @param array<string, mixed> $variables
     */
    private function attributeValue(Attribute $attribute, array $variables, string $template): mixed
    {
        $parts = $attribute->parts ?? [];

        if ($attribute->parts === null) {
            return true;
        }

        if (count($parts) === 1 && $parts[0] instanceof Interpolation) {
            return $this->evaluate($parts[0], $variables, $template);
        }

        $text = '';

        foreach ($parts as $part) {
            $text .= is_string($part)
                ? html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                : $this->toText($this->evaluate($part, $variables, $template), $part, $template);
        }

        return $text;
    }

    // ------------------------------------------------------------------
    // Texte et attributs
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

        return ' ' . $attribute->name . '=' . $attribute->quote . $escaped . $attribute->quote;
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * La balise <k:layout> d'un template, si c'est sa première balise.
     *
     * @param list<TemplateNode> $nodes
     */
    private static function layoutOf(array $nodes): ?Element
    {
        foreach ($nodes as $node) {
            if ($node instanceof Element) {
                return $node->lowerName() === 'k:layout' ? $node : null;
            }
        }

        return null;
    }

    /**
     * @return list<TemplateNode>
     */
    private function load(string $name, string $from, int $line): array
    {
        try {
            return $this->loader->load($name);
        } catch (KiooException $exception) {
            // Une erreur déjà située (dans le fichier lu) garde sa place ; les
            // autres (fichier introuvable) sont situées sur la balise qui l'a demandé.
            throw $exception->getPrevious() instanceof KiooException ? $exception : KiooException::at($exception, $from, $line);
        }
    }

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
