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
    public function __construct(public string $name, public ?array $parts, public string $quote, public int $line) {}
}
