<?php

declare(strict_types=1);

namespace Wazi\View;

/**
 * Du HTML que vous déclarez sûr, et qui sera écrit dans la page sans être échappé.
 *
 * C'est ce que produit le filtre « unsafe_raw » : {article.contenuHtml | unsafe_raw}.
 *
 * Sécurité (ADR-006) : ce nom est long et inquiétant exprès. Tout ce qui passe
 * par ici est exécuté par le navigateur tel quel. N'y mettez jamais un texte
 * saisi par un visiteur sans l'avoir nettoyé.
 */
final readonly class RawHtml
{
    public function __construct(public string $html) {}
}
