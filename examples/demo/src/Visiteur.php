<?php

declare(strict_types=1);

namespace Demo;

use Wazi\Http\Session;

/**
 * Ce que l'application sait du visiteur de la requête en cours : est-il
 * connecté, et y a-t-il un message à lui afficher ?
 *
 * Toutes les pages en ont besoin (le bandeau affiche son nom, la mise en
 * page affiche les messages). Plutôt que de le répéter dans chaque
 * contrôleur, public/index.php le partage une fois avec tous les templates :
 *
 *     $kioo->share('utilisateur', fn () => $visiteur->nom());
 */
final readonly class Visiteur
{
    public function __construct(private Session $session) {}

    /**
     * Le nom du visiteur connecté, ou null.
     */
    public function nom(): ?string
    {
        $nom = $this->session->get('utilisateur');

        // Ce qu'on relit d'une session est de type inconnu : on le vérifie.
        return is_string($nom) ? $nom : null;
    }

    /**
     * Les messages laissés par la requête précédente (voir Session::flash()).
     * takeFlash() les efface en les lisant : ils ne s'affichent qu'une fois.
     *
     * @return list<array{type: string, texte: string}>
     */
    public function messages(): array
    {
        $messages = [];

        foreach (['succes', 'erreur'] as $type) {
            $texte = $this->session->takeFlash($type);

            if (is_string($texte)) {
                $messages[] = ['type' => $type, 'texte' => $texte];
            }
        }

        return $messages;
    }
}
