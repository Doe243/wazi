<?php

declare(strict_types=1);

namespace Demo;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Wazi\Http\CsrfToken;
use Wazi\Http\Response;
use Wazi\Http\Session;
use Wazi\Routing\Attribute\Get;
use Wazi\Routing\Attribute\Patch;
use Wazi\Routing\Attribute\Post;

/**
 * Le carnet d'un visiteur connecté : lire, ajouter, modifier, supprimer.
 *
 * Chaque route porte le middleware ConnexionRequise : arrivé dans une de ces
 * méthodes, on sait qu'un visiteur est connecté.
 *
 * Le parcours d'un formulaire est toujours le même :
 *
 *     POST  →  on vérifie  →  on enregistre  →  on note un message  →  on redirige (303)
 *
 * La redirection évite qu'un rechargement de la page renvoie le formulaire ;
 * le message, noté par flash(), survit à la redirection et s'affiche une fois.
 */
final readonly class NoteController
{
    public function __construct(
        private Carnet $carnet,
        private Pages $pages,
        private Session $session,
        private CsrfToken $jeton,
    ) {}

    #[Get('/notes', [ConnexionRequise::class])]
    public function liste(ServerRequestInterface $request): ResponseInterface
    {
        return $this->pageListe($request);
    }

    #[Post('/notes', [ConnexionRequise::class])]
    public function ajouter(ServerRequestInterface $request): ResponseInterface
    {
        $texte = self::texte($request);
        $erreur = self::erreurDe($texte);

        if ($erreur !== null) {
            // On réaffiche le formulaire avec ce qui a été saisi, et le code
            // 422 : « j'ai compris la demande, mais son contenu ne convient pas ».
            return $this->pageListe($request, $texte, $erreur, 422);
        }

        $this->carnet->ajouter($this->auteur(), $texte);
        $this->session->flash('succes', 'Note ajoutée.');

        return new Response(303, ['Location' => '/notes']);
    }

    // {id:int} dans le chemin, « int $id » dans la méthode : le même nom, donc la valeur arrive ici.
    #[Get('/notes/{id:int}', [ConnexionRequise::class])]
    public function voir(int $id): ResponseInterface
    {
        // trouver() demande l'auteur : la note de quelqu'un d'autre est
        // « introuvable », exactement comme une note qui n'existe pas.
        $note = $this->carnet->trouver($id, $this->auteur());

        return $note === null
            ? $this->pages->page('notes/introuvable', ['id' => $id], 404)
            : $this->pages->page('notes/note', ['note' => $note, 'saisie' => $note['texte'], 'longueur_max' => Carnet::LONGUEUR_MAX]);
    }

    #[Post('/notes/{id:int}', [ConnexionRequise::class])]
    public function modifier(ServerRequestInterface $request, int $id): ResponseInterface
    {
        $note = $this->carnet->trouver($id, $this->auteur());

        if ($note === null) {
            return $this->pages->page('notes/introuvable', ['id' => $id], 404);
        }

        $texte = self::texte($request);
        $erreur = self::erreurDe($texte);

        if ($erreur !== null) {
            return $this->pages->page('notes/note', ['note' => $note, 'saisie' => $texte, 'erreur' => $erreur, 'longueur_max' => Carnet::LONGUEUR_MAX], 422);
        }

        // Une case à cocher non cochée n'est pas envoyée du tout.
        $importante = ((array) $request->getParsedBody())['importante'] ?? null;
        $this->carnet->modifier($id, $this->auteur(), $texte, $importante === '1');
        $this->session->flash('succes', 'Note modifiée.');

        return new Response(303, ['Location' => '/notes']);
    }

    #[Post('/notes/{id:int}/supprimer', [ConnexionRequise::class])]
    public function supprimer(int $id): ResponseInterface
    {
        if ($this->carnet->supprimer($id, $this->auteur())) {
            $this->session->flash('succes', 'Note supprimée.');
        } else {
            $this->session->flash('erreur', 'Cette note n\'existe pas.');
        }

        return new Response(303, ['Location' => '/notes']);
    }

    /**
     * Appelée par le script de la page (public/app.js), sans recharger la page.
     * La réponse n'est pas une page mais du JSON.
     */
    #[Patch('/notes/{id:int}/importante', [ConnexionRequise::class])]
    public function basculer(int $id): ResponseInterface
    {
        $importante = $this->carnet->basculer($id, $this->auteur());

        return $importante === null
            ? self::json(['erreur' => 'Cette note n\'existe pas.'], 404)
            : self::json(['importante' => $importante]);
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    private function pageListe(ServerRequestInterface $request, string $saisie = '', ?string $erreur = null, int $statut = 200): ResponseInterface
    {
        $notes = $this->carnet->de($this->auteur());

        return $this->pages->page('notes/liste', [
            'notes' => $notes,
            'importantes' => count(array_filter($notes, static fn(array $note): bool => $note['importante'])),
            'saisie' => $saisie,
            'erreur' => $erreur,
            // Le script de la page envoie ce jeton dans l'en-tête X-CSRF-Token.
            'jeton' => $this->jeton->value(),
            // L'adresse du visiteur, telle que Wazi l'a établie (ADR-022).
            'adresse' => $request->getAttribute('client_ip'),
            'longueur_max' => Carnet::LONGUEUR_MAX,
        ], $statut);
    }

    private function auteur(): string
    {
        // ConnexionRequise garantit qu'un visiteur est connecté.
        return $this->pages->utilisateur() ?? throw new \LogicException('Cette route doit porter le middleware ConnexionRequise.');
    }

    private static function texte(ServerRequestInterface $request): string
    {
        $texte = ((array) $request->getParsedBody())['texte'] ?? null;

        return is_string($texte) ? trim($texte) : '';
    }

    /**
     * Ce qui ne va pas dans le texte d'une note, ou null si tout va bien.
     *
     * Le navigateur vérifie déjà (attributs required et maxlength), mais on
     * peut envoyer un formulaire sans navigateur : seule la vérification
     * faite ici, sur le serveur, compte.
     */
    private static function erreurDe(string $texte): ?string
    {
        return match (true) {
            $texte === '' => 'Écrivez quelque chose : une note vide n\'est pas enregistrée.',
            mb_strlen($texte) > Carnet::LONGUEUR_MAX => 'Cette note fait ' . mb_strlen($texte) . ' caractères. Le maximum est de ' . Carnet::LONGUEUR_MAX . '.',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $donnees
     */
    private static function json(array $donnees, int $statut = 200): ResponseInterface
    {
        return new Response($statut, ['Content-Type' => 'application/json'], json_encode($donnees, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
