<?php

declare(strict_types=1);

namespace Wazi\Routing;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Routing\Exception\RoutingException;

/**
 * Exécute le code d'une route : c'est la dernière étape, au cœur de l'oignon
 * des middlewares.
 *
 * Le code d'une route est soit une fonction, soit la méthode d'un contrôleur :
 *
 *     $router->get('/articles/{id:int}', function (ServerRequestInterface $request, int $id) { ... });
 *     $router->get('/articles/{id:int}', [ArticleController::class, 'show']);
 *
 * Un contrôleur est fabriqué par le conteneur : les objets que son
 * constructeur attend lui sont fournis automatiquement.
 *
 * Quels arguments reçoit la fonction (ou la méthode) ? Chacun de ses
 * paramètres est rempli ainsi, dans cet ordre :
 *   1. un paramètre dont le type est celui de la requête reçoit la requête ;
 *   2. un paramètre qui porte le nom d'un paramètre de la route ({id}) reçoit sa valeur ;
 *   3. sinon, sa valeur par défaut s'il en a une ;
 *   4. sinon, c'est une erreur : on ne devine pas.
 * Les services (base de données, messagerie...) ne se demandent pas ici mais
 * dans le constructeur du contrôleur.
 *
 * Sécurité (ADR-006) : le nom de la classe et celui de la méthode viennent de
 * la déclaration de la route, écrite dans votre code. Rien de ce que contient
 * la requête ne sert à choisir quoi exécuter. Seule une méthode publique,
 * réellement écrite dans la classe, peut être appelée.
 */
final readonly class RouteRunner implements RequestHandlerInterface
{
    /**
     * @param array<string, string|int> $parameters les paramètres trouvés dans l'adresse
     */
    public function __construct(
        private Route $route,
        private array $parameters,
        private ContainerInterface $container,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $function = $this->functionToCall();
        $response = $function(...$this->argumentsFor(new \ReflectionFunction($function), $request));

        return $response instanceof ResponseInterface
            ? $response
            : throw RoutingException::handlerMustReturnResponse($this->route->path, get_debug_type($response));
    }

    /**
     * La fonction de la route, ou la méthode du contrôleur une fois celui-ci fabriqué.
     */
    private function functionToCall(): \Closure
    {
        $handler = $this->route->handler;

        if ($handler instanceof \Closure) {
            return $handler;
        }

        [$class, $method] = $handler;

        try {
            $controller = $this->container->get($class);
        } catch (ContainerExceptionInterface $exception) {
            throw RoutingException::controllerCannotBeBuilt($this->route->path, $class, $exception);
        }

        // method_exists() écarte les méthodes inventées par __call. La
        // déclaration de la méthode (ReflectionMethod) dit si elle est publique :
        // is_callable() ne suffit pas, car il répond « oui » pour une méthode
        // privée dès que la classe possède un __call.
        if (!is_object($controller)
            || !method_exists($controller, $method)
            || !new \ReflectionMethod($controller, $method)->isPublic()
        ) {
            throw RoutingException::controllerMethodNotFound($this->route->path, $class, $method);
        }

        return $controller->{$method}(...);
    }

    /**
     * @return list<mixed>
     */
    private function argumentsFor(\ReflectionFunction $function, ServerRequestInterface $request): array
    {
        $arguments = [];

        foreach ($function->getParameters() as $parameter) {
            // Un paramètre « ...$valeurs » accepte zéro valeur : on n'en donne aucune.
            if ($parameter->isVariadic()) {
                break;
            }

            $name = $parameter->getName();
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;

            if (($typeName !== null && $request instanceof $typeName) || ($type === null && $name === 'request')) {
                $arguments[] = $request;
            } elseif (array_key_exists($name, $this->parameters)) {
                $arguments[] = $this->routeParameter($name, $typeName);
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw RoutingException::unresolvableArgument($this->route->path, $name);
            }
        }

        return $arguments;
    }

    /**
     * La valeur d'un paramètre de route, après avoir vérifié qu'elle convient
     * au type attendu : mieux vaut une explication qu'une TypeError de PHP.
     */
    private function routeParameter(string $name, ?string $expectedType): string|int
    {
        $value = $this->parameters[$name];

        if (in_array($expectedType, ['int', 'string'], true) && get_debug_type($value) !== $expectedType) {
            throw RoutingException::argumentTypeMismatch($this->route->path, $name, $expectedType, get_debug_type($value));
        }

        return $value;
    }
}
