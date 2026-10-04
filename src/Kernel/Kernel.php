<?php

declare(strict_types=1);

namespace Wazi\Kernel;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Container\Container;
use Wazi\Errors\ErrorHandler;
use Wazi\Http\CspNonce;
use Wazi\Http\CsrfToken;
use Wazi\Http\Exception\EmitterException;
use Wazi\Http\Exception\InvalidMiddlewareException;
use Wazi\Http\LocalPath;
use Wazi\Http\Pipeline;
use Wazi\Http\ResponseEmitter;
use Wazi\Http\ServerRequestCreator;
use Wazi\Http\Session;
use Wazi\Kernel\Exception\KernelException;
use Wazi\Middleware\CsrfCookie;
use Wazi\Middleware\CsrfProtection;
use Wazi\Middleware\FileSessionStore;
use Wazi\Middleware\SecurityHeaders;
use Wazi\Middleware\SessionMiddleware;
use Wazi\Routing\Router;
use Wazi\View\Kioo;

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

    /** Là où vous déclarez vos routes : $app->router->get(...). */
    public Router $router;

    /**
     * Les middlewares que traverse chaque requête avant le routeur.
     *
     * @var list<MiddlewareInterface|string>
     */
    private array $middlewares;

    /**
     * @param bool                    $development     true pour voir le message des erreurs dans le navigateur ; à ne jamais activer en production
     * @param Container               $container       là où vous expliquez comment fabriquer vos services : $app->container->set(...)
     * @param array<array-key, mixed> $middlewares     vos middlewares (objets, ou noms de classes), du plus extérieur au plus intérieur
     * @param SecurityHeaders|null    $securityHeaders les en-têtes de sécurité, placés avant vos middlewares ; null pour les retirer
     * @param string|null             $views           le dossier de vos templates Kioo : vos contrôleurs peuvent alors demander un Kioo dans leur constructeur
     * @param string|null             $sessions        le dossier où ranger les sessions (hors du dossier public) : vos contrôleurs peuvent alors demander une Session
     *
     * @throws InvalidMiddlewareException si la liste contient autre chose qu'un middleware
     */
    public function __construct(
        bool $development = false,
        ?Router $router = null,
        private ServerRequestCreator $requestCreator = new ServerRequestCreator(),
        ?ErrorHandler $errorHandler = null,
        private ResponseEmitter $emitter = new ResponseEmitter(),
        array $middlewares = [],
        ?SecurityHeaders $securityHeaders = new SecurityHeaders(),
        public Container $container = new Container(),
        ?string $views = null,
        ?string $sessions = null,
    ) {
        $this->errorHandler = $errorHandler ?? new ErrorHandler($development);

        // Un seul jeton pour la requête, partagé par les deux qui en ont besoin :
        // SecurityHeaders l'annonce dans l'en-tête, Kioo le pose sur les <script>.
        $nonce = new CspNonce();
        $securityHeaders = $securityHeaders?->withNonce($nonce);
        $this->container->set(CspNonce::class, static fn(): CspNonce => $nonce);

        // La protection des formulaires (ADR-023), toujours active : CsrfCookie
        // transporte le jeton, CsrfProtection le vérifie sur chaque route, et
        // Kioo l'écrit dans les formulaires.
        $csrf = new CsrfToken();
        $this->container->set(CsrfToken::class, static fn(): CsrfToken => $csrf);
        $ahead = [new CsrfCookie($csrf)];

        // Les sessions (ADR-021). Une seule Session pour la requête : celle que
        // le middleware remplit est celle que reçoivent vos contrôleurs.
        if ($sessions !== null) {
            $session = new Session();
            $this->container->set(Session::class, static fn(): Session => $session);
            $ahead[] = new SessionMiddleware($session, new FileSessionStore($sessions));
        }

        if ($views !== null) {
            $this->container->set(Kioo::class, static fn(): Kioo => new Kioo($views, [], $nonce, $csrf));
        }

        // Le routeur et le noyau partagent le même conteneur : un service
        // n'existe qu'en un exemplaire dans toute l'application.
        $this->router = $router ?? new Router($this->container, [new CsrfProtection($csrf)]);

        // SecurityHeaders est le plus à l'extérieur : il voit passer la
        // réponse en dernier, après tous vos middlewares.
        $this->middlewares = Pipeline::declared(
            [...($securityHeaders !== null ? [$securityHeaders] : []), ...$ahead, ...array_values($middlewares)],
        );
    }

    /**
     * Charge l'application construite par le fichier app.php de votre projet.
     *
     *     // public/index.php
     *     Kernel::load(__DIR__ . '/../app.php')->run();
     *
     * app.php crée le noyau, déclare les services et les routes, et se termine
     * par « return $app; ». Le site et la console le chargent tous les deux :
     * ils partagent ainsi exactement la même application (ADR-027).
     *
     * @param string $file le chemin de app.php, écrit dans votre code
     *
     * @throws KernelException si le fichier est introuvable, ou s'il ne retourne pas un Kernel
     */
    public static function load(string $file): self
    {
        // Sécurité : seul un fichier ordinaire est chargé, jamais une adresse
        // à protocole (http://, phar://...). Ce chemin vient de votre code,
        // jamais d'une requête.
        if (!LocalPath::isPlain($file) || !is_file($file)) {
            throw KernelException::applicationFileNotFound($file);
        }

        // Une fonction à part : les variables de app.php ne se mêlent à aucune autre.
        $application = (static fn(): mixed => require $file)();

        return $application instanceof self
            ? $application
            : throw KernelException::applicationNotReturned($file, get_debug_type($application));
    }

    /**
     * Les middlewares que traverse chaque requête avant le routeur, du plus
     * extérieur au plus intérieur : ceux de Wazi, puis les vôtres.
     *
     * C'est une lecture, pour « wazi explain ».
     *
     * @return list<MiddlewareInterface|string>
     */
    public function middlewares(): array
    {
        return $this->middlewares;
    }

    /**
     * Répond à la requête reçue par PHP : c'est ce que fait votre fichier
     * public/index.php, une fois l'application chargée.
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
            // Le pipeline est assemblé ici, et non dans le constructeur : les
            // middlewares désignés par un nom de classe sont fabriqués par le
            // conteneur, que vous avez pu régler après avoir créé le noyau.
            return Pipeline::resolved($this->middlewares, $this->router, $this->container)->handle($request);
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
