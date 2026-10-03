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
 * expirée : elle n'est plus lue, et son fichier finit par être supprimé. La
 * date de modification du fichier EST sa date d'expiration : elle est placée
 * dans le futur à chaque écriture.
 *
 * Le verrou (ADR-024). Deux requêtes du même visiteur peuvent arriver en même
 * temps (deux onglets, une page qui envoie des requêtes JavaScript). Sans
 * précaution, chacune lit la session, la modifie, et la réécrit en entier : la
 * dernière efface ce que l'autre a noté. Ici, la première requête réserve la
 * session (un fichier « .lock » à côté d'elle) ; la seconde attend son tour.
 *
 * Sécurité (ADR-006, ADR-021 et ADR-024) :
 *   - le contenu est écrit en JSON et relu avec json_decode() : jamais
 *     unserialize(), qui peut reconstruire des objets et exécuter du code ;
 *   - l'identifiant n'est utilisé comme nom de fichier que s'il a exactement la
 *     forme attendue (64 chiffres hexadécimaux) ;
 *   - un dossier situé dans le dossier public du site est refusé : les
 *     sessions y seraient téléchargeables ;
 *   - les fichiers ne sont lisibles que par le compte qui fait tourner PHP ;
 *   - un verrou n'est posé que pour une session qui existe : envoyer des
 *     identifiants inventés ne crée aucun fichier.
 */
final class FileSessionStore implements SessionStore
{
    /** Une fois sur cent, une écriture fait aussi le ménage des sessions expirées. */
    private const int CLEANUP_CHANCE = 100;

    /** Le temps d'attente entre deux essais pour réserver une session, en microsecondes. */
    private const int LOCK_RETRY_DELAY = 10_000;

    /**
     * Les verrous tenus par cette requête, par identifiant de session.
     *
     * @var array<string, resource>
     */
    private array $locks = [];

    /**
     * @param string $directory   le dossier où ranger les sessions, hors du dossier public
     * @param int    $lifetime    durée de vie d'une session inutilisée, en secondes (2 heures par défaut)
     * @param float  $lockTimeout temps d'attente maximal d'une session réservée par une autre requête, en secondes
     */
    public function __construct(
        private readonly string $directory,
        private readonly int $lifetime = 7200,
        private readonly float $lockTimeout = 10.0,
    ) {}

    public function lock(string $id): void
    {
        $file = $this->file($id);

        // Sécurité : pas de verrou pour une session qui n'existe pas. Sinon,
        // chaque identifiant inventé par un robot créerait un fichier.
        if ($file === null || isset($this->locks[$id]) || !is_file($file)) {
            return;
        }

        $lockFile = self::lockFile($file);
        $handle = fopen($lockFile, 'c');

        if ($handle === false) {
            throw SessionStoreException::notWritable();
        }

        chmod($lockFile, 0o600);
        $deadline = microtime(true) + $this->lockTimeout;

        // flock() sans attente, réessayé : on peut ainsi renoncer au bout d'un
        // moment, au lieu de rester bloqué derrière une requête qui ne finit pas.
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);

                throw SessionStoreException::lockTimeout($this->lockTimeout);
            }

            usleep(self::LOCK_RETRY_DELAY);
        }

        $this->locks[$id] = $handle;
    }

    public function unlock(string $id): void
    {
        $handle = $this->locks[$id] ?? null;

        if ($handle === null) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
        unset($this->locks[$id]);
    }

    public function read(string $id): ?array
    {
        $file = $this->file($id);

        if ($file === null || !is_file($file)) {
            return null;
        }

        // La date du fichier est sa date d'expiration.
        if (filemtime($file) < time()) {
            $this->delete($id);

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

    public function write(string $id, array $data, ?int $lifetime = null): void
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

        if (!rename($temporary, $file)) {
            unlink($temporary);

            throw SessionStoreException::notWritable();
        }

        // Chaque écriture repousse l'expiration.
        touch($file, time() + ($lifetime ?? $this->lifetime));

        if (random_int(1, self::CLEANUP_CHANCE) === 1) {
            $this->removeExpired();
        }
    }

    public function delete(string $id): void
    {
        $file = $this->file($id);

        if ($file === null) {
            return;
        }

        // Un fichier ouvert ne se supprime pas sous Windows : on libère d'abord.
        $this->unlock($id);

        if (is_file($file)) {
            unlink($file);
        }

        self::removeLockFile(self::lockFile($file));
    }

    /**
     * Supprime les fichiers des sessions expirées, et les verrous qui ne servent plus.
     */
    public function removeExpired(): void
    {
        $now = time();

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (filemtime($file) < $now) {
                unlink($file);
            }
        }

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.json.lock') ?: [] as $lockFile) {
            if (!is_file(substr($lockFile, 0, -strlen('.lock')))) {
                self::removeLockFile($lockFile);
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

    private static function lockFile(string $file): string
    {
        return $file . '.lock';
    }

    /**
     * Supprime un fichier de verrou, sauf si une autre requête le tient.
     */
    private static function removeLockFile(string $lockFile): void
    {
        if (!is_file($lockFile)) {
            return;
        }

        $handle = fopen($lockFile, 'c');

        if ($handle === false) {
            return;
        }

        $isFree = flock($handle, LOCK_EX | LOCK_NB);
        fclose($handle);

        if ($isFree) {
            unlink($lockFile);
        }
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
