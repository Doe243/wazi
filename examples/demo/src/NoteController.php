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
use Wazi\Validation\Validator;
use Wazi\View\Kioo;

/**
 * Le carnet d'un visiteur connecté : lire, chercher, ajouter, modifier, supprimer.
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
    /** Au-delà, ce n'est plus une recherche. */
    private const int RECHERCHE_MAX = 60;

    public function __construct(
        private Carnet $carnet,
        private Kioo $kioo,
        private Visiteur $visiteur,
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
        // Le navigateur vérifie déjà (attributs required et maxlength), mais on
        // peut envoyer un formulaire sans navigateur : seule la vérification
        // faite ici, sur le serveur, compte.
        $v = new Validator($request->getParsedBody());
        $texte = $v->longText('texte', max: Carnet::LONGUEUR_MAX);
        // La liste des couleurs affichée ne protège rien : la valeur reçue
        // doit être une des couleurs permises.
        $couleur = $v->choice('couleur', Carnet::COULEURS, required: false) ?: Carnet::COULEURS[0];

        if ($v->fails()) {
            // On réaffiche le formulaire avec ce qui a été saisi, et le code
            // 422 : « j'ai compris la demande, mais son contenu ne convient pas ».
            return $this->pageListe($request, $v->input()['texte'] ?? '', $couleur, implode(' ', $v->errors()), 422);
        }

        $this->carnet->ajouter($this->auteur(), $texte, $couleur);
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
            ? $this->kioo->page('notes/introuvable', ['id' => $id, 'page' => 'notes'], 404)
            : $this->pageNote($note, $note['texte'], $note['couleur'], $note['importante']);
    }

    #[Post('/notes/{id:int}', [ConnexionRequise::class])]
    public function modifier(ServerRequestInterface $request, int $id): ResponseInterface
    {
        $note = $this->carnet->trouver($id, $this->auteur());

        if ($note === null) {
            return $this->kioo->page('notes/introuvable', ['id' => $id, 'page' => 'notes'], 404);
        }

        $v = new Validator($request->getParsedBody());
        $texte = $v->longText('texte', max: Carnet::LONGUEUR_MAX);
        $couleur = $v->choice('couleur', Carnet::COULEURS, required: false) ?: $note['couleur'];
        // Une case à cocher non cochée n'est pas envoyée du tout.
        $importante = $v->checkbox('importante');

        if ($v->fails()) {
            return $this->pageNote($note, $v->input()['texte'] ?? '', $couleur, $importante, implode(' ', $v->errors()), 422);
        }

        $this->carnet->modifier($id, $this->auteur(), $texte, $couleur, $importante);
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

    private function pageListe(
        ServerRequestInterface $request,
        string $saisie = '',
        string $couleur = Carnet::COULEURS[0],
        ?string $erreur = null,
        int $statut = 200,
    ): ResponseInterface {
        // Ce qui suit le « ? » dans l'adresse : /notes?q=pain&filtre=importantes
        // Comme tout ce qui vient d'une requête, c'est vérifié avant de servir.
        $parametres = $request->getQueryParams();
        $recherche = is_string($parametres['q'] ?? null) ? mb_substr(trim($parametres['q']), 0, self::RECHERCHE_MAX) : '';
        $importantesSeules = ($parametres['filtre'] ?? null) === 'importantes';

        $toutes = $this->carnet->de($this->auteur());
        // Sans recherche ni filtre, les notes à afficher sont toutes les notes :
        // inutile de les redemander à la base. (C'est la barre de débogage qui
        // a montré la même requête lancée deux fois.)
        $notes = $recherche === '' && !$importantesSeules
            ? $toutes
            : $this->carnet->de($this->auteur(), $recherche, $importantesSeules);

        return $this->kioo->page('notes/liste', [
            // Pour la mise en page : le lien « Mes notes » du bandeau est marqué.
            'page' => 'notes',
            'notes' => $notes,
            'total' => count($toutes),
            'importantes' => count(array_filter($toutes, static fn(array $note): bool => $note['importante'])),
            'recherche' => $recherche,
            'filtre' => $importantesSeules ? 'importantes' : 'toutes',
            // Les adresses des filtres sont construites ici : http_build_query()
            // encode la recherche pour qu'elle tienne dans une adresse.
            'lien_toutes' => '/notes' . ($recherche !== '' ? '?' . http_build_query(['q' => $recherche]) : ''),
            'lien_importantes' => '/notes?' . http_build_query(['filtre' => 'importantes', ...($recherche !== '' ? ['q' => $recherche] : [])]),
            'couleurs' => Carnet::COULEURS,
            'saisie' => $saisie,
            'saisie_couleur' => $couleur,
            'erreur' => $erreur,
            // Le script de la page envoie ce jeton dans l'en-tête X-CSRF-Token.
            'jeton' => $this->jeton->value(),
            // L'adresse du visiteur, telle que Wazi l'a établie (ADR-022).
            'adresse' => $request->getAttribute('client_ip'),
            'longueur_max' => Carnet::LONGUEUR_MAX,
        ], $statut);
    }

    /**
     * @param array{id: int, auteur: string, texte: string, couleur: string, importante: bool, creee: \DateTimeImmutable} $note
     */
    private function pageNote(array $note, string $saisie, string $couleur, bool $importante, ?string $erreur = null, int $statut = 200): ResponseInterface
    {
        return $this->kioo->page('notes/note', [
            'page' => 'notes',
            'note' => $note,
            'couleurs' => Carnet::COULEURS,
            'saisie' => $saisie,
            'saisie_couleur' => $couleur,
            'saisie_importante' => $importante,
            'erreur' => $erreur,
            'longueur_max' => Carnet::LONGUEUR_MAX,
        ], $statut);
    }

    private function auteur(): string
    {
        // ConnexionRequise garantit qu'un visiteur est connecté.
        return $this->visiteur->nom() ?? throw new \LogicException('Cette route doit porter le middleware ConnexionRequise.');
    }

    /**
     * @param array<string, mixed> $donnees
     */
    private static function json(array $donnees, int $statut = 200): ResponseInterface
    {
        return new Response($statut, ['Content-Type' => 'application/json'], json_encode($donnees, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
