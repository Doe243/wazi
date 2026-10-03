<?php

declare(strict_types=1);

namespace Wazi\Routing\Attribute;

/**
 * Déclare une route DELETE sur une méthode de contrôleur :
 *
 *     #[Delete('/articles/{id:int}')]
 *     public function show(int $id): ResponseInterface { ... }
 *
 * C'est l'équivalent de $router->delete('/articles/{id:int}', [Classe::class, 'show']),
 * écrit à côté du code qu'il concerne. Les routes d'un contrôleur sont lues
 * quand on le déclare au routeur : $router->addController(Classe::class).
 *
 * Cette classe ne fait rien d'autre que porter deux informations.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class Delete
{
    /**
     * @param string                  $path        le chemin, par exemple /articles/{id:int}
     * @param array<array-key, mixed> $middlewares les middlewares propres à cette route : noms de classes, ou objets
     */
    public function __construct(public string $path, public array $middlewares = []) {}
}
