<?php

declare(strict_types=1);

namespace Wazi\Container;

use Psr\Container\ContainerInterface;
use Wazi\Container\Exception\ContainerException;
use Wazi\Container\Exception\ServiceNotFoundException;

/**
 * Le conteneur de services : il fabrique les objets de votre application et
 * leur fournit ce dont ils ont besoin. Conforme à PSR-11.
 *
 * Le problème qu'il résout : pour créer un contrôleur, il faut d'abord créer
 * ses dépendances, et les dépendances de ses dépendances...
 *
 *     new ArticleController(new ArticleRepository(new Database('mysql:...')), new Mailer(...))
 *
 * Le conteneur fait ce travail à votre place. Il lit le constructeur de la
 * classe demandée, fabrique chaque objet attendu, et recommence pour chacun :
 * c'est l'« autowiring ».
 *
 *     $controller = $container->get(ArticleController::class);
 *
 * Il ne sait deviner que des OBJETS. Pour le reste, on lui explique :
 *
 *     // Une recette, pour une classe qui a besoin d'un texte ou d'un nombre.
 *     $container->set(Database::class, fn () => new Database('mysql:host=localhost'));
 *
 *     // Une liaison, pour dire quelle classe fournir quand on demande une interface.
 *     $container->bind(MailerInterface::class, SmtpMailer::class);
 *
 * Chaque service n'est fabriqué qu'une fois : deux appels à get() rendent le même objet.
 *
 * Sécurité (ADR-006 et ADR-012) :
 *   - ne passez JAMAIS à get() un texte venu d'une requête : le visiteur
 *     choisirait quelle classe votre application fabrique ;
 *   - en garde-fou, le conteneur ne fabrique jamais tout seul une classe
 *     interne de PHP, et ignore tout identifiant qui n'a pas la forme d'un nom
 *     de classe (il n'est même pas transmis au chargeur de classes).
 */
final class Container implements ContainerInterface
{
    /** La forme d'un nom de classe : des mots séparés par « \ ». */
    private const string CLASS_NAME = '/^[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*$/D';

    /** @var array<string, \Closure> Les recettes données par set(). */
    private array $factories = [];

    /** @var array<string, class-string> Les liaisons données par bind() : identifiant => classe à fournir. */
    private array $bindings = [];

    /** @var array<string, mixed> Les services déjà fabriqués. */
    private array $services = [];

    /** @var list<string> Les services en cours de fabrication, pour repérer une dépendance circulaire. */
    private array $resolving = [];

    // ------------------------------------------------------------------
    // Expliquer au conteneur
    // ------------------------------------------------------------------

    /**
     * Donne la recette d'un service. Elle n'est exécutée qu'à la première
     * demande, et reçoit le conteneur pour aller y chercher d'autres services.
     *
     * @param \Closure(self): mixed $factory
     *
     * @throws ContainerException si ce service a déjà été fabriqué
     */
    public function set(string $id, \Closure $factory): void
    {
        $this->assertNotResolved($id);

        $this->factories[$id] = $factory;
        unset($this->bindings[$id]);
    }

    /**
     * Indique quelle classe fournir quand on demande une interface (ou une classe parente).
     *
     * @throws ContainerException si la classe n'existe pas, n'implémente pas l'interface, ou si le service a déjà été fabriqué
     */
    public function bind(string $id, string $class): void
    {
        $this->assertNotResolved($id);

        if (!self::looksLikeClassName($class) || !class_exists($class) || !is_a($class, $id, true)) {
            throw ContainerException::invalidBinding($id, $class);
        }

        $this->bindings[$id] = $class;
        unset($this->factories[$id]);
    }

    // ------------------------------------------------------------------
    // Demander au conteneur (PSR-11)
    // ------------------------------------------------------------------

    /**
     * Vrai si le conteneur connaît ce service ou peut tenter de le fabriquer.
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services)
            || isset($this->factories[$id])
            || isset($this->bindings[$id])
            || (self::looksLikeClassName($id) && class_exists($id));
    }

    /**
     * Retourne le service demandé, en le fabriquant si c'est la première fois.
     *
     * Les annotations ci-dessous disent à votre éditeur et à PHPStan que
     * get(Mailer::class) retourne un Mailer : l'autocomplétion fonctionne.
     *
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : mixed)
     *
     * @throws ServiceNotFoundException si le service est inconnu et ne peut pas être fabriqué
     * @throws ContainerException       si sa fabrication échoue
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->services)) {
            return $this->services[$id];
        }

        // Si ce service est déjà en cours de fabrication, c'est qu'il a besoin
        // de lui-même, directement ou par ricochet.
        if (in_array($id, $this->resolving, true)) {
            throw ContainerException::circularDependency([...$this->resolving, $id]);
        }

        $this->resolving[] = $id;

        try {
            $service = $this->resolve($id);
        } finally {
            array_pop($this->resolving);
        }

        return $this->services[$id] = $service;
    }

    // ------------------------------------------------------------------
    // Fabrication
    // ------------------------------------------------------------------

    private function resolve(string $id): mixed
    {
        if (isset($this->factories[$id])) {
            return ($this->factories[$id])($this);
        }

        if (isset($this->bindings[$id])) {
            return $this->get($this->bindings[$id]);
        }

        if (!self::looksLikeClassName($id)) {
            throw ServiceNotFoundException::forId($id, false);
        }

        if (class_exists($id)) {
            return $this->build($id);
        }

        throw interface_exists($id)
            ? ServiceNotFoundException::forInterface($id)
            : ServiceNotFoundException::forId($id, true);
    }

    /**
     * L'autowiring : fabrique une classe en fournissant à son constructeur
     * chacun des objets qu'il attend.
     *
     * @param class-string $class
     */
    private function build(string $class): object
    {
        $reflection = new \ReflectionClass($class);

        if ($reflection->isInternal()) {
            throw ContainerException::internalClass($class);
        }

        if (!$reflection->isInstantiable()) {
            throw ContainerException::notInstantiable($class);
        }

        $arguments = [];

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            // Un paramètre « ...$valeurs » accepte zéro valeur : on n'en donne aucune.
            if ($parameter->isVariadic()) {
                break;
            }

            $arguments[] = $this->argumentFor($class, $parameter);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * Trouve la valeur à donner à un paramètre de constructeur :
     *   1. un objet, si le paramètre attend une classe que le conteneur connaît ;
     *   2. sinon la valeur par défaut du paramètre, s'il en a une ;
     *   3. sinon null, si le paramètre l'accepte ;
     *   4. sinon, on ne devine pas : c'est une erreur.
     */
    private function argumentFor(string $class, \ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        // Un type simple et unique qui désigne une classe : « Mailer $mailer ».
        // Pour « Mailer|Logger », le conteneur ne choisit pas à votre place.
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && $this->has($type->getName())) {
            return $this->get($type->getName());
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        // Un paramètre sans type, ou de type « ?Mailer », accepte null.
        if ($type === null || $type->allowsNull()) {
            return null;
        }

        // Une interface sans liaison : l'erreur de get() est plus précise que la nôtre.
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            return $this->get($type->getName());
        }

        throw ContainerException::unresolvableParameter($class, $parameter->getName(), (string) $type);
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    private function assertNotResolved(string $id): void
    {
        if (array_key_exists($id, $this->services)) {
            throw ContainerException::alreadyResolved($id);
        }
    }

    private static function looksLikeClassName(string $id): bool
    {
        return preg_match(self::CLASS_NAME, $id) === 1;
    }
}
