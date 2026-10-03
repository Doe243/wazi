<?php

declare(strict_types=1);

namespace Wazi\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Http\Exception\InvalidMessageException;
use Wazi\Http\Response;

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
 *       La liste de ce que la page a le droit de charger. Par défaut : uniquement
 *       ce qui vient de votre propre site. Un script injecté dans une page par
 *       un attaquant (faille XSS) est alors refusé par le navigateur.
 *
 * Un en-tête déjà posé par votre contrôleur n'est jamais remplacé : c'est la
 * façon locale de faire une exception pour une page.
 *
 * ⚠ La politique par défaut interdit les scripts écrits dans la page
 * (<script>...</script>, onclick="...") et ceux d'un autre site (un CDN).
 * Si un script ne s'exécute pas, ouvrez la console du navigateur (F12) : il y
 * explique ce qu'il a bloqué. Deux solutions : mettre le script dans un
 * fichier .js de votre site, ou donner votre propre politique :
 *
 *     new SecurityHeaders("default-src 'self'; script-src 'self' https://cdn.exemple.com")
 */
final readonly class SecurityHeaders implements MiddlewareInterface
{
    /**
     * Tout vient de votre site ('self'). Deux assouplissements, peu risqués et
     * très courants : les styles écrits dans la page, et les images intégrées (data:).
     */
    public const string DEFAULT_CONTENT_SECURITY_POLICY = "default-src 'self'; style-src 'self' 'unsafe-inline';"
        . " img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    /** @var array<string, string> */
    private array $headers;

    /**
     * @param string|null $contentSecurityPolicy votre politique, ou null pour n'en envoyer aucune (déconseillé)
     *
     * @throws InvalidMessageException si la politique contient un caractère interdit dans un en-tête
     */
    public function __construct(?string $contentSecurityPolicy = self::DEFAULT_CONTENT_SECURITY_POLICY)
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];

        if ($contentSecurityPolicy !== null) {
            $headers['Content-Security-Policy'] = $contentSecurityPolicy;
        }

        // Une politique invalide doit se voir dès le démarrage, pas à la
        // première visite : on fait valider les en-têtes par une Response.
        new Response(200, $headers);

        $this->headers = $headers;
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
}
