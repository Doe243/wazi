<?php

declare(strict_types=1);

namespace Wazi\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Http\Exception\InvalidMiddlewareException;

/**
 * Fait traverser une requête par une suite de middlewares, puis par le
 * gestionnaire final (le routeur, ou la fonction d'une route). Conforme à PSR-15.
 *
 * Un middleware est une étape placée autour de votre code. Il voit passer la
 * requête à l'aller et la réponse au retour, comme les couches d'un oignon :
 *
 *     requête ─►  [ A  ─►  [ B  ─►  [ routeur ]  ─►  B ]  ─►  A ]  ─► réponse
 *
 * Avec la liste [A, B], A est donc le plus à l'extérieur : il agit en premier
 * sur la requête, et en dernier sur la réponse.
 *
 * Un middleware reçoit la requête et « la suite » (un gestionnaire). Il peut :
 *   - appeler la suite, puis modifier la réponse (ajouter un en-tête) ;
 *   - modifier la requête avant d'appeler la suite (y poser un attribut) ;
 *   - ne PAS appeler la suite et répondre lui-même (refuser l'accès).
 *
 * Comment ça marche : « la suite » du premier middleware est tout simplement
 * un pipeline qui contient tous les autres. Quand la liste est vide, il ne
 * reste que le gestionnaire final.
 *
 * Cette classe vit dans Http, la couche du bas, parce que le noyau ET le
 * routeur s'en servent (ADR-015).
 */
final readonly class Pipeline implements RequestHandlerInterface
{
    /** La forme d'un nom de classe : des mots séparés par « \ ». */
    private const string CLASS_NAME = '/^[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*$/D';

    /** @var list<MiddlewareInterface> */
    private array $middlewares;

    /**
     * @param array<array-key, mixed> $middlewares des objets MiddlewareInterface, du plus extérieur au plus intérieur
     *
     * @throws InvalidMiddlewareException si la liste contient autre chose qu'un middleware
     */
    public function __construct(array $middlewares, private RequestHandlerInterface $handler)
    {
        $valid = [];

        foreach ($middlewares as $position => $middleware) {
            if (!$middleware instanceof MiddlewareInterface) {
                throw InvalidMiddlewareException::notAMiddleware($position, get_debug_type($middleware));
            }

            $valid[] = $middleware;
        }

        $this->middlewares = $valid;
    }

    /**
     * Vérifie une liste telle qu'on la DÉCLARE : chaque élément est un objet
     * middleware, ou le nom d'une classe que le conteneur fabriquera plus tard.
     * Rien n'est fabriqué ici : une erreur de déclaration se voit dès le démarrage.
     *
     * @param array<array-key, mixed> $middlewares
     *
     * @return list<MiddlewareInterface|string>
     *
     * @throws InvalidMiddlewareException si un élément n'est ni un middleware ni un nom de classe
     */
    public static function declared(array $middlewares): array
    {
        $declared = [];

        foreach ($middlewares as $position => $middleware) {
            if (is_string($middleware)) {
                if (preg_match(self::CLASS_NAME, $middleware) !== 1) {
                    throw InvalidMiddlewareException::notAClassName($position);
                }
            } elseif (!$middleware instanceof MiddlewareInterface) {
                throw InvalidMiddlewareException::notAMiddleware($position, get_debug_type($middleware));
            }

            $declared[] = $middleware;
        }

        return $declared;
    }

    /**
     * Construit le pipeline d'une liste déclarée : les noms de classes sont
     * remplacés par les objets que fabrique le conteneur.
     *
     * Sécurité (ADR-006) : ces noms viennent de votre code (la liste donnée au
     * noyau ou à une route), jamais de la requête.
     *
     * @param list<MiddlewareInterface|string> $declared une liste vérifiée par declared()
     *
     * @throws InvalidMiddlewareException si le conteneur fournit autre chose qu'un middleware
     */
    public static function resolved(array $declared, RequestHandlerInterface $handler, ContainerInterface $container): self
    {
        return new self(
            array_map(
                static fn(MiddlewareInterface|string $middleware): mixed => is_string($middleware)
                    ? $container->get($middleware)
                    : $middleware,
                $declared,
            ),
            $handler,
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->middlewares === []) {
            return $this->handler->handle($request);
        }

        $first = $this->middlewares[0];
        $next = new self(array_slice($this->middlewares, 1), $this->handler);

        return $first->process($request, $next);
    }
}
