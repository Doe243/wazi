<?php

declare(strict_types=1);

namespace Wazi\View\Template;

use Wazi\View\Exception\KiooException;
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

    private string $source = '';

    private string $template = '';

    private int $position = 0;

    private int $length = 0;

    /**
     * Les balises ouvertes et pas encore fermées, de la plus extérieure à la plus intérieure.
     *
     * @var list<array{name: string, attributes: list<Attribute>, children: list<TemplateNode>, line: int}>
     */
    private array $open = [];

    /** @var list<TemplateNode> Ce qui se trouve en dehors de toute balise. */
    private array $root = [];

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
        $this->open = [];
        $this->root = [];

        while ($this->position < $this->length) {
            match (true) {
                $this->startsWith('<!--') => $this->append(new Raw($this->readUntil('-->', 'Le commentaire <!--'))),
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
                $this->append(new Element($name, $attributes, [], true, false, $line));

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
            $this->append(new Element($name, $attributes, [], false, false, $line));

            return;
        }

        if (in_array($lowerName, self::RAW_TEXT_ELEMENTS, true)) {
            $this->append(new Element($name, $attributes, [new Raw($this->readRawText($lowerName, $line))], false, true, $line));

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

        $this->append(new Element($frame['name'], $frame['attributes'], $frame['children'], false, $closed, $frame['line']));
    }

    private function parseAttribute(): Attribute
    {
        $line = $this->line();
        $start = $this->position;

        while ($this->position < $this->length && !str_contains(" \t\r\n=>/", $this->source[$this->position])) {
            $this->position++;
        }

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

        while ($this->position < $this->length && !str_contains(" \t\r\n>", $this->source[$this->position])) {
            $this->position++;
        }

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
        if ($this->open === []) {
            $this->root[] = $node;

            return;
        }

        $this->open[array_key_last($this->open)]['children'][] = $node;
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
        while ($this->position < $this->length && ctype_space($this->source[$this->position])) {
            $this->position++;
        }
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

    private function line(): int
    {
        return substr_count($this->source, "\n", 0, min($this->position, $this->length)) + 1;
    }

    private function error(KiooException $exception, int $line): KiooException
    {
        return KiooException::at($exception, $this->template, $line);
    }
}
