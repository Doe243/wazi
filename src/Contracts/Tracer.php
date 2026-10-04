<?php

declare(strict_types=1);

namespace Wazi\Contracts;

/**
 * Ce à quoi un composant signale ce qu'il vient de faire, et en combien de temps.
 *
 *     $tracer?->record('template', 'notes/liste', 2.4);
 *
 * En mode développement, la barre de débogage en fournit un et affiche ce
 * qu'il a reçu. Le reste du temps, il n'y en a pas : le composant reçoit
 * null, et ne signale rien.
 *
 * Ce contrat vit dans la couche la plus basse : un composant peut signaler ce
 * qu'il fait sans connaître la barre, ni aucun autre composant.
 *
 * Sécurité (ADR-035) : le libellé est affiché dans la page. Un composant n'y
 * met jamais une valeur reçue d'un visiteur ni un secret : un nom de
 * template, le texte d'une requête SQL avec ses marqueurs, jamais ses valeurs.
 */
interface Tracer
{
    /**
     * @param string $kind         la sorte de chose signalée : « template », « sql »
     * @param string $label        ce qui a été fait, en quelques mots
     * @param float  $milliseconds le temps que cela a pris
     */
    public function record(string $kind, string $label, float $milliseconds): void;
}
