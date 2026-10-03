<?php

/**
 * Une application Wazi complète, en un seul fichier.
 *
 * Pour l'essayer, depuis le dossier du framework :
 *
 *     php -S localhost:8000 examples/bonjour.php
 *
 * puis ouvrez http://localhost:8000 dans votre navigateur.
 */

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Wazi\Http\Response;
use Wazi\Kernel\Kernel;

require __DIR__ . '/../vendor/autoload.php';

// Le mode développement affiche le message des erreurs dans le navigateur.
// Ne l'activez jamais sur un site en ligne : retirez l'argument, et le
// visiteur ne verra plus qu'une page d'erreur générique.
$app = new Kernel(development: true);

/**
 * Enveloppe un contenu dans une page HTML. Tout ce qui vient de l'adresse est
 * échappé avec htmlspecialchars() avant d'être affiché.
 */
$page = static fn(string $title, string $html): ResponseInterface => new Response(
    200,
    ['Content-Type' => 'text/html; charset=utf-8'],
    '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>'
    . htmlspecialchars($title) . '</title></head><body><h1>' . htmlspecialchars($title) . '</h1>' . $html
    . '<p><a href="/">Accueil</a></p></body></html>',
);

// Une route sans paramètre.
$app->router->get('/', static fn(ServerRequestInterface $request): ResponseInterface => $page(
    'Bonjour !',
    '<ul>'
    . '<li><a href="/articles/42">Un article, avec un paramètre entier</a></li>'
    . '<li><a href="/bonjour/René">Un paramètre de texte</a></li>'
    . '<li><a href="/articles/abc">Une adresse qui ne respecte pas la contrainte (404)</a></li>'
    . '<li><a href="/erreur">Une erreur dans le code (500)</a></li>'
    . '</ul>',
));

// {id:int} n'accepte qu'un nombre entier : la fonction reçoit un vrai int.
$app->router->get('/articles/{id:int}', static function (ServerRequestInterface $request) use ($page): ResponseInterface {
    $id = $request->getAttribute('id');

    return $page('Article n° ' . $id, '<p>Le paramètre est de type <code>' . get_debug_type($id) . '</code>.</p>');
});

// Sans contrainte, le paramètre est un texte : on l'échappe avant de l'afficher.
$app->router->get('/bonjour/{prenom}', static function (ServerRequestInterface $request) use ($page): ResponseInterface {
    $prenom = $request->getAttribute('prenom');

    return $page('Bonjour ' . (is_string($prenom) ? $prenom : ''), '');
});

// Une erreur volontaire, pour voir la page d'erreur pédagogique.
$app->router->get('/erreur', static function (ServerRequestInterface $request) {
    // Oubli classique : on retourne un texte au lieu d'une réponse.
    return 'Bonjour';
});

$app->run();
