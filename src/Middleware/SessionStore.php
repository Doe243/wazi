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
     * @return array<string, mixed>|null le contenu de la session, ou null si elle n'existe pas ou a expiré
     */
    public function read(string $id): ?array;

    /**
     * @param array<string, mixed> $data
     */
    public function write(string $id, array $data): void;

    public function delete(string $id): void;
}
