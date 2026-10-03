<?php

declare(strict_types=1);

namespace Wazi\Middleware;

use Wazi\Http\Session;
use Wazi\Middleware\Exception\SessionStoreException;

/**
 * Conserve les sessions dans des fichiers : un fichier par visiteur, nommé
 * d'après son identifiant de session.
 *
 *     var/sessions/3f9a…e21.json   →   {"user_id": 42, "panier": [3, 7]}
 *
 * Une session qui n'a pas servi depuis $lifetime secondes est considérée comme
 * expirée : elle n'est plus lue, et son fichier finit par être supprimé.
 *
 * Sécurité (ADR-006 et ADR-021) :
 *   - le contenu est écrit en JSON et relu avec json_decode() : jamais
 *     unserialize(), qui peut reconstruire des objets et exécuter du code ;
 *   - l'identifiant n'est utilisé comme nom de fichier que s'il a exactement la
 *     forme attendue (64 chiffres hexadécimaux) ;
 *   - un dossier situé dans le dossier public du site est refusé : les
 *     sessions y seraient téléchargeables ;
 *   - les fichiers ne sont lisibles que par le compte qui fait tourner PHP.
 */
final readonly class FileSessionStore implements SessionStore
{
    /** Une fois sur cent, une écriture fait aussi le ménage des sessions expirées. */
    private const int CLEANUP_CHANCE = 100;

    /**
     * @param string $directory le dossier où ranger les sessions, hors du dossier public
     * @param int    $lifetime  durée de vie d'une session inutilisée, en secondes (2 heures par défaut)
     */
    public function __construct(private string $directory, private int $lifetime = 7200) {}

    public function read(string $id): ?array
    {
        $file = $this->file($id);

        if ($file === null || !is_file($file)) {
            return null;
        }

        if (filemtime($file) < time() - $this->lifetime) {
            unlink($file);

            return null;
        }

        $content = file_get_contents($file);
        $data = $content === false ? null : json_decode($content, true);

        // Un fichier illisible ou abîmé vaut une session absente.
        if (!is_array($data)) {
            return null;
        }

        $session = [];

        foreach ($data as $key => $value) {
            $session[(string) $key] = $value;
        }

        return $session;
    }

    public function write(string $id, array $data): void
    {
        $file = $this->file($id);

        if ($file === null) {
            return;
        }

        $this->prepareDirectory();

        // On écrit d'abord un fichier provisoire, puis on le renomme : une
        // autre requête ne peut jamais lire un fichier à moitié écrit.
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            throw SessionStoreException::notWritable();
        }

        chmod($temporary, 0o600);
        rename($temporary, $file);

        if (random_int(1, self::CLEANUP_CHANCE) === 1) {
            $this->removeExpired();
        }
    }

    public function delete(string $id): void
    {
        $file = $this->file($id);

        if ($file !== null && is_file($file)) {
            unlink($file);
        }
    }

    /**
     * Supprime les fichiers des sessions expirées.
     */
    public function removeExpired(): void
    {
        $limit = time() - $this->lifetime;

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (filemtime($file) < $limit) {
                unlink($file);
            }
        }
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * Le fichier d'une session, ou null si l'identifiant n'en est pas un.
     */
    private function file(string $id): ?string
    {
        return Session::isValidId($id) ? $this->directory . DIRECTORY_SEPARATOR . $id . '.json' : null;
    }

    private function prepareDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            throw SessionStoreException::notWritable();
        }

        if (!is_writable($this->directory)) {
            throw SessionStoreException::notWritable();
        }

        if ($this->isInsideDocumentRoot()) {
            throw SessionStoreException::publiclyAccessible();
        }
    }

    /**
     * Vrai si le dossier se trouve dans celui que le serveur web distribue.
     */
    private function isInsideDocumentRoot(): bool
    {
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';

        if (!is_string($documentRoot) || $documentRoot === '') {
            return false;
        }

        // realpath() donne le chemin réel, sans « .. » ni lien symbolique.
        $realDirectory = realpath($this->directory);
        $realRoot = realpath($documentRoot);

        if ($realDirectory === false || $realRoot === false) {
            return false;
        }

        $realRoot = rtrim($realRoot, '/\\');

        return $realDirectory === $realRoot || str_starts_with($realDirectory, $realRoot . DIRECTORY_SEPARATOR);
    }
}
