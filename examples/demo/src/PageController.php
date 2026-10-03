<?php

declare(strict_types=1);

namespace Demo;

use Psr\Http\Message\ResponseInterface;
use Wazi\Routing\Attribute\Get;

/**
 * Les pages que tout le monde peut voir.
 */
final readonly class PageController
{
    public function __construct(private Pages $pages) {}

    #[Get('/')]
    public function accueil(): ResponseInterface
    {
        return $this->pages->page('accueil');
    }

    /**
     * Une page qui tombe en panne, exprès : pour voir ce que Wazi affiche.
     *
     * En développement (APP_DEBUG=true dans .env), le navigateur montre le
     * message, le fichier et la ligne. En production, une page neutre et un
     * identifiant : le détail est dans le journal, pas sous les yeux du visiteur.
     */
    #[Get('/panne')]
    public function panne(): ResponseInterface
    {
        throw new \RuntimeException(
            'Cette panne est volontaire : la démonstration montre ce que Wazi affiche quand votre code lève une exception.',
        );
    }
}
