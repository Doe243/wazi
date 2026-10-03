<?php

declare(strict_types=1);

namespace Wazi\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Middleware\Exception\InvalidMiddlewareException;

/**
 * Fait traverser une requête par une suite de middlewares, puis par le
 * gestionnaire final (le routeur, en général). Conforme à PSR-15.
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
 */
final readonly class Pipeline implements RequestHandlerInterface
{
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
