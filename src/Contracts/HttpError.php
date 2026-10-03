<?php

declare(strict_types=1);

namespace Wazi\Contracts;

/**
 * Une exception qui sait quelle réponse HTTP lui correspond.
 *
 * « Page introuvable » (404), « méthode non permise » (405), « requête
 * refusée » (400)... ne sont pas des pannes de l'application : ce sont des
 * réponses normales à une requête qui ne peut pas aboutir. Les exceptions de
 * ce genre implémentent cette interface, et le composant qui fabrique les
 * pages d'erreur n'a besoin de connaître qu'elle.
 *
 * Pourquoi dans Contracts ? Le routeur lève ces exceptions et le composant
 * Errors les transforme en réponses, mais ces deux composants n'ont pas le
 * droit de dépendre l'un de l'autre (règle des couches). Ils se rejoignent
 * ici, sur un contrat qui ne dépend de rien (ADR-010).
 */
interface HttpError extends \Throwable
{
    /**
     * Le code de statut à répondre, par exemple 404.
     */
    public function getStatusCode(): int;

    /**
     * Les en-têtes à joindre à la réponse, par exemple ['Allow' => 'GET, POST'] pour un 405.
     *
     * @return array<string, string>
     */
    public function getResponseHeaders(): array;
}
