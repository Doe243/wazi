<?php

declare(strict_types=1);

namespace Wazi\View\Expression;

/**
 * Un élément de l'arbre d'une expression.
 *
 * L'expression « prix * 2 > 10 » devient un arbre :
 *
 *              Binary(>)
 *              /       \
 *        Binary(*)    Literal(10)
 *        /      \
 *  Variable    Literal(2)
 *   (prix)
 *
 * L'analyseur (Parser) construit cet arbre ; l'évaluateur (Evaluator) le
 * parcourt pour calculer la valeur. Chaque sorte d'élément est une petite
 * classe du dossier Node/, qui ne fait que porter des informations.
 */
interface Node
{
    /**
     * La position du premier caractère de cet élément dans le texte de
     * l'expression (à partir de 0), pour situer une erreur.
     */
    public function position(): int;
}
