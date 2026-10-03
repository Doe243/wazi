<?php

declare(strict_types=1);

namespace Demo;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Http\Response;
use Wazi\Http\Session;

/**
 * Le middleware : un garde placé devant les routes réservées aux visiteurs connectés.
 *
 * S'il n'appelle pas $handler->handle(), la suite ne s'exécute jamais : le
 * contrôleur n'est même pas fabriqué.
 *
 * Il se pose sur une route en une ligne :
 *
 *     #[Get('/notes', [ConnexionRequise::class])]
 */
final readonly class ConnexionRequise implements MiddlewareInterface
{
    public function __construct(private Session $session) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!is_string($this->session->get('utilisateur'))) {
            // 303 : « allez voir ailleurs, avec une requête GET ».
            //
            // On ne note rien dans la session ici (pas de message flash) :
            // lire une session ne crée aucun fichier, y écrire en crée un. Un
            // robot qui frapperait mille fois à cette porte créerait mille
            // fichiers. L'explication est donc portée par l'adresse.
            return new Response(303, ['Location' => '/connexion?retour=1']);
        }

        return $handler->handle($request);
    }
}
