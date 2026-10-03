<?php

declare(strict_types=1);

namespace Demo;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Wazi\Http\Response;
use Wazi\Http\Session;
use Wazi\Routing\Attribute\Get;
use Wazi\Routing\Attribute\Post;

/**
 * Se connecter, se déconnecter.
 *
 * Wazi ne fournit pas (encore) de composant d'authentification : ce
 * contrôleur montre de quoi une connexion est faite, pièce par pièce.
 *
 * Ce que cette démonstration ne fait pas : limiter le nombre d'essais. Un
 * vrai site doit ralentir ou bloquer quelqu'un qui essaie des milliers de
 * mots de passe.
 */
final readonly class ConnexionController
{
    public function __construct(private Pages $pages, private Session $session, private Comptes $comptes) {}

    #[Get('/connexion')]
    public function formulaire(ServerRequestInterface $request): ResponseInterface
    {
        // « ?retour=1 » est ajouté par ConnexionRequise quand un visiteur a
        // voulu ouvrir une page réservée. On ne se sert que de sa présence :
        // ce que contient l'adresse n'est jamais recopié dans la page.
        $renvoye = array_key_exists('retour', $request->getQueryParams());

        return $this->pages->page('connexion', ['nom' => '', 'renvoye' => $renvoye]);
    }

    #[Post('/connexion')]
    public function connecter(ServerRequestInterface $request): ResponseInterface
    {
        // Arrivé ici, le jeton du formulaire a déjà été vérifié par Wazi
        // (CsrfProtection) : ce formulaire vient bien d'une page de ce site.
        $formulaire = (array) $request->getParsedBody();
        $nom = is_string($formulaire['nom'] ?? null) ? trim($formulaire['nom']) : '';
        $motDePasse = is_string($formulaire['mot_de_passe'] ?? null) ? $formulaire['mot_de_passe'] : '';

        if (!$this->comptes->verifier($nom, $motDePasse)) {
            // Le message ne dit pas si c'est le nom ou le mot de passe qui est
            // faux : ce serait dire à un inconnu quels comptes existent.
            // Le mot de passe saisi n'est jamais renvoyé dans la page.
            return $this->pages->page('connexion', ['nom' => $nom, 'renvoye' => false, 'erreur' => 'Nom ou mot de passe incorrect.'], 422);
        }

        // Un identifiant de session tout neuf : si quelqu'un avait réussi à
        // imposer le sien à ce navigateur avant la connexion, il ne vaut plus rien.
        $this->session->regenerate();
        $this->session->set('utilisateur', $nom);

        // « Se souvenir de moi » : seulement si le visiteur a coché la case.
        if (($formulaire['se_souvenir'] ?? null) === '1') {
            $this->session->remember(30);
        }

        $this->session->flash('succes', 'Bonjour ' . ucfirst($nom) . ', vous êtes connecté.');

        return new Response(303, ['Location' => '/notes']);
    }

    #[Post('/deconnexion')]
    public function deconnecter(): ResponseInterface
    {
        // clear() oublie tout et change d'identifiant. Le message est noté
        // APRÈS : il est la seule chose que retiendra la nouvelle session.
        $this->session->clear();
        $this->session->flash('succes', 'Vous êtes déconnecté.');

        return new Response(303, ['Location' => '/']);
    }
}
