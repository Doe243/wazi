<?php

declare(strict_types=1);

namespace Wazi\Http;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Wazi\Http\Exception\InvalidUploadedFileException;
use Wazi\Http\Exception\UploadedFileException;

/**
 * Un fichier envoyé par un formulaire (<input type="file">), conforme à PSR-7.
 *
 * Quand un navigateur envoie un fichier, PHP le range dans un emplacement
 * temporaire et le supprime à la fin de la requête. Pour le garder, il faut
 * le déplacer :
 *
 *     $avatar = $request->getUploadedFiles()['avatar'];
 *
 *     if ($avatar->getError() === UPLOAD_ERR_OK) {
 *         $avatar->moveTo(__DIR__ . '/../storage/avatars/' . $utilisateur->id . '.png');
 *     }
 *
 * Un fichier envoyé n'est pas immuable : une fois déplacé, il ne peut plus
 * être ni lu ni déplacé à nouveau.
 *
 * Sécurité (ADR-006) :
 *   - getClientFilename() et getClientMediaType() retournent ce que le
 *     navigateur a DÉCLARÉ. Un attaquant y écrit ce qu'il veut : un nom comme
 *     « ../../public/index.php », un type « image/png » pour un script PHP.
 *     Ne construisez JAMAIS le chemin de destination avec le nom du client :
 *     choisissez vous-même le nom et l'extension, comme dans l'exemple ;
 *   - un chemin qui commence par un protocole (phar://, php://, http://...)
 *     est refusé, comme source et comme destination ;
 *   - sur un vrai serveur, le déplacement passe par move_uploaded_file(), qui
 *     refuse tout fichier que PHP n'a pas reçu lui-même pendant cette requête.
 */
final class UploadedFile implements UploadedFileInterface
{
    /** Ce que signifie chaque code d'erreur de PHP (les constantes UPLOAD_ERR_*). */
    private const array ERRORS = [
        UPLOAD_ERR_OK => 'aucune erreur.',
        UPLOAD_ERR_INI_SIZE => 'il dépasse la taille maximale acceptée par le serveur'
            . ' (réglage upload_max_filesize du php.ini).',
        UPLOAD_ERR_FORM_SIZE => 'il dépasse la taille maximale annoncée par le formulaire (champ MAX_FILE_SIZE).',
        UPLOAD_ERR_PARTIAL => 'il n\'a été reçu qu\'en partie, l\'envoi a été interrompu.',
        UPLOAD_ERR_NO_FILE => 'aucun fichier n\'a été choisi dans le formulaire.',
        UPLOAD_ERR_NO_TMP_DIR => 'le serveur n\'a pas de dossier temporaire (réglage upload_tmp_dir du php.ini).',
        UPLOAD_ERR_CANT_WRITE => 'le serveur n\'a pas pu l\'écrire sur son disque.',
        UPLOAD_ERR_EXTENSION => 'une extension de PHP a interrompu l\'envoi.',
    ];

    /** Taille des morceaux lus quand le contenu est copié depuis un flux. */
    private const int CHUNK_SIZE = 1_048_576;

    /** Le chemin du fichier temporaire, ou null si le fichier a été donné sous forme de flux. */
    private readonly ?string $file;

    private ?StreamInterface $stream;

    private bool $moved = false;

    /**
     * @param StreamInterface|string $streamOrFile     le contenu, ou le chemin du fichier temporaire ($_FILES : « tmp_name »)
     * @param int|null               $size             la taille en octets, ou null si elle est inconnue
     * @param int                    $error            une des constantes UPLOAD_ERR_* de PHP
     * @param string|null            $clientFilename   le nom annoncé par le navigateur (à ne jamais croire)
     * @param string|null            $clientMediaType  le type annoncé par le navigateur (à ne jamais croire)
     *
     * @throws InvalidUploadedFileException si le code d'erreur, la taille ou le chemin est invalide
     */
    public function __construct(
        StreamInterface|string $streamOrFile,
        private readonly ?int $size = null,
        private readonly int $error = UPLOAD_ERR_OK,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
    ) {
        if (!isset(self::ERRORS[$error])) {
            throw InvalidUploadedFileException::unknownErrorCode($error);
        }

        if ($size !== null && $size < 0) {
            throw InvalidUploadedFileException::negativeSize($size);
        }

        if ($streamOrFile instanceof StreamInterface) {
            $this->file = null;
            $this->stream = $streamOrFile;

            return;
        }

        // Quand l'envoi a échoué, PHP ne donne aucun chemin : il n'y a rien à vérifier.
        if ($error === UPLOAD_ERR_OK) {
            self::assertLocalPath($streamOrFile);
        }

        $this->file = $streamOrFile;
        $this->stream = null;
    }

    // ------------------------------------------------------------------
    // Informations
    // ------------------------------------------------------------------

    public function getSize(): ?int
    {
        return $this->size;
    }

    /**
     * UPLOAD_ERR_OK si l'envoi a réussi, sinon une autre constante UPLOAD_ERR_*.
     */
    public function getError(): int
    {
        return $this->error;
    }

    /**
     * Le nom du fichier sur l'ordinateur du visiteur, tel que le navigateur l'annonce.
     *
     * ⚠ Ne l'utilisez jamais pour construire un chemin : voir la note de sécurité de la classe.
     */
    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    /**
     * Le type du fichier (« image/png »...), tel que le navigateur l'annonce.
     *
     * ⚠ Ce n'est pas une preuve : pour connaître le vrai type, examinez le contenu.
     */
    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    // ------------------------------------------------------------------
    // Lecture et déplacement
    // ------------------------------------------------------------------

    /**
     * Le contenu du fichier, sous forme de flux.
     *
     * @throws UploadedFileException si l'envoi a échoué, si le fichier a déjà été déplacé ou s'il est illisible
     */
    public function getStream(): StreamInterface
    {
        $this->assertUsable();

        return $this->stream ??= $this->openFile();
    }

    /**
     * Déplace le fichier vers son emplacement définitif. À faire une seule fois.
     *
     * @throws InvalidUploadedFileException si la destination n'est pas un chemin de fichier ordinaire
     * @throws UploadedFileException        si l'envoi a échoué, si le fichier a déjà été déplacé ou si le déplacement échoue
     */
    public function moveTo(string $targetPath): void
    {
        $this->assertUsable();
        self::assertLocalPath($targetPath);

        $directory = dirname($targetPath);

        if (!is_dir($directory) || !is_writable($directory) || is_dir($targetPath)) {
            throw UploadedFileException::targetDirectoryUnusable();
        }

        $this->file !== null
            ? $this->moveFile($this->file, $targetPath)
            : $this->copyStream($this->getStream(), $targetPath);

        $this->stream = null;
        $this->moved = true;
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    private function assertUsable(): void
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            // Le constructeur n'accepte que des codes connus ; le « ?? » ne sert qu'à le garantir.
            throw UploadedFileException::uploadFailed(self::ERRORS[$this->error] ?? 'la raison est inconnue.');
        }

        if ($this->moved) {
            throw UploadedFileException::alreadyMoved();
        }
    }

    private function openFile(): StreamInterface
    {
        if ($this->file === null || !is_file($this->file) || !is_readable($this->file)) {
            throw UploadedFileException::sourceUnreadable();
        }

        $resource = fopen($this->file, 'rb');

        return $resource !== false ? new Stream($resource) : throw UploadedFileException::sourceUnreadable();
    }

    private function moveFile(string $file, string $targetPath): void
    {
        if (!is_file($file)) {
            throw UploadedFileException::sourceUnreadable();
        }

        // Un fichier encore ouvert ne peut pas être déplacé sous Windows.
        $this->stream?->close();

        // En ligne de commande (les tests, par exemple), aucun fichier n'est
        // reçu par HTTP : move_uploaded_file() refuserait tout. Partout
        // ailleurs, c'est lui qui garantit que le fichier vient bien d'un envoi.
        $moved = in_array(PHP_SAPI, ['cli', 'phpdbg'], true)
            ? rename($file, $targetPath)
            : move_uploaded_file($file, $targetPath);

        if (!$moved) {
            throw UploadedFileException::moveFailed();
        }
    }

    /**
     * Copie le flux morceau par morceau, pour ne jamais charger un gros
     * fichier en entier dans la mémoire.
     */
    private function copyStream(StreamInterface $source, string $targetPath): void
    {
        $target = fopen($targetPath, 'wb');

        if ($target === false) {
            throw UploadedFileException::moveFailed();
        }

        try {
            if ($source->isSeekable()) {
                $source->rewind();
            }

            while (!$source->eof()) {
                if (fwrite($target, $source->read(self::CHUNK_SIZE)) === false) {
                    throw UploadedFileException::moveFailed();
                }
            }
        } finally {
            fclose($target);
        }

        $source->close();
    }

    private static function assertLocalPath(string $path): void
    {
        if ($path === '') {
            throw InvalidUploadedFileException::emptyPath();
        }

        if (!LocalPath::isPlain($path)) {
            throw InvalidUploadedFileException::notALocalPath();
        }
    }
}
