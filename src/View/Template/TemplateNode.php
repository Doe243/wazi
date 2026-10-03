<?php

declare(strict_types=1);

namespace Wazi\View\Template;

/**
 * Un élément de l'arbre d'un template : une balise, du texte, ou un morceau
 * recopié tel quel. L'analyseur (TemplateParser) construit cet arbre, le
 * moteur de rendu (Renderer) le parcourt pour écrire la page.
 */
interface TemplateNode {}
