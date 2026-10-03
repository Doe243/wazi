<?php

declare(strict_types=1);

namespace Wazi\Kernel;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Errors\ErrorHandler;
use Wazi\Http\Exception\EmitterException;
use Wazi\Http\ResponseEmitter;
use Wazi\Http\ServerRequestCreator;
use Wazi\Middleware\Exception\InvalidMiddlewareException;
use Wazi\Middleware\Pipeline;
use Wazi\Middleware\SecurityHeaders;
use Wazi\Routing\Router;

/**
 * Le noyau : il assemble les composants de Wazi et fait faire à une requête
 * tout son trajet.
 *
 *     $app = new Kernel();
 *
 *     $app->router->get('/', fn (ServerRequestInterface $request) => new Response(200, [], 'Bonjour'));
 *
 *     $app->run();
 *
 * Ce que fait run(), dans l'ordre :
 *
 *     variables globales de PHP
 *            │  ServerRequestCreator : construit la requête, refuse ce qui est inacceptable
 *            ▼
 *        ServerRequest
 *            │  Pipeline : fait traverser les middlewares (en-têtes de sécurité, puis les vôtres)
 *            │  Router : choisit la route et exécute sa fonction
 *            ▼
 *         Response            ◄── en cas d'exception : ErrorHandler fabrique la page d'erreur
 *            │  ResponseEmitter : envoie en-têtes et corps
 *            ▼
 *        navigateur
 *
 * Le noyau ne contient aucune logique à lui : chaque étape appartient à un
 * composant, que vous pouvez ouvrir d'un clic. C'est le seul endroit qui a le
 * droit de connaître tous les composants (règle des couches).
 *
 * Sécurité (ADR-006 et ADR-011) :
 *   - le mode production est le défaut. Le mode développement, qui montre le
 *     message des erreurs dans le navigateur, se demande explicitement :
 *     new Kernel(development: true). Il n'est jamais deviné ;
 *   - run() interdit à PHP d'afficher lui-même ses erreurs dans la page : elles
 *     passent toutes par ErrorHandler, qui décide de ce que voit le visiteur ;
 *   - les en-têtes de sécurité (SecurityHeaders) sont ajoutés à chaque réponse,
 *     que vous déclariez ou non vos propres middlewares. Pour les régler, passez
 *     votre propre objet : new Kernel(securityHeaders: new SecurityHeaders(...)).
 *     Pour les retirer, il faut l'écrire : new Kernel(securityHeaders: null).
 */
final readonly class Kernel implements RequestHandlerInterface
{
    /** Les erreurs que PHP ne permet pas de rattraper : le script s'arrête net. */
    private const array FATAL_ERRORS = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    private ErrorHandler $errorHandler;

    /** Les middlewares et le routeur, assemblés : c'est ce que traverse chaque requête. */
    private Pipeline $pipeline;

    /**
     * @param bool                    $development     true pour voir le message des erreurs dans le navigateur ; à ne jamais activer en production
     * @param array<array-key, mixed> $middlewares     vos middlewares (objets MiddlewareInterface), du plus extérieur au plus intérieur
     * @param SecurityHeaders|null    $securityHeaders les en-têtes de sécurité, placés avant vos middlewares ; null pour les retirer
     *
     * @throws InvalidMiddlewareException si la liste contient autre chose qu'un middleware
     */
    public function __construct(
        bool $development = false,
        public Router $router = new Router(),
        private ServerRequestCreator $requestCreator = new ServerRequestCreator(),
        ?ErrorHandler $errorHandler = null,
        private ResponseEmitter $emitter = new ResponseEmitter(),
        array $middlewares = [],
        ?SecurityHeaders $securityHeaders = new SecurityHeaders(),
    ) {
        $this->errorHandler = $errorHandler ?? new ErrorHandler($development);

        // SecurityHeaders est le plus à l'extérieur : il voit passer la
        // réponse en dernier, après tous vos middlewares.
        $this->pipeline = new Pipeline(
            $securityHeaders !== null ? [$securityHeaders, ...array_values($middlewares)] : $middlewares,
            $this->router,
        );
    }

    /**
     * Répond à la requête reçue par PHP : c'est la seule ligne à écrire à la
     * fin de votre fichier public/index.php.
     */
    public function run(): void
    {
        $this->takeOverPhpErrors();

        // Une requête HEAD attend les mêmes en-têtes qu'un GET, sans le corps.
        $withBody = ($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD';

        try {
            $response = $this->handle($this->requestCreator->fromGlobals());
        } catch (\Throwable $error) {
            // La requête elle-même a été refusée (corps trop gros, Host falsifié...).
            $response = $this->errorHandler->handle($error);
        }

        $this->send($response, $withBody);
    }

    /**
     * Transforme une requête en réponse, sans rien lire ni envoyer : c'est
     * cette méthode qu'on appelle dans un test.
     *
     * Ne lève jamais d'exception : une erreur devient une page d'erreur.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->pipeline->handle($request);
        } catch (\Throwable $error) {
            return $this->errorHandler->handle($error);
        }
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    private function send(ResponseInterface $response, bool $withBody): void
    {
        try {
            $this->emitter->emit($response, $withBody);
        } catch (EmitterException $error) {
            // Quelque chose a été affiché avant la réponse (un echo oublié).
            // Si rien n'est encore parti, on jette cet affichage et on envoie
            // la page d'erreur qui explique le problème.
            $errorResponse = $this->errorHandler->handle($error);

            if ($this->emitter->discardPendingOutput()) {
                $this->emitter->emit($errorResponse, $withBody);
            }
        }
    }

    /**
     * Fait passer toutes les erreurs de PHP par ErrorHandler.
     *
     * Par défaut, PHP peut écrire ses avertissements directement dans la page,
     * avec le chemin des fichiers du serveur : le visiteur les verrait, et
     * l'envoi de la réponse échouerait. On lui interdit d'afficher, on lui
     * demande de consigner, et on transforme ses avertissements en exceptions.
     */
    private function takeOverPhpErrors(): void
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            // error_reporting() vaut 0 pour ce niveau quand l'erreur a été
            // volontairement tue (opérateur @) ou exclue par la configuration.
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            // Une dépréciation annonce un changement futur de PHP ou d'une
            // bibliothèque : elle va au journal, sans interrompre la page.
            if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        // Une erreur fatale (mémoire épuisée, temps dépassé) arrête PHP sans
        // passer par aucun try/catch. Cette fonction s'exécute quand même, à
        // la toute fin : elle évite la page blanche.
        register_shutdown_function(function (): void {
            $last = error_get_last();

            if ($last === null || !in_array($last['type'], self::FATAL_ERRORS, true)) {
                return;
            }

            $error = new \ErrorException($last['message'], 0, $last['type'], $last['file'], $last['line']);
            $response = $this->errorHandler->handle($error);

            if ($this->emitter->discardPendingOutput()) {
                $this->emitter->emit($response);
            }
        });
    }
}
