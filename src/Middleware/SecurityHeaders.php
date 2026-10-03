<?php

declare(strict_types=1);

namespace Wazi\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Http\Exception\InvalidMessageException;
use Wazi\Http\Exception\InvalidUriException;
use Wazi\Http\Response;
use Wazi\Http\Uri;
use Wazi\Middleware\Exception\InvalidSecurityPolicyException;

/**
 * Ajoute à chaque réponse les en-têtes qui demandent au navigateur de protéger vos visiteurs.
 *
 *   X-Content-Type-Options: nosniff
 *       Le navigateur respecte le type annoncé : un fichier envoyé par un
 *       visiteur ne sera pas exécuté comme un script parce qu'il y ressemble.
 *
 *   X-Frame-Options: DENY
 *       Vos pages ne peuvent pas être affichées dans le cadre d'un autre site,
 *       qui piégerait les clics de vos visiteurs.
 *
 *   Referrer-Policy: strict-origin-when-cross-origin
 *       Quand un visiteur suit un lien vers un autre site, celui-ci apprend
 *       d'où il vient, mais pas l'adresse complète de la page.
 *
 *   Content-Security-Policy
 *       La liste de ce que la page a le droit de charger (ADR-014).
 *
 * La politique par défaut n'est stricte que sur ce qui est dangereux : le
 * JavaScript. Un script injecté dans une page par un attaquant (faille XSS)
 * peut tout faire au nom du visiteur ; une image ou une police venue
 * d'ailleurs, presque rien.
 *
 *     Feuilles de style, polices, images, vidéos, cadres (YouTube...)
 *         → autorisés depuis n'importe quel site en https, sans rien régler.
 *     Scripts
 *         → uniquement les fichiers .js de votre propre site.
 *
 * Pour utiliser le JavaScript d'un autre site (un CDN), déclarez ce site :
 *
 *     new Kernel(securityHeaders: new SecurityHeaders(scripts: ['https://cdn.jsdelivr.net']));
 *
 * ⚠ Les scripts écrits dans la page (<script>...</script>, onclick="...")
 * restent refusés : c'est exactement la forme que prend une attaque. Mettez
 * votre script dans un fichier .js de votre site. Si un script ne s'exécute
 * pas, la console du navigateur (F12) dit ce qui a été bloqué.
 *
 * Un en-tête déjà posé par votre contrôleur n'est jamais remplacé : c'est la
 * façon de faire une exception pour une seule page.
 */
final readonly class SecurityHeaders implements MiddlewareInterface
{
    /** À passer en contentSecurityPolicy pour n'envoyer aucune politique (déconseillé). */
    public const string WITHOUT_POLICY = '';

    private const string POLICY_UP_TO_SCRIPTS = "default-src 'self'; script-src 'self'";

    private const string POLICY_AFTER_SCRIPTS = "; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: https:;"
        . " font-src 'self' data: https:; media-src 'self' https:; frame-src 'self' https:; connect-src 'self' https:;"
        . " object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    /** La politique envoyée quand vous ne réglez rien. */
    public const string DEFAULT_CONTENT_SECURITY_POLICY = self::POLICY_UP_TO_SCRIPTS . self::POLICY_AFTER_SCRIPTS;

    /** Ce qui, dans une source, permettrait d'ajouter d'autres règles à la politique. */
    private const string FORBIDDEN_IN_SOURCE = '/[\s;,\'"*]/';

    /** @var array<string, string> */
    private array $headers;

    /**
     * @param list<string> $scripts               les sites dont vos pages peuvent charger du JavaScript, par exemple 'https://cdn.jsdelivr.net'
     * @param string|null  $contentSecurityPolicy pour écrire toute la politique vous-même ; self::WITHOUT_POLICY pour n'en envoyer aucune
     *
     * @throws InvalidSecurityPolicyException si une source de scripts est refusée, ou si les deux arguments sont donnés ensemble
     * @throws InvalidMessageException        si la politique contient un caractère interdit dans un en-tête
     */
    public function __construct(array $scripts = [], ?string $contentSecurityPolicy = null)
    {
        if ($scripts !== [] && $contentSecurityPolicy !== null) {
            throw InvalidSecurityPolicyException::scriptsWithCustomPolicy();
        }

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];

        $policy = $contentSecurityPolicy ?? self::policyAllowing($scripts);

        if ($policy !== self::WITHOUT_POLICY) {
            $headers['Content-Security-Policy'] = $policy;
        }

        // Une politique invalide doit se voir dès le démarrage, pas à la
        // première visite : on fait valider les en-têtes par une Response.
        $this->headers = array_map(
            static fn(array $values): string => implode(', ', $values),
            new Response(200, $headers)->getHeaders(),
        );
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        foreach ($this->headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * @param list<string> $scripts
     */
    private static function policyAllowing(array $scripts): string
    {
        $sources = '';

        foreach ($scripts as $source) {
            if (!is_string($source) || !self::isHttpsSite($source)) {
                throw InvalidSecurityPolicyException::invalidScriptSource(
                    is_string($source) ? $source : get_debug_type($source),
                );
            }

            $sources .= ' ' . $source;
        }

        return self::POLICY_UP_TO_SCRIPTS . $sources . self::POLICY_AFTER_SCRIPTS;
    }

    /**
     * Une source acceptée est l'adresse d'un site en https, avec un chemin si
     * l'on veut : rien qui puisse ajouter une autre règle à la politique.
     */
    private static function isHttpsSite(string $source): bool
    {
        if (preg_match(self::FORBIDDEN_IN_SOURCE, $source) === 1) {
            return false;
        }

        try {
            $uri = new Uri($source);
        } catch (InvalidUriException) {
            return false;
        }

        return $uri->getScheme() === 'https'
            && $uri->getHost() !== ''
            && $uri->getUserInfo() === ''
            && $uri->getQuery() === ''
            && $uri->getFragment() === '';
    }
}
