<?php

declare(strict_types=1);

namespace Wazi\Kernel;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Wazi\Debug\Panel;
use Wazi\Debug\Trace;
use Wazi\Http\Session;
use Wazi\Routing\Route;
use Wazi\Routing\Router;

/**
 * Rassemble ce que la barre de débogage montre d'une requête.
 *
 * Cette classe vit dans la couche d'assemblage, à côté du Kernel : elle est
 * la seule à pouvoir interroger à la fois le routeur, la session et les
 * autres composants. La barre elle-même (Wazi\Debug) ne connaît que des textes.
 *
 * Sécurité (ADR-035) : on ne collecte ici que ce qui peut être montré.
 *   - d'une session ou d'un formulaire : le NOM des clés, jamais leur valeur ;
 *   - jamais un cookie, un en-tête, une variable d'environnement, un réglage.
 * Ce qui n'est pas collecté ne peut pas fuiter.
 *
 * @internal réservé au Kernel
 */
final class DebugPanels
{
    /** Assez pour reconnaître un formulaire ; un envoi démesuré n'allonge pas la barre. */
    private const int MAX_NAMES = 30;

    /**
     * @param list<MiddlewareInterface|string> $middlewares les middlewares que traverse chaque requête
     * @param float                            $milliseconds le temps mis à répondre
     *
     * @return list<Panel>
     */
    public static function collect(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Router $router,
        array $middlewares,
        ?Session $session,
        Trace $trace,
        float $milliseconds,
    ): array {
        return [
            self::request($request, $response, $milliseconds),
            self::route($request, $router),
            self::middlewares($middlewares),
            self::templates($trace),
            self::session($session),
            self::environment(),
        ];
    }

    private static function request(ServerRequestInterface $request, ResponseInterface $response, float $milliseconds): Panel
    {
        $status = $response->getStatusCode();
        $rows = [
            'Méthode' => $request->getMethod(),
            'Chemin' => $request->getUri()->getPath(),
            'Réponse' => trim($status . ' ' . $response->getReasonPhrase()),
            'Durée' => self::duration($milliseconds),
            'Mémoire' => number_format(memory_get_peak_usage() / 1_048_576, 1, ',', ' ') . ' Mo',
        ];

        // Sécurité : le nom des champs seulement. Leur valeur peut être un mot de passe.
        $query = self::names($request->getQueryParams());
        $body = self::names($request->getParsedBody());

        if ($query !== '') {
            $rows['Paramètres de l\'adresse'] = $query;
        }

        if ($body !== '') {
            $rows['Champs reçus'] = $body;
        }

        return new Panel(
            'Requête',
            $request->getMethod() . ' ' . $request->getUri()->getPath() . ' · ' . $status . ' · ' . self::duration($milliseconds),
            $rows,
            $status >= 400,
        );
    }

    private static function route(ServerRequestInterface $request, Router $router): Panel
    {
        $match = $router->find($request->getMethod(), $request->getUri()->getPath());
        $route = $match->route;

        if ($route === null) {
            return new Panel('Route', 'aucune', ['Route' => 'Aucune route ne correspond à cette adresse pour cette méthode.'], true);
        }

        $rows = [
            'Motif' => implode('|', $route->methods) . ' ' . $route->path,
            'Code exécuté' => self::handler($route),
            'Rang' => $match->position . ' sur ' . count($router->routes()) . ' route(s) : la première qui convient gagne',
        ];

        if ($match->parameters !== []) {
            // Ces valeurs viennent de l'adresse, déjà visible dans le navigateur.
            $rows['Paramètres'] = implode("\n", array_map(
                static fn(string $name, string|int $value): string => $name . ' = ' . $value,
                array_keys($match->parameters),
                $match->parameters,
            ));
        }

        if ($route->middlewares !== []) {
            $rows['Middlewares de la route'] = implode("\n", array_map(self::className(...), $route->middlewares));
        }

        if ($match->shadowed !== []) {
            $rows['Routes masquées'] = implode("\n", array_map(
                static fn(Route $hidden): string => implode('|', $hidden->methods) . ' ' . $hidden->path,
                $match->shadowed,
            ));
        }

        return new Panel('Route', self::handler($route, short: true), $rows);
    }

    /**
     * @param list<MiddlewareInterface|string> $middlewares
     */
    private static function middlewares(array $middlewares): Panel
    {
        $rows = [];

        foreach ($middlewares as $position => $middleware) {
            $rows[($position + 1) . '.'] = self::className($middleware);
        }

        return new Panel('Middlewares', (string) count($middlewares), $rows);
    }

    private static function templates(Trace $trace): Panel
    {
        $rows = [];
        $total = 0.0;

        foreach ($trace->of('template') as $position => $entry) {
            // Le rang en tête : un même template peut être écrit plusieurs fois.
            $rows[($position + 1) . '. ' . $entry['label']] = self::duration($entry['milliseconds']);
            $total += $entry['milliseconds'];
        }

        if ($trace->dropped() > 0) {
            $rows['…'] = $trace->dropped() . ' autre(s), non listé(s)';
        }

        return new Panel('Templates', $rows === [] ? '0' : count($rows) . ' · ' . self::duration($total), $rows);
    }

    private static function session(?Session $session): Panel
    {
        if ($session === null) {
            return new Panel('Session', 'non réglée', ['Session' => 'Aucun dossier de sessions n\'est donné au noyau : new Kernel(sessions: ...).']);
        }

        if (!$session->isStarted()) {
            return new Panel('Session', 'aucune', ['Session' => 'Ce visiteur n\'a pas de session : rien n\'y a encore été noté.']);
        }

        // Sécurité : le nom des clés seulement, jamais ce qu'elles contiennent.
        $keys = array_map(strval(...), array_keys($session->all()));

        return new Panel('Session', count($keys) . ' clé(s)', [
            'Clés' => $keys === [] ? '(aucune)' : self::listed($keys),
            'Valeurs' => 'Jamais affichées ici : une session contient des données personnelles.',
        ]);
    }

    private static function environment(): Panel
    {
        $version = class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('wazi/framework')
            ? (string) \Composer\InstalledVersions::getPrettyVersion('wazi/framework')
            : 'inconnue';

        return new Panel('Wazi', 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, [
            'PHP' => PHP_VERSION,
            'Wazi' => $version,
            'Mode' => 'développement. Cette barre n\'existe pas en production.',
        ]);
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * Le code d'une route, en clair : « Classe::methode », ou l'endroit où la fonction est écrite.
     */
    private static function handler(Route $route, bool $short = false): string
    {
        $handler = $route->handler;

        if (is_array($handler)) {
            return ($short ? self::shortName($handler[0]) : $handler[0]) . '::' . $handler[1];
        }

        $function = new \ReflectionFunction($handler);

        return 'fonction (' . basename((string) $function->getFileName()) . ', ligne ' . $function->getStartLine() . ')';
    }

    private static function className(object|string $middleware): string
    {
        return is_string($middleware) ? $middleware : $middleware::class;
    }

    /**
     * App\NoteController → NoteController.
     */
    private static function shortName(string $class): string
    {
        return substr($class, (int) strrpos('\\' . $class, '\\'));
    }

    /**
     * Les noms des champs d'un formulaire ou des paramètres d'une adresse, sans aucune valeur.
     */
    private static function names(mixed $data): string
    {
        if (!is_array($data) || $data === []) {
            return '';
        }

        return self::listed(array_map(strval(...), array_keys($data)));
    }

    /**
     * @param list<string> $names
     */
    private static function listed(array $names): string
    {
        $shown = array_map(
            // Un nom de champ est choisi par celui qui envoie : on le borne.
            static fn(string $name): string => mb_strlen($name) > 60 ? mb_substr($name, 0, 57) . '...' : $name,
            array_slice($names, 0, self::MAX_NAMES),
        );

        return implode(', ', $shown) . (count($names) > self::MAX_NAMES ? ', … (' . count($names) . ' en tout)' : '');
    }

    private static function duration(float $milliseconds): string
    {
        return number_format($milliseconds, $milliseconds < 10 ? 1 : 0, ',', ' ') . ' ms';
    }
}
