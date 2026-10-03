<?php

declare(strict_types=1);

namespace Wazi\View\Template;

use Wazi\View\Expression\Node;

/**
 * Une balise, ses attributs et ce qu'elle contient.
 *
 * Les attributs k:if, k:for et k:else ne sont pas rangés avec les autres : ils
 * ne seront jamais écrits dans la page. Ils deviennent une condition, une
 * boucle, ou la balise « sinon » de la balise précédente.
 */
final readonly class Element implements TemplateNode
{
    /**
     * @param list<Attribute>    $attributes
     * @param list<TemplateNode> $children
     * @param bool               $selfClosing vrai si la balise est écrite <br /> ou <k:include />
     * @param bool               $closed      vrai si une balise fermante </nom> était écrite : on la réécrit alors à la sortie
     * @param Node|null          $condition   k:if : la balise n'est écrite que si cette expression est vraie
     * @param Loop|null          $loop        k:for : la balise est écrite une fois par élément de la liste
     * @param bool               $isElse      vrai pour une balise k:else, tant qu'elle n'est pas rattachée à celle qui la précède
     * @param Element|null       $otherwise   la balise k:else qui suit : écrite quand la condition est fausse, ou la liste vide
     */
    public function __construct(
        public string $name,
        public array $attributes,
        public array $children,
        public bool $selfClosing,
        public bool $closed,
        public int $line,
        public ?Node $condition = null,
        public ?Loop $loop = null,
        public bool $isElse = false,
        public ?self $otherwise = null,
    ) {}

    /**
     * La même balise, à laquelle on rattache sa balise k:else.
     */
    public function withOtherwise(self $otherwise): self
    {
        return clone($this, ['otherwise' => clone($otherwise, ['isElse' => false])]);
    }

    /**
     * Le nom de la balise sans tenir compte des majuscules : « k:block », « script ».
     */
    public function lowerName(): string
    {
        return strtolower($this->name);
    }

    /**
     * La valeur d'un attribut écrit en dur (sans affichage), ou null s'il est absent ou calculé.
     */
    public function staticAttribute(string $name): ?string
    {
        foreach ($this->attributes as $attribute) {
            if ($attribute->name === $name && $attribute->parts !== null) {
                return count($attribute->parts) === 1 && is_string($attribute->parts[0]) ? $attribute->parts[0] : null;
            }
        }

        return null;
    }
}
