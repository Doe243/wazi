<?php

declare(strict_types=1);

namespace Wazi\Middleware;

/**
 * L'endroit où le contenu des sessions est conservé entre deux requêtes.
 *
 * Une interface plutôt qu'une classe, pour pouvoir un jour ranger les
 * sessions ailleurs que dans des fichiers (une base de données, par exemple)
 * sans toucher au middleware.
 */
interface SessionStore
{
    /**
     * Réserve une session existante à la requête en cours : une autre requête
     * du même visiteur attend qu'elle soit libérée. Sans effet si la session
     * n'existe pas.
     */
    public function lock(string $id): void;

    /**
     * Libère la session réservée par lock(). Sans effet si elle ne l'était pas.
     */
    public function unlock(string $id): void;

    /**
     * @return array<string, mixed>|null le contenu de la session, ou null si elle n'existe pas ou a expiré
     */
    public function read(string $id): ?array;

    /**
     * @param array<string, mixed> $data
     * @param int|null             $lifetime durée de vie propre à cette session, en secondes ; null pour la durée ordinaire
     */
    public function write(string $id, array $data, ?int $lifetime = null): void;

    /**
     * Supprime la session, et la libère si elle était réservée.
     */
    public function delete(string $id): void;
}
