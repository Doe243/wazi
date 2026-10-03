<?php

declare(strict_types=1);

namespace Wazi\Routing;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Routing\Exception\InvalidRouteException;
use Wazi\Routing\Exception\MethodNotAllowedException;
use Wazi\Routing\Exception\RouteNotFoundException;
use Wazi\Routing\Exception\RoutingException;

/**
 * Le routeur : il choisit quelle fonction exécuter pour une requête.
 *
 *     $router = new Router();
 *
 *     $router->get('/articles/{id:int}', function (ServerRequestInterface $request): ResponseInterface {
 *         $id = $request->getAttribute('id');   // un int, garanti par la contrainte
 *
 *         return new Response(200, [], "Article n° $id");
 *     });
 *
 *     $response = $router->handle($request);
 *
 * Comment il choisit (ADR-009) : il parcourt ses routes dans l'ordre où vous
 * les avez déclarées et prend la PREMIÈRE qui correspond. Déclarez donc
 * « /articles/nouveau » avant « /articles/{slug} », sinon « nouveau » serait
 * pris pour un slug.
 *
 * Les paramètres trouvés dans l'adresse sont déposés sur la requête, sous
 * forme d'attributs : $request->getAttribute('id').
 *
 * Sécurité (ADR-006) :
 *   - pas d'expression régulière libre : voir Constraint ;
 *   - une adresse dont un segment, une fois décodé, vaut « . » ou « .. », ou
 *     contient « / », « \ », un caractère de contrôle ou des octets qui ne
 *     sont pas de l'UTF-8, ne correspond à AUCUNE route. Un paramètre ne peut
 *     donc jamais servir à remonter dans les dossiers du serveur ;
 *   - un paramètre ne remplace jamais un attribut déjà présent sur la requête.
 */
final class Router implements RequestHandlerInterface
{
    /** Ce qu'un segment décodé ne doit jamais contenir. */
    private const string UNSAFE_IN_SEGMENT = '#[/\\\\\x00-\x1F\x7F]#';

    /** @var list<Route> */
    private array $routes = [];

    // ------------------------------------------------------------------
    // Déclarer des routes
    // ------------------------------------------------------------------

    /**
     * @param \Closure(ServerRequestInterface): ResponseInterface $handler
     */
    public function get(string $path, \Closure $handler): void
    {
        $this->add(['GET'], $path, $handler);
    }

    /**
     * @param \Closure(ServerRequestInterface): ResponseInterface $handler
     */
    public function post(string $path, \Closure $handler): void
    {
        $this->add(['POST'], $path, $handler);
    }

    /**
     * @param \Closure(ServerRequestInterface): ResponseInterface $handler
     */
    public function put(string $path, \Closure $handler): void
    {
        $this->add(['PUT'], $path, $handler);
    }

    /**
     * @param \Closure(ServerRequestInterface): ResponseInterface $handler
     */
    public function patch(string $path, \Closure $handler): void
    {
        $this->add(['PATCH'], $path, $handler);
    }

    /**
     * @param \Closure(ServerRequestInterface): ResponseInterface $handler
     */
    public function delete(string $path, \Closure $handler): void
    {
        $this->add(['DELETE'], $path, $handler);
    }

    /**
     * Déclare une route pour une ou plusieurs méthodes : $router->add(['GET', 'POST'], '/contact', ...).
     *
     * @param list<string> $methods
     * @param \Closure     $handler elle reçoit la requête (ServerRequestInterface) et retourne une réponse (ResponseInterface)
     *
     * @throws InvalidRouteException si la route est mal déclarée, ou déjà déclarée
     */
    public function add(array $methods, string $path, \Closure $handler): void
    {
        $route = new Route($methods, $path, $handler);

        foreach ($this->routes as $existing) {
            foreach ($route->methods as $method) {
                if ($existing->path === $path && in_array($method, $existing->methods, true)) {
                    throw InvalidRouteException::duplicateRoute($method, $path);
                }
            }
        }

        $this->routes[] = $route;
    }

    // ------------------------------------------------------------------
    // Répondre à une requête
    // ------------------------------------------------------------------

    /**
     * Trouve la route de la requête, exécute sa fonction et retourne sa réponse.
     *
     * @throws RouteNotFoundException    si aucune route ne correspond à l'adresse (404)
     * @throws MethodNotAllowedException si l'adresse existe, mais pas pour cette méthode (405)
     * @throws RoutingException          si la fonction de la route ne retourne pas une réponse
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $segments = self::safeSegments($path) ?? throw RouteNotFoundException::forPath($path);

        // Les méthodes des routes dont le chemin correspond, mais pas la méthode :
        // elles servent à répondre 405 plutôt que 404.
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $parameters = $route->match($segments);

            if ($parameters === null) {
                continue;
            }

            if ($route->accepts($method)) {
                return self::run($route, $request, $parameters);
            }

            $allowedMethods = [...$allowedMethods, ...$route->methods];
        }

        if ($allowedMethods === []) {
            throw RouteNotFoundException::forPath($path);
        }

        if (in_array('GET', $allowedMethods, true)) {
            $allowedMethods[] = 'HEAD';
        }

        throw MethodNotAllowedException::forPath($method, $path, array_values(array_unique($allowedMethods)));
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * @param array<string, string|int> $parameters
     */
    private static function run(Route $route, ServerRequestInterface $request, array $parameters): ResponseInterface
    {
        $attributes = $request->getAttributes();

        foreach ($parameters as $name => $value) {
            if (array_key_exists($name, $attributes)) {
                throw RoutingException::parameterWouldOverwriteAttribute($route->path, $name);
            }

            $request = $request->withAttribute($name, $value);
        }

        $response = ($route->handler)($request);

        return $response instanceof ResponseInterface
            ? $response
            : throw RoutingException::handlerMustReturnResponse($route->path, get_debug_type($response));
    }

    /**
     * Découpe le chemin demandé en segments décodés, ou retourne null s'il
     * contient quelque chose qu'aucune route ne doit jamais recevoir.
     *
     * Le chemin arrive encodé (« caf%C3%A9 »). On le découpe D'ABORD sur les
     * « / », on décode ENSUITE chaque segment : ainsi un « %2F » (un « / »
     * encodé) ne peut pas créer de segment supplémentaire, et il est repéré
     * juste après, une fois décodé.
     *
     * @return list<string>|null
     */
    private static function safeSegments(string $path): ?array
    {
        // Une adresse sans chemin (« https://exemple.com ») désigne la page d'accueil.
        if ($path === '') {
            $path = '/';
        }

        if (!str_starts_with($path, '/')) {
            return null;
        }

        $segments = [];

        foreach (explode('/', substr($path, 1)) as $segment) {
            $segment = rawurldecode($segment);

            if ($segment === '.' || $segment === '..'
                || preg_match(self::UNSAFE_IN_SEGMENT, $segment) === 1
                || preg_match('//u', $segment) !== 1
            ) {
                return null;
            }

            $segments[] = $segment;
        }

        return $segments;
    }
}
