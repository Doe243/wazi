<?php

declare(strict_types=1);

namespace Wazi\Kernel\Command;

use Psr\Http\Message\ServerRequestInterface;
use Wazi\Console\Application;
use Wazi\Console\Argument;
use Wazi\Console\DetailedCommand;
use Wazi\Console\Input;
use Wazi\Console\Option;
use Wazi\Console\Output;
use Wazi\Kernel\Kernel;
use Wazi\Middleware\CsrfCookie;
use Wazi\Middleware\CsrfProtection;
use Wazi\Middleware\SecurityHeaders;
use Wazi\Middleware\SessionMiddleware;
use Wazi\Middleware\WithoutCsrf;
use Wazi\Routing\Route;
use Wazi\Routing\RouteMatch;

/**
 * « wazi explain /notes/42 » : ce qu'une adresse traverse, de la requête au contrôleur.
 *
 *     wazi explain /notes/42
 *     wazi explain /notes --method=POST
 *
 * La commande répond à trois questions :
 *   - quelle route est choisie, et avec quels paramètres ?
 *   - par quelles étapes la requête passe-t-elle, dans l'ordre ?
 *   - quel code s'exécute, et qui lui fournit ses arguments ?
 *
 * Elle n'exécute rien : ni middleware, ni contrôleur. Elle lit l'application
 * construite par app.php, et le code de vos classes.
 *
 * Sécurité (ADR-006 et ADR-029) : cet outil ne s'utilise que dans un terminal.
 * Il ne montre ni la valeur d'un réglage, ni le contenu d'une session.
 */
final readonly class ExplainCommand implements DetailedCommand
{
    private const string METHOD = '/^[A-Z]{1,20}$/D';

    /** Les méthodes qui ne font que lire : la protection des formulaires ne leur demande rien. */
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** Ce que fait chaque middleware de Wazi, en une phrase. */
    private const array DESCRIPTIONS = [
        SecurityHeaders::class => 'ajoute les en-têtes de sécurité à la réponse',
        CsrfCookie::class => 'lit le cookie du jeton des formulaires, et l\'envoie si une page en a besoin',
        SessionMiddleware::class => 'retrouve la session du visiteur, et l\'enregistre au retour',
        WithoutCsrf::class => 'dispense cette route du jeton des formulaires',
    ];

    public function __construct(private Kernel $app) {}

    public function name(): string
    {
        return 'explain';
    }

    public function description(): string
    {
        return 'Explique ce qu\'une adresse traverse : route, middlewares, contrôleur.';
    }

    public function arguments(): array
    {
        return [new Argument('adresse', 'Le chemin à expliquer : /notes/42, ou notes/42')];
    }

    public function options(): array
    {
        return [new Option('method', 'La méthode de la requête : GET, POST, PUT, PATCH, DELETE', 'GET')];
    }

    public function help(): string
    {
        return implode("\n", [
            'Montre la route choisie, les étapes traversées dans l\'ordre, le code exécuté',
            'et d\'où vient chacun de ses arguments.',
            '',
            'Rien n\'est exécuté : ni middleware, ni contrôleur. Vous pouvez expliquer',
            'une suppression sans rien supprimer.',
            '',
            'L\'adresse s\'écrit avec ou sans la barre du début. Dans Git Bash, écrivez-la',
            'sans : ce terminal transforme « /notes » en chemin de fichier.',
        ]);
    }

    public function examples(): array
    {
        return [
            'notes/3' => 'Ce que traverse GET /notes/3',
            'contact --method=POST' => 'Ce que traverse l\'envoi du formulaire de contact',
        ];
    }

    public function run(Input $input, Output $output): int
    {
        $method = strtoupper($input->option('method'));
        $path = $input->argument('adresse');

        if (preg_match(self::METHOD, $method) !== 1) {
            $output->error('La méthode donnée à --method n\'en est pas une. Écrivez par exemple --method=POST.');

            return Application::USAGE_ERROR;
        }

        // Sous Windows, le terminal Git Bash transforme « /notes/42 » en chemin
        // de fichier (« C:/Program Files/Git/notes/42 ») avant de nous le donner.
        if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            $output->error(
                'L\'adresse reçue est un chemin de fichier Windows, pas une adresse du site. Certains terminaux'
                . ' (Git Bash) transforment ce qui commence par « / ». Écrivez l\'adresse sans la barre du début :'
                . ' wazi explain notes/42.',
            );

            return Application::USAGE_ERROR;
        }

        // « notes/42 » et « /notes/42 » désignent la même adresse.
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        // Ce qui suit « ? » ou « # » ne sert pas à choisir une route.
        $path = (string) strtok($path, '?#');

        $output->title($method . ' ' . $path);

        $match = $this->app->router->find($method, $path);

        if ($match->route === null) {
            $this->explainNoRoute($match, $method, $output);

            return Application::SUCCESS;
        }

        $this->explainRoute($match, $output);
        $this->explainJourney($match->route, $method, $output);
        $this->explainHandler($match, $output);

        return Application::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Les trois parties de l'explication
    // ------------------------------------------------------------------

    private function explainNoRoute(RouteMatch $match, string $method, Output $output): void
    {
        if ($match->unsafePath) {
            $output->line('Réponse : 404, page introuvable.');
            $output->line();
            $output->line('Cette adresse contient un segment qu\'aucune route ne reçoit jamais : « . », « .. », une barre');
            $output->line('oblique encodée, un caractère de contrôle, ou du texte qui n\'est pas de l\'UTF-8. Le routeur la');
            $output->line('refuse avant de regarder vos routes : c\'est une protection, pas une erreur de votre code.');

            return;
        }

        if ($match->allowedMethods !== []) {
            $output->line('Réponse : 405, méthode non permise.');
            $output->line();
            $output->line('Cette adresse existe, mais pas pour ' . $method . '. Méthodes acceptées : ' . implode(', ', $match->allowedMethods) . '.');

            return;
        }

        $output->line('Réponse : 404, page introuvable.');
        $output->line();
        $output->line('Aucune route ne correspond à cette adresse. « wazi routes » liste celles qui existent.');
        $output->line('À vérifier : l\'orthographe, la barre finale (« /notes » et « /notes/ » sont deux adresses),');
        $output->line('et la contrainte d\'un paramètre ({id:int} n\'accepte qu\'un nombre entier).');
    }

    private function explainRoute(RouteMatch $match, Output $output): void
    {
        $route = $match->route;

        if ($route === null) {
            return;
        }

        $total = count($this->app->router->routes());

        $output->line('1. La route choisie');
        $output->line();
        $output->line('   ' . implode('|', $route->methods) . ' ' . $route->path . '   (la ' . $match->position . ($match->position === 1 ? 're' : 'e') . ' des ' . $total . ' route(s) déclarée(s))');

        foreach ($match->parameters as $name => $value) {
            $output->line('   {' . $name . '} = ' . $value . '   (' . (is_int($value) ? 'un nombre entier' : 'un texte') . ')');
        }

        if ($match->shadowed !== []) {
            $output->line();
            $output->warning('Cette adresse convient aussi à une route déclarée plus loin, qui n\'est donc jamais atteinte pour elle :');

            foreach ($match->shadowed as $shadowed) {
                $output->line('   ' . implode('|', $shadowed->methods) . ' ' . $shadowed->path . '   ' . self::handlerName($shadowed));
            }

            $output->line('   Le routeur prend la PREMIÈRE route qui convient : déclarez la plus précise avant l\'autre.');
        }

        $output->line();
    }

    private function explainJourney(Route $route, string $method, Output $output): void
    {
        $output->line('2. Ce que la requête traverse, dans l\'ordre');
        $output->line();

        $steps = [];

        foreach ($this->app->middlewares() as $middleware) {
            $steps[] = $this->step($middleware, $route, $method);
        }

        $steps[] = ['le routeur', 'choisit la route ci-dessus'];

        foreach ([...$route->middlewares, ...$this->app->router->middlewares()] as $middleware) {
            $steps[] = $this->step($middleware, $route, $method);
        }

        $steps[] = [self::handlerName($route), 'votre code : il retourne la réponse'];

        $width = 0;

        foreach ($steps as $step) {
            $width = max($width, mb_strlen($step[0]));
        }

        foreach ($steps as $number => $step) {
            $label = sprintf('   %2d. ', $number + 1) . $step[0];

            $output->line(rtrim($label . str_repeat(' ', $width - mb_strlen($step[0]) + 3) . $step[1]));
        }

        $output->line();
        $output->line('   La réponse repasse ensuite par les mêmes étapes, en sens inverse.');
        $output->line();
    }

    private function explainHandler(RouteMatch $match, Output $output): void
    {
        $route = $match->route;

        if ($route === null) {
            return;
        }

        $output->line('3. Le code exécuté');
        $output->line();

        $handler = $route->handler;

        if (is_array($handler)) {
            [$class, $methodName] = $handler;

            if (!class_exists($class) || !method_exists($class, $methodName)) {
                $output->line('   ' . $class . '::' . $methodName . '() est introuvable. Vérifiez le nom de la classe et celui de la méthode.');

                return;
            }

            $function = new \ReflectionMethod($class, $methodName);
            $constructor = new \ReflectionClass($class)->getConstructor();

            $output->line('   ' . $class . '::' . $methodName . '()');
            $output->line('   ' . $function->getFileName() . ', ligne ' . $function->getStartLine());
            $output->line();
            $output->line('   Le conteneur fabrique le contrôleur, et lui fournit ce que son constructeur demande :');

            $dependencies = $constructor?->getParameters() ?? [];

            if ($dependencies === []) {
                $output->line('     (rien : son constructeur ne demande aucun service)');
            }

            foreach ($dependencies as $dependency) {
                $output->line('     ' . self::typeOf($dependency) . ' $' . $dependency->getName());
            }
        } else {
            $function = new \ReflectionFunction($handler);

            $output->line('   Une fonction, écrite dans ' . $function->getFileName() . ', ligne ' . $function->getStartLine());
        }

        $output->line();
        $output->line('   Les arguments de ' . ($function instanceof \ReflectionMethod ? 'la méthode' : 'la fonction') . ' :');

        $parameters = $function->getParameters();

        if ($parameters === []) {
            $output->line('     (aucun)');
        }

        foreach ($parameters as $parameter) {
            $output->line('     ' . trim(self::typeOf($parameter) . ' $' . $parameter->getName()) . '   ← ' . self::sourceOf($parameter, $match->parameters));
        }
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * Une étape du trajet : le nom court du middleware, et ce qu'il fait.
     *
     * @return array{string, string}
     */
    private function step(object|string $middleware, Route $route, string $method): array
    {
        $class = is_string($middleware) ? $middleware : $middleware::class;
        $name = substr($class, (int) strrpos('\\' . $class, '\\'));

        if ($class === CsrfProtection::class) {
            return [$name, $this->csrfDescription($route, $method)];
        }

        return [$name, self::DESCRIPTIONS[$class] ?? self::summaryOf($class)];
    }

    /**
     * Ce que la protection des formulaires fera de CETTE requête.
     */
    private function csrfDescription(Route $route, string $method): string
    {
        if (in_array($method, self::SAFE_METHODS, true)) {
            return 'ne demande rien : ' . $method . ' ne fait que lire';
        }

        foreach ($route->middlewares as $middleware) {
            if ((is_string($middleware) ? $middleware : $middleware::class) === WithoutCsrf::class) {
                return 'ne demande rien : la route en est dispensée par WithoutCsrf';
            }
        }

        return 'exige le jeton du formulaire ; sans lui, la réponse est 403';
    }

    /**
     * La première phrase du commentaire d'une classe : c'est ainsi que vos
     * propres middlewares se présentent, avec vos mots.
     */
    private static function summaryOf(string $class): string
    {
        if (!class_exists($class)) {
            return '(classe introuvable)';
        }

        $comment = new \ReflectionClass($class)->getDocComment();

        if ($comment === false) {
            return '';
        }

        foreach (explode("\n", $comment) as $line) {
            $line = trim($line, " \t\r/*");

            if ($line !== '' && !str_starts_with($line, '@')) {
                return mb_strimwidth($line, 0, 90, '…');
            }
        }

        return '';
    }

    private static function handlerName(Route $route): string
    {
        $handler = $route->handler;

        if (is_array($handler)) {
            return $handler[0] . '::' . $handler[1] . '()';
        }

        $function = new \ReflectionFunction($handler);

        return 'fonction (' . basename((string) $function->getFileName()) . ', ligne ' . $function->getStartLine() . ')';
    }

    private static function typeOf(\ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();

        return $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;
    }

    /**
     * D'où vient la valeur d'un argument : les mêmes règles que RouteRunner, dans le même ordre.
     *
     * @param array<string, string|int> $routeParameters
     */
    private static function sourceOf(\ReflectionParameter $parameter, array $routeParameters): string
    {
        $type = $parameter->getType();

        if ($type instanceof \ReflectionNamedType && is_a($type->getName(), ServerRequestInterface::class, true)) {
            return 'la requête';
        }

        if (array_key_exists($parameter->getName(), $routeParameters)) {
            return 'le paramètre {' . $parameter->getName() . '} de la route : ' . $routeParameters[$parameter->getName()];
        }

        if ($parameter->isDefaultValueAvailable()) {
            return 'sa valeur par défaut';
        }

        return 'RIEN ne le fournit : ce n\'est ni la requête, ni un paramètre de la route. La page donnera une erreur.';
    }
}
