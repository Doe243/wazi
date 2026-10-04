<?php

declare(strict_types=1);

namespace Wazi\Debug;

/**
 * Une rubrique de la barre de débogage : « Route », « Templates », « Session ».
 *
 * Dans la barre fermée, on voit son nom et son résumé. En cliquant dessus,
 * on voit ses lignes : un libellé, puis une valeur.
 *
 * Sécurité (ADR-035) : un Panel ne contient que des textes à montrer. Celui
 * qui le remplit n'y met jamais un secret ; la barre, elle, échappe tout.
 */
final readonly class Panel
{
    /**
     * @param string                $name    le nom de la rubrique
     * @param string                $summary ce qu'on lit sans l'ouvrir : « GET /notes », « 3 »
     * @param array<string, string> $rows    libellé => valeur, affichés une fois la rubrique ouverte
     * @param bool                  $alert   vrai pour attirer l'œil : une réponse en erreur, par exemple
     */
    public function __construct(
        public string $name,
        public string $summary,
        public array $rows = [],
        public bool $alert = false,
    ) {}
}
