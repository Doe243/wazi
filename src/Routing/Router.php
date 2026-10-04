<?php

declare(strict_types=1);

namespace Wazi\Routing;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Container\Container;
use Wazi\Http\Exception\InvalidMiddlewareException;
use Wazi\Http\Pipeline;
use Wazi\Routing\Attribute\Delete;
use Wazi\Routing\Attribute\Get;
use Wazi\Routing\Attribute\Patch;
use Wazi\Routing\Attribute\Post;
use Wazi\Routing\Attribute\Put;
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
 * Le code d'une route peut aussi être la méthode d'un contrôleur, et la route
 * peut avoir ses propres middlewares (voir RouteRunner et Pipeline) :
 *
 *     $router->get('/admin', [AdminController::class, 'index'], [RequireLogin::class]);
 *
 * Enfin, les routes d'un contrôleur peuvent s'écrire à côté de ses méthodes,
 * avec les attributs #[Get], #[Post]... : voir addController().
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

    /** Les attributs qui déclarent une route sur une méthode, et la méthode HTTP de chacun. */
    private const array ROUTE_ATTRIBUTES = [
        Get::class => 'GET',
        Post::class => 'POST',
        Put::class => 'PUT',
        Patch::class => 'PATCH',
        Delete::class => 'DELETE',
    ];

    /** @var list<Route> */
    private array $routes = [];

    /**
     * Les middlewares appliqués à TOUTES les routes, juste avant leur code.
     *
     * @var list<MiddlewareInterface|string>
     */
    private readonly array $middlewares;

    /**
     * @param ContainerInterface      $container   fabrique les contrôleurs et les middlewares désignés par leur nom de classe
     * @param array<array-key, mixed> $middlewares des middlewares appliqués à toutes les routes, après ceux de chaque route
     *
     * @throws InvalidMiddlewareException si la liste contient autre chose qu'un middleware
     */
    public function __construct(
        private readonly ContainerInterface $container = new Container(),
        array $middlewares = [],
    ) {
        $this->middlewares = Pipeline::declared($middlewares);
    }

    // ------------------------------------------------------------------
    // Déclarer des routes
    // ------------------------------------------------------------------

    /**
     * @param \Closure|array{class-string, string} $handler
     * @param array<array-key, mixed>              $middlewares
     */
    public function get(string $path, \Closure|array $handler, array $middlewares = []): void
    {
        $this->add(['GET'], $path, $handler, $middlewares);
    }

    /**
     * @param \Closure|array{class-string, string} $handler
     * @param array<array-key, mixed>              $middlewares
     */
    public function post(string $path, \Closure|array $handler, array $middlewares = []): void
    {
        $this->add(['POST'], $path, $handler, $middlewares);
    }

    /**
     * @param \Closure|array{class-string, string} $handler
     * @param array<array-key, mixed>              $middlewares
     */
    public function put(string $path, \Closure|array $handler, array $middlewares = []): void
    {
        $this->add(['PUT'], $path, $handler, $middlewares);
    }

    /**
     * @param \Closure|array{class-string, string} $handler
     * @param array<array-key, mixed>              $middlewares
     */
    public function patch(string $path, \Closure|array $handler, array $middlewares = []): void
    {
        $this->add(['PATCH'], $path, $handler, $middlewares);
    }

    /**
     * @param \Closure|array{class-string, string} $handler
     * @param array<array-key, mixed>              $middlewares
     */
    public function delete(string $path, \Closure|array $handler, array $middlewares = []): void
    {
        $this->add(['DELETE'], $path, $handler, $middlewares);
    }

    /**
     * Déclare une route pour une ou plusieurs méthodes : $router->add(['GET', 'POST'], '/contact', ...).
     *
     * @param list<string>            $methods
     * @param \Closure|array<mixed>   $handler     une fonction, ou [ArticleController::class, 'show'] ; elle retourne une réponse
     * @param array<array-key, mixed> $middlewares les middlewares propres à cette route : objets, ou noms de classes
     *
     * @throws InvalidRouteException      si la route est mal déclarée, ou déjà déclarée
     * @throws InvalidMiddlewareException si la liste contient autre chose qu'un middleware
     */
    public function add(array $methods, string $path, \Closure|array $handler, array $middlewares = []): void
    {
        $route = new Route($methods, $path, $handler, $middlewares);

        foreach ($this->routes as $existing) {
            foreach ($route->methods as $method) {
                if ($existing->path === $path && in_array($method, $existing->methods, true)) {
                    throw InvalidRouteException::duplicateRoute($method, $path);
                }
            }
        }

        $this->routes[] = $route;
    }

    /**
     * Déclare toutes les routes écrites en attributs sur les méthodes d'un contrôleur :
     *
     *     final class ArticleController
     *     {
     *         #[Get('/articles/{id:int}')]
     *         public function show(int $id): ResponseInterface { ... }
     *     }
     *
     *     $router->addController(ArticleController::class);
     *
     * Les contrôleurs se déclarent un par un, ici, dans votre code : Wazi ne
     * parcourt aucun dossier à la recherche de classes. Vous savez toujours
     * d'où vient une route, et rien d'inattendu n'est chargé (ADR-016).
     *
     * @param class-string $class
     *
     * @throws InvalidRouteException      si la classe est introuvable, n'a aucune route, ou si une route est mal déclarée
     * @throws InvalidMiddlewareException si une route porte autre chose qu'un middleware
     */
    public function addController(string $class): void
    {
        if (!class_exists($class)) {
            throw InvalidRouteException::controllerNotFound($class);
        }

        $found = false;

        foreach (new \ReflectionClass($class)->getMethods() as $method) {
            foreach (self::ROUTE_ATTRIBUTES as $attributeClass => $httpMethod) {
                foreach ($method->getAttributes($attributeClass) as $attribute) {
                    // Une route sur une méthode privée ne pourrait jamais être exécutée.
                    if (!$method->isPublic()) {
                        throw InvalidRouteException::routeOnNonPublicMethod($class, $method->getName());
                    }

                    $declared = $attribute->newInstance();
                    $this->add([$httpMethod], $declared->path, [$class, $method->getName()], $declared->middlewares);
                    $found = true;
                }
            }
        }

        if (!$found) {
            throw InvalidRouteException::controllerWithoutRoute($class);
        }
    }

    /**
     * Les routes déclarées, dans l'ordre où le routeur les essaie.
     *
     * C'est une lecture : la liste rendue est une copie, et une Route ne se
     * modifie pas. La console s'en sert pour « wazi routes ».
     *
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    // ------------------------------------------------------------------
    // Répondre à une requête
    // ------------------------------------------------------------------

    /**
     * Trouve la route de la requête, exécute sa fonction et retourne sa réponse.
     *
     * @throws RouteNotFoundException    si aucune route ne correspond à l'adresse (404)
     * @throws MethodNotAllowedException si l'adresse existe, mais pas pour cette méthode (405)
     * @throws RoutingException          si le code de la route est introuvable ou ne retourne pas une réponse
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
                return $this->run($route, $request, $parameters);
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
    private function run(Route $route, ServerRequestInterface $request, array $parameters): ResponseInterface
    {
        $attributes = $request->getAttributes();

        foreach ($parameters as $name => $value) {
            if (array_key_exists($name, $attributes)) {
                throw RoutingException::parameterWouldOverwriteAttribute($route->path, $name);
            }

            $request = $request->withAttribute($name, $value);
        }

        // Les middlewares de la route entourent son code. Ils s'exécutent ici,
        // dans le routeur : une route gardée l'est donc toujours, qu'elle soit
        // appelée par le noyau ou par le routeur seul.
        // L'ordre : d'abord les middlewares de la route, puis ceux de toutes les
        // routes. Un middleware de route peut ainsi laisser une marque que ceux
        // de toutes les routes liront (c'est ce que fait WithoutCsrf).
        return Pipeline::resolved(
            [...$route->middlewares, ...$this->middlewares],
            new RouteRunner($route, $parameters, $this->container),
            $this->container,
        )->handle($request);
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
