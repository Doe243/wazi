<?php

declare(strict_types=1);

namespace Wazi\View;

use Wazi\Http\CsrfToken;
use Wazi\View\Exception\KiooException;
use Wazi\View\Expression\Evaluator;
use Wazi\View\Template\Attribute;
use Wazi\View\Template\Element;
use Wazi\View\Template\Interpolation;
use Wazi\View\Template\Raw;
use Wazi\View\Template\StaticElement;
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
 *     <k:json id="d" value="{notes}"> met une valeur à la disposition du JavaScript
 *
 * Sécurité (ADR-019) :
 *   - aucune valeur n'est écrite sans échappement, sauf par le filtre
 *     « unsafe_raw », dans le texte uniquement ;
 *   - un template inclus ne voit que les variables qu'on lui passe, et celles
 *     que l'application partage avec tous les templates.
 */
final readonly class Renderer
{
    /** Au-delà de cette profondeur d'inclusion, c'est une boucle. */
    private const int MAX_DEPTH = 20;

    /** Le nom du bloc qui reçoit tout ce qui, dans une page, n'est pas dans un <k:block>. */
    private const string CONTENT_BLOCK = 'content';

    /**
     * @param string|null          $scriptNonce le jeton à poser sur les balises <script> des templates (voir CspNonce), ou null
     * @param CsrfToken|null       $csrf        le jeton de protection à ajouter aux formulaires, ou null
     * @param array<string, mixed> $shared      les variables que tous les templates voient, morceaux inclus compris
     */
    public function __construct(
        private Evaluator $evaluator,
        private TemplateLoader $loader,
        private ?string $scriptNonce = null,
        private ?CsrfToken $csrf = null,
        public array $shared = [],
    ) {}

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
        $previous = null;

        foreach ($nodes as $node) {
            if ($node instanceof StaticElement) {
                // Rien à calculer : la balise a été écrite à la lecture du template.
                $html .= $node->html;
            } elseif ($node instanceof Text) {
                $html .= $this->renderText($node, $variables, $template);
            } elseif ($node instanceof Element) {
                // Le retour à la ligne et l'indentation qui précèdent une
                // boucle : elle les répète entre ses tours, pour que la page
                // produite garde une balise par ligne.
                $indentation = $node->loop !== null && $previous instanceof Text ? self::trailingIndentation($previous) : '';
                $html .= $this->renderStructure($node, $variables, $template, $blocks, $depth, $indentation);
            } elseif ($node instanceof Raw) {
                $html .= $node->source;
            }

            $previous = $node;
        }

        return $html;
    }

    /**
     * Applique k:for ou k:if avant d'écrire la balise.
     *
     * @param array<string, mixed>  $variables
     * @param array<string, string> $blocks
     */
    private function renderStructure(Element $element, array $variables, string $template, array $blocks, int $depth, string $indentation): string
    {
        if ($element->loop !== null) {
            return $this->renderLoop($element, $variables, $template, $blocks, $depth, $indentation);
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
    private function renderLoop(Element $element, array $variables, string $template, array $blocks, int $depth, string $indentation): string
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

        $turns = [];

        foreach ($list as $key => $item) {
            // L'élément (et sa clé) ne sont visibles que dans cette balise :
            // ils s'ajoutent aux variables, sans les modifier pour la suite.
            $inside = [...$variables, $loop->item => $item];

            if ($loop->key !== null) {
                $inside[$loop->key] = $key;
            }

            $turns[] = $this->renderElement($element, $inside, $template, $blocks, $depth);
        }

        // Liste vide : c'est la balise k:else, s'il y en a une, qui est écrite.
        if ($turns === [] && $element->otherwise !== null) {
            return $this->renderElement($element->otherwise, $variables, $template, $blocks, $depth);
        }

        return implode($indentation, $turns);
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

        if ($name === 'k:json') {
            return $this->renderJson($element, $variables, $template);
        }

        $html = '<' . $element->name;

        foreach ($element->attributes as $attribute) {
            $html .= $this->renderAttribute($attribute, $variables, $template);
        }

        // Sécurité (ADR-014) : les balises <script> écrites dans VOS templates
        // reçoivent le jeton du jour. Le navigateur exécute les scripts qui le
        // portent, et refuse tous les autres : un script glissé dans la page
        // par un attaquant ne connaît pas le jeton.
        if ($name === 'script' && $this->scriptNonce !== null && $element->staticAttribute('nonce') === null) {
            $html .= ' nonce="' . Escaper::html($this->scriptNonce) . '"';
        }

        if ($element->selfClosing) {
            return $html . ' />';
        }

        $html .= '>';

        // Sécurité (ADR-023) : un formulaire envoyé en POST vers VOTRE site
        // reçoit le jeton qui prouve qu'il vient bien de vos pages.
        if ($name === 'form' && $this->isOwnPostForm($element, $variables, $template)) {
            $html .= '<input type="hidden" name="' . CsrfToken::FIELD . '" value="' . Escaper::html($this->csrf?->value() ?? '') . '">';
        }

        $html .= $this->renderNodes($element->children, $variables, $template, $blocks, $depth);

        return $element->closed ? $html . '</' . $element->name . '>' : $html;
    }

    /**
     * Vrai si ce formulaire doit recevoir le jeton de protection : il est
     * envoyé en POST, vers une adresse de ce site.
     *
     * Sécurité : le jeton n'est JAMAIS ajouté à un formulaire qui part vers un
     * autre site. Ce site apprendrait le jeton, et pourrait s'en servir contre
     * votre visiteur.
     *
     * @param array<string, mixed> $variables
     */
    private function isOwnPostForm(Element $element, array $variables, string $template): bool
    {
        if ($this->csrf === null || !$this->csrf->isStarted()) {
            return false;
        }

        $method = '';
        $action = '';

        foreach ($element->attributes as $attribute) {
            $attributeName = strtolower($attribute->name);

            if ($attributeName === 'method' || $attributeName === 'action') {
                $value = $this->attributeValue($attribute, $variables, $template);
                $value = is_string($value) || is_int($value) ? (string) $value : '';

                if ($attributeName === 'method') {
                    $method = $value;
                } else {
                    $action = $value;
                }
            }
        }

        return strtolower(trim($method)) === 'post' && self::isSameSite($action);
    }

    /**
     * Vrai pour une adresse de ce site : vide (la page elle-même), « /chemin »,
     * « page », « ?a=1 ». Faux dès qu'elle nomme un protocole ou un autre hôte.
     */
    private static function isSameSite(string $url): bool
    {
        // Comme pour une adresse dangereuse : les navigateurs ignorent les
        // espaces et retours à la ligne glissés au début.
        $compact = preg_replace('/[\x00-\x20\x7F]+/', '', $url) ?? '';

        // « //hote », « /\hote » et « \\hote » désignent un autre site ;
        // « https://… » et « mailto:… » aussi.
        return preg_match('#^(?:[/\\\\]{2}|[^:/?\#]*:)#', $compact) !== 1;
    }

    /**
     * <k:include file="pied" annee="{2026}"> : écrit ici un autre template.
     *
     * Sécurité : le template inclus ne reçoit QUE les attributs écrits sur la
     * balise. Il ne voit aucune des variables de la page qui l'inclut, hormis
     * celles que l'application a partagées avec tous les templates (Kioo::share()).
     *
     * @param array<string, mixed> $variables
     */
    private function renderInclude(Element $element, array $variables, string $template, int $depth): string
    {
        if ($depth >= self::MAX_DEPTH) {
            throw KiooException::at(KiooException::includeTooDeep(self::MAX_DEPTH), $template, $element->line);
        }

        $file = (string) $element->staticAttribute('file');
        $given = $this->shared;

        foreach ($element->attributes as $attribute) {
            if ($attribute->name !== 'file') {
                $given[$attribute->name] = $this->attributeValue($attribute, $variables, $template);
            }
        }

        return $this->render($this->load($file, $template, $element->line), $given, $file, $depth + 1);
    }

    /**
     * <k:json id="donnees" value="{notes}"> : met une valeur à la disposition
     * du JavaScript de la page, sans jamais l'écrire dans du code.
     *
     * La balise produite est un <script type="application/json"> : le
     * navigateur ne l'exécute pas, il la garde comme une donnée, que votre
     * script lit ainsi :
     *
     *     const notes = JSON.parse(document.getElementById('donnees').textContent);
     *
     * @param array<string, mixed> $variables
     */
    private function renderJson(Element $element, array $variables, string $template): string
    {
        $value = null;

        foreach ($element->attributes as $attribute) {
            if ($attribute->name === 'value') {
                $value = $this->attributeValue($attribute, $variables, $template);
            }
        }

        try {
            // Filters::json() écrit < > & sous forme de codes : la valeur ne
            // peut pas contenir « </script> » et refermer la balise.
            $json = Filters::json($value);
        } catch (KiooException $exception) {
            throw KiooException::at($exception, $template, $element->line);
        }

        return '<script type="application/json" id="' . Escaper::html((string) $element->staticAttribute('id')) . '">' . $json . '</script>';
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
        // Un attribut sans affichage a été écrit à la lecture du template.
        if ($attribute->static !== null) {
            return $attribute->static;
        }

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
     * Le retour à la ligne et l'indentation par lesquels un texte se termine,
     * ou '' s'il ne se termine pas ainsi.
     */
    private static function trailingIndentation(Text $text): string
    {
        $last = $text->parts === [] ? null : $text->parts[array_key_last($text->parts)];

        return is_string($last) && preg_match('/\R[ \t]*$/', $last, $match) === 1 ? $match[0] : '';
    }

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

            // Une autre balise vient en premier : pas de mise en page.
            if ($node instanceof StaticElement) {
                return null;
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
