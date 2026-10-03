<?php

declare(strict_types=1);

namespace Demo;

use Psr\Http\Message\ResponseInterface;
use Wazi\Http\Session;
use Wazi\View\Kioo;

/**
 * Fabrique les pages du site.
 *
 * Toutes les pages partagent la même mise en page (views/base.kioo), qui a
 * besoin de savoir qui est connecté et s'il y a un message à afficher. Plutôt
 * que de le répéter dans chaque contrôleur, on le fait ici, une fois.
 *
 * Kioo est strict : une variable absente est une erreur, pas un vide. C'est
 * donc ici que l'on garantit que « utilisateur », « messages » et « annee »
 * existent toujours.
 */
final readonly class Pages
{
    public function __construct(private Kioo $kioo, private Session $session) {}

    /**
     * @param array<string, mixed> $variables ce que la vue affiche
     */
    public function page(string $vue, array $variables = [], int $statut = 200): ResponseInterface
    {
        return $this->kioo->page($vue, [
            ...$variables,
            'utilisateur' => $this->utilisateur(),
            'messages' => $this->messages(),
            'annee' => (int) date('Y'),
        ], $statut);
    }

    /**
     * Le nom du visiteur connecté, ou null.
     */
    public function utilisateur(): ?string
    {
        $nom = $this->session->get('utilisateur');

        return is_string($nom) ? $nom : null;
    }

    /**
     * Les messages laissés par la requête précédente (voir Session::flash()).
     * takeFlash() les efface en les lisant : ils ne s'affichent qu'une fois.
     *
     * @return list<array{type: string, texte: string}>
     */
    private function messages(): array
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
