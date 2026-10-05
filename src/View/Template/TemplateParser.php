<?php

declare(strict_types=1);

namespace Wazi\View\Template;

use Wazi\View\Exception\KiooException;
use Wazi\View\Expression\Node;
use Wazi\View\Expression\Parser;

/**
 * Lit le texte d'un template et en construit l'arbre : balises, texte, affichages.
 *
 *     <ul class="notes">            Element(ul)
 *       <li>{note.texte}</li>         └─ Element(li)
 *     </ul>                               └─ Text[ Interpolation(note.texte) ]
 *
 * Ce n'est pas un analyseur HTML complet : il reconnaît juste ce qu'il faut
 * pour savoir OÙ se trouve chaque affichage (dans du texte, dans un attribut,
 * dans une balise <script>), car c'est l'endroit qui décide de l'échappement.
 *
 * Il avance dans le texte caractère par caractère, avec un curseur. À chaque
 * endroit, il regarde ce qui commence là : un commentaire, une balise
 * fermante, une balise ouvrante, ou du texte.
 *
 * Sécurité (ADR-019) : c'est ici qu'un affichage est REFUSÉ quand il se
 * trouve à un endroit où aucun échappement ne protège la page : attribut
 * d'événement (onclick), attribut style, attribut sans guillemets, nom d'attribut.
 */
final class TemplateParser
{
    /** Les balises qui n'ont jamais de contenu ni de balise fermante. */
    private const array VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /** Les balises dont le contenu n'est pas du HTML : il est recopié tel quel, sans y chercher d'affichage. */
    private const array RAW_TEXT_ELEMENTS = ['script', 'style'];

    private const string TAG_NAME = '/\G[a-zA-Z][a-zA-Z0-9:-]*/';

    /** Les balises de Kioo. Les deux premières n'ont jamais de contenu. */
    private const array KIOO_ELEMENTS = ['k:layout', 'k:include', 'k:block', 'k:json'];

    private const array KIOO_VOID_ELEMENTS = ['k:layout', 'k:include', 'k:json'];

    /** L'attribut que chaque balise de Kioo exige, écrit en dur. */
    private const array REQUIRED_ATTRIBUTE = ['k:layout' => 'name', 'k:block' => 'name', 'k:include' => 'file', 'k:json' => 'id'];

    /** Les attributs de Kioo. */
    private const array KIOO_ATTRIBUTES = ['k:if', 'k:else', 'k:for', 'k:zone', 'k:update'];

    /** Le nom d'une zone : liste, compteur, panier-total. */
    private const string ZONE_NAME = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D';

    private const int ZONE_NAME_MAX = 40;

    /** Les balises qui ne peuvent pas être une zone : la page entière, ou ce que le navigateur n'affiche pas. */
    private const array NEVER_A_ZONE = ['html', 'head', 'body', 'title', 'script', 'style'];

    /** k:for="note in notes" ou k:for="cle, note in notes". */
    private const string FOR = '/^\s*(?:([a-zA-Z_][a-zA-Z0-9_]*)\s*,\s*)?([a-zA-Z_][a-zA-Z0-9_]*)\s+in\s+(.+)$/Ds';

    private const string NAME = '/^[a-zA-Z_][a-zA-Z0-9_]*$/D';

    private string $source = '';

    private string $template = '';

    private int $position = 0;

    private int $length = 0;

    /** Jusqu'où les lignes ont déjà été comptées, et le numéro de ligne à cet endroit. */
    private int $countedUpTo = 0;

    private int $lineNumber = 1;

    /**
     * Les balises ouvertes et pas encore fermées, de la plus extérieure à la plus intérieure.
     *
     * @var list<array{name: string, attributes: list<Attribute>, children: list<TemplateNode>, line: int}>
     */
    private array $open = [];

    /** @var list<TemplateNode> Ce qui se trouve en dehors de toute balise. */
    private array $root = [];

    /** Vrai si le template commence par <k:layout> : ses <k:block> remplissent alors une mise en page. */
    private bool $hasLayout = false;

    /** @var array<string, int> Les zones déjà rencontrées dans ce template : nom => ligne. */
    private array $zones = [];

    public function __construct(private readonly Parser $expressions = new Parser()) {}

    /**
     * @param string $template le nom du template, cité dans les messages d'erreur
     *
     * @return list<TemplateNode>
     *
     * @throws KiooException si le template est mal écrit
     */
    public function parse(string $source, string $template = 'template'): array
    {
        $this->source = $source;
        $this->template = $template;
        $this->position = 0;
        $this->length = strlen($source);
        $this->countedUpTo = 0;
        $this->lineNumber = 1;
        $this->open = [];
        $this->root = [];
        $this->hasLayout = false;
        $this->zones = [];

        while ($this->position < $this->length) {
            match (true) {
                $this->startsWith('<!--') => $this->skipComment(),
                $this->startsWith('<!'), $this->startsWith('<?') => $this->append(new Raw($this->readUntil('>', 'La déclaration <!'))),
                $this->startsWith('</') => $this->parseClosingTag(),
                $this->isTagStart() => $this->parseOpeningTag(),
                default => $this->parseText(),
            };
        }

        // Une balise jamais fermée (HTML le permet pour <li>, <p>...) est
        // refermée à la fin, sans réécrire de balise fermante.
        while ($this->open !== []) {
            $this->closeInnermost(false);
        }

        return $this->root;
    }

    // ------------------------------------------------------------------
    // Commentaires
    // ------------------------------------------------------------------

    /**
     * Un commentaire <!-- … --> est lu, puis oublié : il n'est jamais écrit
     * dans la page.
     *
     * Sécurité (ADR-025) : un commentaire s'adresse à qui lit le template, pas
     * au visiteur. Laissé dans la page, il lui apprendrait comment le site
     * est fait, ou ce qu'il reste à corriger.
     */
    private function skipComment(): void
    {
        $start = $this->position;
        $this->readUntil('-->', 'Le commentaire <!--');

        // Un commentaire seul sur sa ligne emporte sa ligne avec lui : on
        // retire l'indentation qui le précède et le retour à la ligne qui le suit.
        $lineStart = strrpos(substr($this->source, 0, $start), "\n");
        $before = substr($this->source, $lineStart === false ? 0 : $lineStart + 1, $start - ($lineStart === false ? 0 : $lineStart + 1));
        $spaces = strspn($this->source, " \t", $this->position);
        $after = substr($this->source, $this->position + $spaces, 2);

        if (trim($before, " \t") !== '' || ($after !== '' && $after[0] !== "\n" && $after !== "\r\n")) {
            return;
        }

        $this->dropTrailingIndentation();
        $this->position += $spaces + ($after === "\r\n" ? 2 : strlen(substr($after, 0, 1)));
    }

    /**
     * Retire les espaces qui terminent le dernier texte lu (l'indentation d'une ligne).
     */
    private function dropTrailingIndentation(): void
    {
        if ($this->open === []) {
            $siblings = &$this->root;
        } else {
            $siblings = &$this->open[array_key_last($this->open)]['children'];
        }

        $last = $siblings === [] ? null : $siblings[array_key_last($siblings)];

        if (!$last instanceof Text || $last->parts === []) {
            return;
        }

        $parts = $last->parts;
        $final = $parts[array_key_last($parts)];

        if (!is_string($final)) {
            return;
        }

        $trimmed = rtrim($final, " \t");
        array_pop($parts);
        array_pop($siblings);

        if ($trimmed !== '') {
            $parts[] = $trimmed;
        }

        if ($parts !== []) {
            $siblings[] = new Text($parts);
        }
    }

    // ------------------------------------------------------------------
    // Balises
    // ------------------------------------------------------------------

    private function parseOpeningTag(): void
    {
        $line = $this->line();
        $this->position++;
        $name = $this->readTagName();
        $attributes = [];

        while (true) {
            $this->skipWhitespace();

            if ($this->position >= $this->length) {
                throw $this->error(KiooException::unterminated('La balise <' . $name, '>'), $line);
            }

            if ($this->startsWith('/>')) {
                $this->position += 2;
                $this->append(self::flattened($this->element($name, $attributes, [], true, false, $line)));

                return;
            }

            if ($this->startsWith('>')) {
                $this->position++;

                break;
            }

            // Un « / » isolé (<br / >) n'est pas un attribut : on l'ignore.
            if ($this->startsWith('/')) {
                $this->position++;

                continue;
            }

            $attributes[] = $this->parseAttribute();
        }

        $lowerName = strtolower($name);

        if (in_array($lowerName, self::VOID_ELEMENTS, true)) {
            $this->append(self::flattened($this->element($name, $attributes, [], false, false, $line)));

            return;
        }

        // <k:layout name="base">, <k:include file="pied"> et <k:json ...> s'écrivent sans balise fermante.
        if (in_array($lowerName, self::KIOO_VOID_ELEMENTS, true)) {
            $this->append($this->element($name, $attributes, [], true, false, $line));

            return;
        }

        if (in_array($lowerName, self::RAW_TEXT_ELEMENTS, true)) {
            $this->append(self::flattened($this->element($name, $attributes, [new Raw($this->readRawText($lowerName, $line))], false, true, $line)));

            return;
        }

        $this->open[] = ['name' => $name, 'attributes' => $attributes, 'children' => [], 'line' => $line];
    }

    private function parseClosingTag(): void
    {
        $line = $this->line();
        $this->position += 2;
        $name = $this->readTagName();
        $this->skipWhitespace();

        if (!$this->startsWith('>')) {
            throw $this->error(KiooException::unterminated('La balise fermante </' . $name, '>'), $line);
        }

        $this->position++;

        // On cherche, de l'intérieur vers l'extérieur, la balise ouverte que celle-ci ferme.
        $depth = null;

        for ($index = count($this->open) - 1; $index >= 0; $index--) {
            if (strtolower($this->open[$index]['name']) === strtolower($name)) {
                $depth = $index;

                break;
            }
        }

        if ($depth === null) {
            throw $this->error(KiooException::unexpectedClosingTag($name), $line);
        }

        // Les balises ouvertes entre les deux (un <li> jamais fermé avant </ul>)
        // sont refermées en passant.
        while (count($this->open) - 1 > $depth) {
            $this->closeInnermost(false);
        }

        $this->closeInnermost(true);
    }

    /**
     * Termine la balise ouverte la plus intérieure et la range dans son parent.
     */
    private function closeInnermost(bool $closed): void
    {
        $frame = array_pop($this->open);

        if ($frame === null) {
            return;
        }

        $this->append(self::flattened($this->element($frame['name'], $frame['attributes'], $frame['children'], false, $closed, $frame['line'])));
    }

    /**
     * Une balise dont rien ne dépend d'une valeur est écrite tout de suite,
     * une fois pour toutes : voir StaticElement. Les autres sont rendues telles quelles.
     */
    private static function flattened(Element $element): Element|StaticElement
    {
        $name = $element->lowerName();

        // Une structure (k:if, k:for, k:else), une balise <k:…>, un <script>
        // (qui reçoit le jeton du jour) ou un <form> (qui reçoit le jeton de
        // protection) se décident au moment d'afficher la page.
        if ($element->condition !== null
            || $element->loop !== null
            || $element->isElse
            || $name === 'script'
            || $name === 'form'
            || str_starts_with($name, 'k:')
        ) {
            return $element;
        }

        $html = '<' . $element->name;

        foreach ($element->attributes as $attribute) {
            if ($attribute->static === null) {
                return $element;
            }

            $html .= $attribute->static;
        }

        if ($element->selfClosing) {
            return new StaticElement($html . ' />');
        }

        $html .= '>';

        foreach ($element->children as $child) {
            if ($child instanceof StaticElement) {
                $html .= $child->html;
            } elseif ($child instanceof Raw) {
                $html .= $child->source;
            } elseif ($child instanceof Text && self::isFixedText($child)) {
                $html .= implode('', $child->parts);
            } else {
                return $element;
            }
        }

        return new StaticElement($element->closed ? $html . '</' . $element->name . '>' : $html);
    }

    /**
     * @phpstan-assert-if-true list<string> $text->parts
     */
    private static function isFixedText(Text $text): bool
    {
        foreach ($text->parts as $part) {
            if (!is_string($part)) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Ce que Kioo ajoute au HTML
    // ------------------------------------------------------------------

    /**
     * Construit une balise. Ses attributs k:if, k:for et k:else en sont retirés
     * et deviennent une condition, une boucle, ou la marque « sinon ».
     *
     * @param list<Attribute>    $attributes
     * @param list<TemplateNode> $children
     */
    private function element(string $name, array $attributes, array $children, bool $selfClosing, bool $closed, int $line): Element
    {
        $lowerName = strtolower($name);

        if (str_starts_with($lowerName, 'k:')) {
            $this->assertKiooElement($lowerName, $attributes, $selfClosing || $closed, $line);
        }

        $condition = null;
        $loop = null;
        $isElse = false;
        $zone = null;
        $kept = [];

        foreach ($attributes as $attribute) {
            if (!str_starts_with($attribute->name, 'k:')) {
                $kept[] = $attribute;

                continue;
            }

            $value = is_string($attribute->parts[0] ?? null) ? $attribute->parts[0] : '';

            match ($attribute->name) {
                'k:if' => $condition = $this->directiveExpression('k:if', $value, $attribute->line),
                'k:for' => $loop = $this->loop($value, $attribute->line),
                'k:else' => $isElse = true,
                'k:zone' => $zone = new Attribute('data-k-zone', [$this->zoneName($value, $attribute->line)], '"', $attribute->line),
                'k:update' => $kept[] = new Attribute('data-k-update', [$this->updatedZones($lowerName, $value, $attribute->line)], '"', $attribute->line),
                default => throw $this->error(KiooException::unknownDirective($attribute->name, self::KIOO_ATTRIBUTES), $attribute->line),
            };
        }

        $directives = (int) ($condition !== null) + (int) ($loop !== null) + (int) $isElse;

        if ($directives > 1) {
            throw $this->error(KiooException::conflictingDirectives($name), $line);
        }

        // Une structure vit et meurt avec sa balise : il faut savoir où elle finit.
        $isVoid = in_array($lowerName, self::VOID_ELEMENTS, true);

        if ($directives === 1 && !$closed && !$selfClosing && !$isVoid) {
            throw $this->error(KiooException::directiveNeedsClosingTag($name), $line);
        }

        if ($zone !== null) {
            // Une zone est un morceau de page que le script remplace : il lui
            // faut un début et une fin, et un nom qui ne désigne qu'elle.
            if ($loop !== null) {
                throw $this->error(KiooException::zoneInLoop($name), $line);
            }

            if (str_starts_with($lowerName, 'k:') || in_array($lowerName, self::NEVER_A_ZONE, true) || $isVoid || $selfClosing) {
                throw $this->error(KiooException::zoneNotAllowedHere($name), $line);
            }

            if (!$closed) {
                throw $this->error(KiooException::directiveNeedsClosingTag($name), $line);
            }

            $kept[] = $zone;
        }

        return new Element($name, $kept, $children, $selfClosing, $closed, $line, $condition, $loop, $isElse);
    }

    /**
     * k:zone="liste" : le nom d'une zone, écrit en dur.
     *
     * Sécurité (ADR-036) : le nom n'est jamais une expression. Un nom venu
     * d'un visiteur pourrait désigner un morceau de page qu'on ne voulait
     * pas remplacer.
     */
    private function zoneName(string $name, int $line): string
    {
        if (preg_match(self::ZONE_NAME, $name) !== 1 || strlen($name) > self::ZONE_NAME_MAX) {
            throw $this->error(KiooException::invalidZoneName($name), $line);
        }

        if (isset($this->zones[$name])) {
            throw $this->error(KiooException::duplicateZone($name, $this->zones[$name]), $line);
        }

        $this->zones[$name] = $line;

        return $name;
    }

    /**
     * k:update="liste, compteur" : les zones qu'un formulaire ou un lien met à
     * jour. Dans la page, les noms sont séparés par des espaces.
     */
    private function updatedZones(string $element, string $value, int $line): string
    {
        if ($element !== 'form' && $element !== 'a') {
            throw $this->error(KiooException::updateNotAllowedHere($element), $line);
        }

        $names = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);

        if ($names === false || $names === []) {
            throw $this->error(KiooException::updateWithoutZone(), $line);
        }

        foreach ($names as $name) {
            if (preg_match(self::ZONE_NAME, $name) !== 1 || strlen($name) > self::ZONE_NAME_MAX) {
                throw $this->error(KiooException::invalidZoneName($name), $line);
            }
        }

        return implode(' ', array_unique($names));
    }

    /**
     * La valeur de k:if et de k:for est une expression écrite SANS accolades.
     */
    private function directiveExpression(string $directive, string $expression, int $line): Node
    {
        if (str_contains($expression, '{')) {
            throw $this->error(KiooException::bracesInDirective($directive), $line);
        }

        try {
            return $this->expressions->parse(html_entity_decode($expression, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        } catch (KiooException $exception) {
            throw $this->error($exception, $line);
        }
    }

    private function loop(string $value, int $line): Loop
    {
        if (preg_match(self::FOR, $value, $matches) !== 1) {
            throw $this->error(KiooException::invalidFor(), $line);
        }

        return new Loop($matches[2], $matches[1] !== '' ? $matches[1] : null, $this->directiveExpression('k:for', $matches[3], $line));
    }

    /**
     * Vérifie une balise <k:…> dès la lecture du template : une faute se voit
     * tout de suite, pas le jour où la page est demandée.
     *
     * @param list<Attribute> $attributes
     */
    private function assertKiooElement(string $name, array $attributes, bool $hasEnd, int $line): void
    {
        if (!in_array($name, self::KIOO_ELEMENTS, true)) {
            throw $this->error(KiooException::unknownDirective('<' . $name . '>', array_map(static fn(string $known): string => '<' . $known . '>', self::KIOO_ELEMENTS)), $line);
        }

        $required = self::REQUIRED_ATTRIBUTE[$name];
        $found = false;
        $hasValue = false;

        foreach ($attributes as $attribute) {
            if ($attribute->name === $required) {
                // Sécurité : le nom d'un template ou d'un bloc s'écrit en dur.
                // Une valeur (qui peut venir d'un visiteur) ne doit pas choisir quel fichier est lu.
                $isStatic = $attribute->parts !== null && count($attribute->parts) === 1 && is_string($attribute->parts[0]);

                if (!$isStatic) {
                    throw $this->error(KiooException::dynamicTemplateName($name, $required), $line);
                }

                $found = true;
            } elseif ($attribute->name === 'value') {
                $hasValue = true;
            } elseif ($name === 'k:include' && !str_starts_with($attribute->name, 'k:') && preg_match(self::NAME, $attribute->name) !== 1) {
                throw $this->error(KiooException::invalidIncludeVariable($attribute->name), $attribute->line);
            }
        }

        if (!$found) {
            throw $this->error(KiooException::missingAttribute($name, $required), $line);
        }

        if ($name === 'k:json' && !$hasValue) {
            throw $this->error(KiooException::missingAttribute($name, 'value'), $line);
        }

        if ($name === 'k:block' && !$hasEnd) {
            throw $this->error(KiooException::directiveNeedsClosingTag($name), $line);
        }

        if ($name === 'k:layout') {
            if ($this->open !== [] || !$this->onlyWhitespaceSoFar()) {
                throw $this->error(KiooException::layoutMustComeFirst(), $line);
            }

            $this->hasLayout = true;
        }

        // Dans une page qui utilise une mise en page, un bloc en remplit un
        // emplacement : il se place au premier niveau, pas dans une autre balise.
        if ($name === 'k:block' && $this->hasLayout && $this->open !== []) {
            throw $this->error(KiooException::nestedBlock(), $line);
        }
    }

    private function onlyWhitespaceSoFar(): bool
    {
        foreach ($this->root as $node) {
            if (!self::isWhitespace($node) && !$node instanceof Raw) {
                return false;
            }
        }

        return true;
    }

    private static function isWhitespace(TemplateNode $node): bool
    {
        if (!$node instanceof Text) {
            return false;
        }

        foreach ($node->parts as $part) {
            if (!is_string($part) || trim($part) !== '') {
                return false;
            }
        }

        return true;
    }

    private function parseAttribute(): Attribute
    {
        $line = $this->line();
        $start = $this->position;

        // strcspn() donne d'un coup le nombre de caractères avant le prochain
        // de la liste : plus rapide que d'avancer caractère par caractère.
        $this->position += strcspn($this->source, " \t\r\n=>/", $this->position);

        // Un caractère inattendu ici (un « = » sans nom, par exemple) : on avance
        // d'un cran pour ne pas rester bloqué, et il fera partie du nom.
        if ($this->position === $start) {
            $this->position++;
        }

        $name = substr($this->source, $start, $this->position - $start);

        if (str_contains($name, '{')) {
            throw $this->error(KiooException::interpolationNotAllowed(
                'dans le nom d\'un attribut',
                'Une valeur ne peut remplir que la VALEUR d\'un attribut : class="{nom}".',
            ), $line);
        }

        $this->skipWhitespace();

        if (!$this->startsWith('=')) {
            return new Attribute($name, null, '', $line);
        }

        $this->position++;
        $this->skipWhitespace();
        $quote = $this->source[$this->position] ?? '';

        if ($quote !== '"' && $quote !== "'") {
            return new Attribute($name, [$this->readUnquotedValue($name, $line)], '', $line);
        }

        $this->position++;

        // La valeur d'un attribut k: est une expression, pas un texte à afficher :
        // elle est gardée telle quelle, sans y chercher d'accolades.
        if (str_starts_with($name, 'k:')) {
            $end = strpos($this->source, $quote, $this->position);

            if ($end === false) {
                throw $this->error(KiooException::unterminated('La valeur de l\'attribut « ' . $name . ' »', $quote), $line);
            }

            $value = substr($this->source, $this->position, $end - $this->position);
            $this->position = $end + 1;

            return new Attribute($name, [$value], $quote, $line);
        }

        $value = $this->readUntilUnescaped($quote, 'La valeur de l\'attribut « ' . $name . ' »', $line);

        return new Attribute($name, $this->attributeParts($name, $value, $line), $quote, $line);
    }

    /**
     * Découpe la valeur d'un attribut en texte fixe et affichages, sauf là où
     * un affichage serait dangereux.
     *
     * @return list<string|Interpolation>
     */
    private function attributeParts(string $name, string $value, int $line): array
    {
        $lowerName = strtolower($name);
        $hasInterpolation = preg_match('/(?<!\\\\)\{/', $value) === 1;

        if ($hasInterpolation && str_starts_with($lowerName, 'on')) {
            throw $this->error(KiooException::interpolationNotAllowed(
                'dans l\'attribut d\'événement « ' . $name . ' »',
                'Mettez votre script dans un fichier .js, et passez-lui la valeur par un attribut data- : data-id="{note.id}".',
            ), $line);
        }

        if ($hasInterpolation && in_array($lowerName, ['style', 'srcdoc'], true)) {
            throw $this->error(KiooException::interpolationNotAllowed(
                'dans l\'attribut « ' . $name . ' »',
                'Pour faire varier l\'apparence, changez plutôt une classe : class="note {note.type}".',
            ), $line);
        }

        return $this->parseParts($value, $line);
    }

    private function readUnquotedValue(string $name, int $line): string
    {
        $start = $this->position;

        $this->position += strcspn($this->source, " \t\r\n>", $this->position);

        $value = substr($this->source, $start, $this->position - $start);

        if (str_contains($value, '{')) {
            throw $this->error(KiooException::interpolationNotAllowed(
                'dans l\'attribut « ' . $name . ' », dont la valeur n\'est pas entre guillemets',
                'Entourez la valeur de guillemets : ' . $name . '="{...}".',
            ), $line);
        }

        return $value;
    }

    // ------------------------------------------------------------------
    // Texte et affichages
    // ------------------------------------------------------------------

    private function parseText(): void
    {
        // La ligne se lit AVANT d'avancer dans le texte.
        $line = $this->line();

        $this->append(new Text($this->parseParts($this->readText(), $line)));
    }

    /**
     * Lit du texte jusqu'au début de la prochaine balise. Un « < » à
     * l'intérieur d'un affichage ({a < b}) n'est pas un début de balise.
     */
    private function readText(): string
    {
        $start = $this->position;

        while ($this->position < $this->length) {
            // On saute d'un coup tout ce qui n'est ni « \ », ni « { », ni « < ».
            $this->position += strcspn($this->source, '\\{<', $this->position);

            if ($this->position >= $this->length) {
                break;
            }

            $character = $this->source[$this->position];

            if ($character === '\\' && ($this->source[$this->position + 1] ?? '') === '{') {
                $this->position += 2;

                continue;
            }

            if ($character === '{') {
                $this->position = $this->endOfExpression($this->source, $this->position, $this->line()) + 1;

                continue;
            }

            if ($character === '<' && $this->position > $start && ($this->isTagStart() || $this->startsWith('</') || $this->startsWith('<!') || $this->startsWith('<?'))) {
                break;
            }

            $this->position++;
        }

        return substr($this->source, $start, $this->position - $start);
    }

    /**
     * Découpe un texte en morceaux fixes et en affichages {…}.
     *
     * @return list<string|Interpolation>
     */
    private function parseParts(string $text, int $line): array
    {
        $parts = [];
        $fixed = '';
        $position = 0;
        $length = strlen($text);

        while ($position < $length) {
            // Tout ce qui précède le prochain « \ » ou « { » est du texte fixe.
            $run = strcspn($text, '\\{', $position);

            if ($run > 0) {
                $fixed .= substr($text, $position, $run);
                $position += $run;

                continue;
            }

            $character = $text[$position];

            // « \{ » écrit une vraie accolade.
            if ($character === '\\' && ($text[$position + 1] ?? '') === '{') {
                $fixed .= '{';
                $position += 2;

                continue;
            }

            if ($character !== '{') {
                $fixed .= $character;
                $position++;

                continue;
            }

            $currentLine = $line + substr_count($text, "\n", 0, $position);
            $end = $this->endOfExpression($text, $position, $currentLine);
            $expression = trim(substr($text, $position + 1, $end - $position - 1));

            if ($expression === '') {
                throw $this->error(KiooException::emptyExpression(), $currentLine);
            }

            if ($fixed !== '') {
                $parts[] = $fixed;
                $fixed = '';
            }

            try {
                $parts[] = new Interpolation($this->expressions->parse($expression), $currentLine);
            } catch (KiooException $exception) {
                throw $this->error($exception, $currentLine);
            }

            $position = $end + 1;
        }

        if ($fixed !== '') {
            $parts[] = $fixed;
        }

        return $parts;
    }

    /**
     * La position de l'accolade qui ferme l'affichage ouvert à $start. Une
     * accolade écrite dans un texte entre guillemets ({a ? '}' : b}) ne compte pas.
     */
    private function endOfExpression(string $text, int $start, int $line): int
    {
        $quote = null;
        $length = strlen($text);

        for ($position = $start + 1; $position < $length; $position++) {
            $character = $text[$position];

            if ($quote !== null) {
                if ($character === '\\') {
                    $position++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '}') {
                return $position;
            }
        }

        throw $this->error(KiooException::unterminated('L\'affichage ouvert par {', '}'), $line);
    }

    // ------------------------------------------------------------------
    // Lecture du texte source
    // ------------------------------------------------------------------

    private function append(TemplateNode $node): void
    {
        $last = array_key_last($this->open);

        // Le cas de presque toutes les balises : on ajoute à la suite, sur
        // place, sans recopier la liste des voisins.
        if (!$node instanceof Element || !$node->isElse) {
            if ($last === null) {
                $this->root[] = $node;
            } else {
                $this->open[$last]['children'][] = $node;
            }

            return;
        }

        if ($last === null) {
            $this->root = $this->attachElse($this->root, $node);
        } else {
            $this->open[$last]['children'] = $this->attachElse($this->open[$last]['children'], $node);
        }
    }

    /**
     * Une balise k:else n'est pas ajoutée à la liste de ses voisins : elle est
     * rattachée à la balise k:if ou k:for qui la précède.
     *
     * @param list<TemplateNode> $siblings
     *
     * @return list<TemplateNode>
     */
    private function attachElse(array $siblings, Element $node): array
    {
        // On remonte les voisins en sautant les espaces et les retours à la
        // ligne. Les commentaires, eux, ont déjà disparu : on a donc le droit
        // d'expliquer son k:else.
        for ($index = count($siblings) - 1; $index >= 0; $index--) {
            $previous = $siblings[$index];

            if (self::isWhitespace($previous)) {
                continue;
            }

            if ($previous instanceof Element && ($previous->condition !== null || $previous->loop !== null) && $previous->otherwise === null) {
                $siblings[$index] = $previous->withOtherwise($node);

                // Les espaces qui séparaient les deux balises ne sont pas
                // écrits dans la page : une seule des deux le sera.
                return array_slice($siblings, 0, $index + 1);
            }

            break;
        }

        throw $this->error(KiooException::elseWithoutIf(), $node->line);
    }

    private function startsWith(string $text): bool
    {
        return substr_compare($this->source, $text, $this->position, strlen($text)) === 0;
    }

    /**
     * Vrai si une balise ouvrante commence ici : « < » suivi d'une lettre.
     * « a < b » ou « <3 » restent du texte.
     */
    private function isTagStart(): bool
    {
        return ($this->source[$this->position] ?? '') === '<' && ctype_alpha($this->source[$this->position + 1] ?? '');
    }

    private function readTagName(): string
    {
        if (preg_match(self::TAG_NAME, $this->source, $match, 0, $this->position) !== 1) {
            throw $this->error(KiooException::unterminated('Une balise', 'son nom'), $this->line());
        }

        $this->position += strlen($match[0]);

        return $match[0];
    }

    private function skipWhitespace(): void
    {
        $this->position += strspn($this->source, " \t\n\r\v\f", $this->position);
    }

    /**
     * Lit jusqu'à $ending compris, et retourne ce qui a été lu.
     */
    private function readUntil(string $ending, string $what): string
    {
        $line = $this->line();
        $end = strpos($this->source, $ending, $this->position);

        if ($end === false) {
            throw $this->error(KiooException::unterminated($what, $ending), $line);
        }

        $text = substr($this->source, $this->position, $end + strlen($ending) - $this->position);
        $this->position = $end + strlen($ending);

        return $text;
    }

    /**
     * Lit la valeur d'un attribut jusqu'à son guillemet fermant (non compris),
     * en sautant les guillemets écrits à l'intérieur d'un affichage {…}.
     */
    private function readUntilUnescaped(string $quote, string $what, int $line): string
    {
        $start = $this->position;

        while ($this->position < $this->length) {
            $this->position += strcspn($this->source, '\\{' . $quote, $this->position);

            if ($this->position >= $this->length) {
                break;
            }

            $character = $this->source[$this->position];

            if ($character === '\\' && ($this->source[$this->position + 1] ?? '') === '{') {
                $this->position += 2;

                continue;
            }

            if ($character === '{') {
                $this->position = $this->endOfExpression($this->source, $this->position, $line) + 1;

                continue;
            }

            if ($character === $quote) {
                $value = substr($this->source, $start, $this->position - $start);
                $this->position++;

                return $value;
            }

            $this->position++;
        }

        throw $this->error(KiooException::unterminated($what, $quote), $line);
    }

    /**
     * Le contenu d'une balise <script> ou <style>, jusqu'à sa balise fermante (consommée).
     */
    private function readRawText(string $name, int $line): string
    {
        $end = stripos($this->source, '</' . $name, $this->position);

        if ($end === false) {
            throw $this->error(KiooException::unterminated('La balise <' . $name . '>', '</' . $name . '>'), $line);
        }

        $content = substr($this->source, $this->position, $end - $this->position);
        $close = strpos($this->source, '>', $end);
        $this->position = $close === false ? $this->length : $close + 1;

        return $content;
    }

    /**
     * Le numéro de la ligne où l'on se trouve.
     *
     * On ne recompte pas depuis le début du fichier à chaque appel : on compte
     * seulement les retours à la ligne franchis depuis l'appel précédent.
     */
    private function line(): int
    {
        $position = min($this->position, $this->length);

        if ($position > $this->countedUpTo) {
            $this->lineNumber += substr_count($this->source, "\n", $this->countedUpTo, $position - $this->countedUpTo);
            $this->countedUpTo = $position;
        }

        return $this->lineNumber;
    }

    private function error(KiooException $exception, int $line): KiooException
    {
        return KiooException::at($exception, $this->template, $line);
    }
}
