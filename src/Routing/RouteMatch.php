<?php

declare(strict_types=1);

namespace Wazi\Routing;

/**
 * Ce que le routeur a trouvé pour une méthode et une adresse, avant d'exécuter quoi que ce soit.
 *
 * Trois issues possibles :
 *   - une route correspond : $route la donne, avec ses paramètres ;
 *   - l'adresse existe, mais pas pour cette méthode : $route est null, et
 *     $allowedMethods dit lesquelles sont acceptées (réponse 405) ;
 *   - rien ne correspond : $route est null et $allowedMethods est vide (réponse 404).
 */
final readonly class RouteMatch
{
    /**
     * @param Route|null                $route          la route choisie, ou null
     * @param array<string, string|int> $parameters     les paramètres trouvés dans l'adresse : nom => valeur
     * @param int                       $position       le rang de la route parmi celles déclarées, à partir de 1 ; 0 si aucune
     * @param list<string>              $allowedMethods les méthodes acceptées pour cette adresse, quand la méthode demandée ne l'est pas
     * @param bool                      $unsafePath     vrai si l'adresse contient un segment qu'aucune route ne reçoit jamais (« .. », « / » encodé...)
     * @param list<Route>               $shadowed       les routes déclarées plus loin qui auraient aussi convenu : elles ne sont jamais atteintes pour cette adresse
     */
    public function __construct(
        public ?Route $route = null,
        public array $parameters = [],
        public int $position = 0,
        public array $allowedMethods = [],
        public bool $unsafePath = false,
        public array $shadowed = [],
    ) {}
}
