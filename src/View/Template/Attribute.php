<?php

declare(strict_types=1);

namespace Wazi\View\Template;

/**
 * Un attribut d'une balise : class="note {type}", disabled, href='/notes'.
 */
final readonly class Attribute
{
    /**
     * @param list<string|Interpolation>|null $parts la valeur, découpée comme un Text ; null pour un attribut sans valeur (disabled)
     * @param string                          $quote le guillemet qui entourait la valeur : " ou ', ou '' s'il n'y en avait pas
     */
    public function __construct(public string $name, public ?array $parts, public string $quote, public int $line)
    {
        $this->static = self::staticHtml($name, $parts, $quote);
    }

    /**
     * L'attribut tel qu'il s'écrit dans la page, s'il ne contient aucun
     * affichage : il est alors préparé une fois, à la lecture du template.
     * null si sa valeur dépend d'une expression.
     */
    public ?string $static;

    /**
     * @param list<string|Interpolation>|null $parts
     */
    private static function staticHtml(string $name, ?array $parts, string $quote): ?string
    {
        if ($parts === null) {
            return ' ' . $name;
        }

        $value = '';

        foreach ($parts as $part) {
            if (!is_string($part)) {
                return null;
            }

            $value .= $part;
        }

        return ' ' . $name . '=' . $quote . $value . $quote;
    }
}
