<?php

declare(strict_types=1);

namespace Wazi\Routing;

use Wazi\Routing\Exception\InvalidRouteException;

/**
 * Une route : « pour telle méthode et telle forme d'adresse, exécuter telle fonction ».
 *
 *     GET  /articles/{id:int}
 *     └┬┘  └───┬───┘ └───┬──┘
 *   méthode  segment   segment à paramètre : {nom} ou {nom:contrainte}
 *            fixe
 *
 * Le chemin est découpé en segments, les morceaux entre les « / ». Chaque
 * segment est soit un texte fixe, soit un paramètre qui l'occupe en entier.
 * Comparer une adresse à une route revient alors à comparer deux listes,
 * segment par segment : c'est tout l'algorithme (ADR-009).
 */
final readonly class Route
{
    /** Un mot en majuscules : GET, POST... */
    private const string METHOD = '/^[A-Z]+$/D';

    /** {nom} ou {nom:contrainte}, seul dans son segment. */
    private const string PARAMETER = '/^\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([a-z]+))?\}$/D';

    /** Ce qu'un segment fixe ne peut pas contenir : accolades, et ce qui a un sens spécial dans une URI. */
    private const string FORBIDDEN_IN_LITERAL = '/[{}?#%\\\\\x00-\x20\x7F]/';

    /** @var list<string> */
    public array $methods;

    /**
     * Les segments du chemin. Pour un segment fixe, « parameter » vaut null et
     * « literal » contient le texte ; pour un paramètre, c'est l'inverse.
     *
     * @var list<array{literal: string, parameter: string|null, constraint: Constraint}>
     */
    private array $segments;

    /**
     * @param list<string> $methods les méthodes HTTP acceptées, en majuscules
     * @param string       $path    le chemin, par exemple /articles/{id:int}
     * @param \Closure     $handler la fonction à exécuter ; elle reçoit la requête et retourne une réponse
     *
     * @throws InvalidRouteException si la route est mal déclarée
     */
    public function __construct(array $methods, public string $path, public \Closure $handler)
    {
        if ($methods === []) {
            throw InvalidRouteException::noMethod($path);
        }

        foreach ($methods as $method) {
            if (preg_match(self::METHOD, $method) !== 1) {
                throw InvalidRouteException::invalidMethod($method);
            }
        }

        $this->methods = array_values(array_unique($methods));
        $this->segments = self::parse($path);
    }

    /**
     * Compare les segments d'une adresse à ceux de la route.
     *
     * @param list<string> $requestedSegments les segments de l'adresse demandée, déjà décodés
     *
     * @return array<string, string|int>|null les paramètres trouvés, ou null si l'adresse ne correspond pas
     */
    public function match(array $requestedSegments): ?array
    {
        if (count($requestedSegments) !== count($this->segments)) {
            return null;
        }

        $parameters = [];

        foreach ($this->segments as $position => $segment) {
            $requested = $requestedSegments[$position];

            if ($segment['parameter'] === null) {
                if ($requested !== $segment['literal']) {
                    return null;
                }

                continue;
            }

            if (!$segment['constraint']->accepts($requested)) {
                return null;
            }

            $parameters[$segment['parameter']] = $segment['constraint']->convert($requested);
        }

        return $parameters;
    }

    public function accepts(string $method): bool
    {
        // HEAD demande la même chose que GET, sans le corps de la réponse :
        // toute route GET y répond.
        return in_array($method, $this->methods, true)
            || ($method === 'HEAD' && in_array('GET', $this->methods, true));
    }

    /**
     * @return list<array{literal: string, parameter: string|null, constraint: Constraint}>
     */
    private static function parse(string $path): array
    {
        if (!str_starts_with($path, '/')) {
            throw InvalidRouteException::pathMustStartWithSlash($path);
        }

        // « / » seul est la page d'accueil : un unique segment vide.
        if ($path === '/') {
            return [['literal' => '', 'parameter' => null, 'constraint' => Constraint::Any]];
        }

        $segments = [];
        $names = [];

        foreach (explode('/', substr($path, 1)) as $text) {
            if ($text === '') {
                throw InvalidRouteException::emptySegment($path);
            }

            if (preg_match(self::PARAMETER, $text, $matches) !== 1) {
                if ($text === '.' || $text === '..' || preg_match(self::FORBIDDEN_IN_LITERAL, $text) === 1) {
                    throw InvalidRouteException::invalidSegment($path, $text);
                }

                $segments[] = ['literal' => $text, 'parameter' => null, 'constraint' => Constraint::Any];

                continue;
            }

            $name = $matches[1];
            $constraint = Constraint::tryFrom($matches[2] ?? Constraint::Any->value)
                ?? throw InvalidRouteException::unknownConstraint($path, $matches[2] ?? '');

            if (in_array($name, $names, true)) {
                throw InvalidRouteException::duplicateParameter($path, $name);
            }

            $names[] = $name;
            $segments[] = ['literal' => '', 'parameter' => $name, 'constraint' => $constraint];
        }

        return $segments;
    }
}
