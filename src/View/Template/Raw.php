<?php

declare(strict_types=1);

namespace Wazi\View\Template;

/**
 * Un morceau recopié tel quel, sans rien y chercher : un commentaire, la
 * déclaration <!DOCTYPE>, ou le contenu d'une balise <script> ou <style>.
 */
final readonly class Raw implements TemplateNode
{
    public function __construct(public string $source) {}
}
