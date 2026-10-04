<?php

declare(strict_types=1);

namespace Wazi\Kernel\Command;

use Wazi\Console\Command;
use Wazi\Console\Input;
use Wazi\Console\Output;
use Wazi\Routing\Route;
use Wazi\Routing\Router;

/**
 * « wazi routes » : la liste des routes de votre application.
 *
 *     GET    /                  App\PageController::accueil
 *     GET    /notes/{id:int}    App\NoteController::voir       [ConnexionRequise]
 *     POST   /notes             App\NoteController::ajouter    [ConnexionRequise]
 *
 * Pour chaque route : la méthode, l'adresse, le code qui s'exécute, et les
 * middlewares posés sur cette route. Elles sont listées dans l'ordre où vous
 * les avez déclarées, qui est aussi l'ordre dans lequel le routeur les essaie.
 *
 * Cette commande vit dans la couche d'assemblage (à côté du Kernel) : c'est
 * la seule qui a le droit de connaître à la fois la console et le routeur (ADR-027).
 */
final readonly class RoutesCommand implements Command
{
    public function __construct(private Router $router) {}

    public function name(): string
    {
        return 'routes';
    }

    public function description(): string
    {
        return 'Liste les routes de l\'application : adresse, code exécuté, middlewares.';
    }

    public function arguments(): array
    {
        return [];
    }

    public function options(): array
    {
        return [];
    }

    public function run(Input $input, Output $output): int
    {
        $rows = [];

        foreach ($this->router->routes() as $route) {
            $rows[] = [implode('|', $route->methods), $route->path, self::handler($route), self::middlewares($route)];
        }

        if ($rows === []) {
            $output->line('Aucune route n\'est déclarée. Ajoutez-en dans app.php : $app->router->get(\'/\', ...).');

            return 0;
        }

        // Chaque colonne prend la largeur de sa valeur la plus longue.
        $widths = [0, 0, 0];

        foreach ($rows as $row) {
            foreach ($widths as $column => $width) {
                $widths[$column] = max($width, mb_strlen($row[$column]));
            }
        }

        $output->title(count($rows) . ' route(s), dans l\'ordre où le routeur les essaie');

        foreach ($rows as $row) {
            $line = '';

            foreach ($widths as $column => $width) {
                $line .= $row[$column] . str_repeat(' ', $width - mb_strlen($row[$column]) + 2);
            }

            $output->line(rtrim($line . $row[3]));
        }

        return 0;
    }

    /**
     * Le code d'une route, en clair : « Classe::methode », ou l'endroit où la fonction est écrite.
     */
    private static function handler(Route $route): string
    {
        $handler = $route->handler;

        if (is_array($handler)) {
            return $handler[0] . '::' . $handler[1];
        }

        $function = new \ReflectionFunction($handler);

        return 'fonction (' . basename((string) $function->getFileName()) . ', ligne ' . $function->getStartLine() . ')';
    }

    private static function middlewares(Route $route): string
    {
        if ($route->middlewares === []) {
            return '';
        }

        $names = [];

        foreach ($route->middlewares as $middleware) {
            $class = is_string($middleware) ? $middleware : $middleware::class;

            // Le nom court suffit à s'y retrouver : App\ConnexionRequise → ConnexionRequise.
            $names[] = substr($class, (int) strrpos('\\' . $class, '\\'));
        }

        return '[' . implode(', ', $names) . ']';
    }
}
