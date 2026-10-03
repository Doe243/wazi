<?php

declare(strict_types=1);

namespace Wazi\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Wazi\Http\Exception\InvalidUploadedFileException;
use Wazi\Http\Exception\UploadedFileException;
use Wazi\Http\Stream;
use Wazi\Http\UploadedFile;

final class UploadedFileTest extends TestCase
{
    /** Un dossier temporaire propre à chaque test, supprimé ensuite. */
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wazi-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->directory) ?: [] as $entry) {
            $path = $this->directory . DIRECTORY_SEPARATOR . $entry;

            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($this->directory);
    }

    // --- Informations ------------------------------------------------------

    public function testItImplementsPsr7(): void
    {
        self::assertInstanceOf(UploadedFileInterface::class, new UploadedFile(Stream::fromString()));
    }

    public function testItExposesWhatTheBrowserDeclared(): void
    {
        $file = new UploadedFile($this->temporaryFile('contenu'), 7, UPLOAD_ERR_OK, 'photo.png', 'image/png');

        self::assertSame(7, $file->getSize());
        self::assertSame(UPLOAD_ERR_OK, $file->getError());
        self::assertSame('photo.png', $file->getClientFilename());
        self::assertSame('image/png', $file->getClientMediaType());
    }

    public function testEverythingOptionalIsNullByDefault(): void
    {
        $file = new UploadedFile(Stream::fromString());

        self::assertNull($file->getSize());
        self::assertNull($file->getClientFilename());
        self::assertNull($file->getClientMediaType());
        self::assertSame(UPLOAD_ERR_OK, $file->getError());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unknownErrorCodes(): iterable
    {
        yield 'négatif' => [-1];
        yield 'le 5 n\'existe pas' => [5];
        yield 'trop grand' => [9];
    }

    #[DataProvider('unknownErrorCodes')]
    public function testItRejectsAnUnknownErrorCode(int $error): void
    {
        $this->expectException(InvalidUploadedFileException::class);

        new UploadedFile(Stream::fromString(), null, $error);
    }

    public function testItRejectsANegativeSize(): void
    {
        $this->expectException(InvalidUploadedFileException::class);

        new UploadedFile(Stream::fromString(), -1);
    }

    // --- Lecture -----------------------------------------------------------

    public function testGetStreamReadsTheTemporaryFile(): void
    {
        $file = new UploadedFile($this->temporaryFile('contenu du fichier'));

        self::assertSame('contenu du fichier', (string) $file->getStream());

        $file->getStream()->close();
    }

    public function testGetStreamReturnsTheGivenStream(): void
    {
        $stream = Stream::fromString('contenu');

        self::assertSame($stream, new UploadedFile($stream)->getStream());
    }

    public function testGetStreamFailsWhenTheTemporaryFileIsGone(): void
    {
        $file = new UploadedFile($this->directory . '/disparu.tmp');

        $this->expectException(UploadedFileException::class);

        $file->getStream();
    }

    // --- Déplacement -------------------------------------------------------

    public function testMoveToMovesTheTemporaryFile(): void
    {
        $source = $this->temporaryFile('contenu');
        $target = $this->directory . '/avatar.png';

        new UploadedFile($source)->moveTo($target);

        self::assertStringEqualsFile($target, 'contenu');
        self::assertFileDoesNotExist($source);
    }

    public function testMoveToWorksAfterTheStreamWasRead(): void
    {
        $file = new UploadedFile($this->temporaryFile('contenu'));
        $target = $this->directory . '/avatar.png';

        $file->getStream()->read(3);
        $file->moveTo($target);

        self::assertStringEqualsFile($target, 'contenu');
    }

    public function testMoveToCopiesAStreamFromItsBeginning(): void
    {
        $stream = Stream::fromString('contenu du flux');
        $stream->read(5);
        $target = $this->directory . '/copie.txt';

        new UploadedFile($stream)->moveTo($target);

        self::assertStringEqualsFile($target, 'contenu du flux');
    }

    public function testAFileCannotBeMovedTwice(): void
    {
        $file = new UploadedFile($this->temporaryFile('contenu'));
        $file->moveTo($this->directory . '/premier.txt');

        $this->expectException(UploadedFileException::class);

        $file->moveTo($this->directory . '/second.txt');
    }

    public function testAMovedFileCannotBeReadAnymore(): void
    {
        $file = new UploadedFile(Stream::fromString('contenu'));
        $file->moveTo($this->directory . '/fichier.txt');

        $this->expectException(UploadedFileException::class);

        $file->getStream();
    }

    public function testMoveToFailsWhenTheTargetDirectoryDoesNotExist(): void
    {
        $file = new UploadedFile($this->temporaryFile('contenu'));

        $this->expectException(UploadedFileException::class);

        $file->moveTo($this->directory . '/dossier-absent/fichier.txt');
    }

    public function testMoveToFailsWhenTheTargetIsADirectory(): void
    {
        $file = new UploadedFile($this->temporaryFile('contenu'));

        $this->expectException(UploadedFileException::class);

        $file->moveTo($this->directory);
    }

    public function testAFailedMoveLeavesTheFileUsable(): void
    {
        $source = $this->temporaryFile('contenu');
        $file = new UploadedFile($source);

        try {
            $file->moveTo($this->directory . '/dossier-absent/fichier.txt');
            self::fail('Une exception était attendue.');
        } catch (UploadedFileException) {
            $file->moveTo($this->directory . '/fichier.txt');
        }

        self::assertStringEqualsFile($this->directory . '/fichier.txt', 'contenu');
    }

    public function testTheMoveExceptionIsARuntimeExceptionAsPsr7Requires(): void
    {
        $file = new UploadedFile(Stream::fromString('contenu'));
        $file->moveTo($this->directory . '/fichier.txt');

        $this->expectException(\RuntimeException::class);

        $file->moveTo($this->directory . '/encore.txt');
    }

    // --- Envoi échoué ------------------------------------------------------

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function failedUploads(): iterable
    {
        yield 'trop gros pour le serveur' => [UPLOAD_ERR_INI_SIZE, 'upload_max_filesize'];
        yield 'trop gros pour le formulaire' => [UPLOAD_ERR_FORM_SIZE, 'MAX_FILE_SIZE'];
        yield 'reçu en partie' => [UPLOAD_ERR_PARTIAL, 'en partie'];
        yield 'aucun fichier' => [UPLOAD_ERR_NO_FILE, 'aucun fichier'];
        yield 'pas de dossier temporaire' => [UPLOAD_ERR_NO_TMP_DIR, 'upload_tmp_dir'];
        yield 'écriture impossible' => [UPLOAD_ERR_CANT_WRITE, 'disque'];
        yield 'extension' => [UPLOAD_ERR_EXTENSION, 'extension'];
    }

    #[DataProvider('failedUploads')]
    public function testAFailedUploadCannotBeReadAndTheMessageSaysWhy(int $error, string $expectedHint): void
    {
        // Quand l'envoi échoue, PHP donne un chemin vide dans $_FILES.
        $file = new UploadedFile('', 0, $error);

        self::assertSame($error, $file->getError());

        try {
            $file->getStream();
            self::fail('Une exception était attendue.');
        } catch (UploadedFileException $exception) {
            self::assertStringContainsString($expectedHint, $exception->getMessage());
            self::assertStringContainsString('getError()', $exception->getMessage());
        }
    }

    #[DataProvider('failedUploads')]
    public function testAFailedUploadCannotBeMovedAndTheMessageSaysWhy(int $error, string $expectedHint): void
    {
        $file = new UploadedFile('', 0, $error);

        $this->expectException(UploadedFileException::class);
        $this->expectExceptionMessage($expectedHint);

        $file->moveTo($this->directory . '/fichier.txt');
    }

    // --- Sécurité ----------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsThatAreNotPlainFiles(): iterable
    {
        yield 'archive phar' => ['phar://piege.phar/fichier.txt'];
        yield 'flux php' => ['php://filter/resource=/etc/passwd'];
        yield 'adresse distante' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'ftp' => ['ftp://exemple.com/fichier.txt'];
        yield 'protocole en majuscules' => ['PHAR://piege.phar/fichier.txt'];
        yield 'octet nul' => ["/tmp/fichier.png\0.php"];
        yield 'retour à la ligne' => ["/tmp/fichier\n.png"];
        yield 'vide' => [''];
    }

    #[DataProvider('pathsThatAreNotPlainFiles')]
    public function testItRejectsASourceThatIsNotAPlainFilePath(string $path): void
    {
        $this->expectException(InvalidUploadedFileException::class);

        new UploadedFile($path);
    }

    #[DataProvider('pathsThatAreNotPlainFiles')]
    public function testMoveToRejectsATargetThatIsNotAPlainFilePath(string $path): void
    {
        $file = new UploadedFile(Stream::fromString('contenu'));

        $this->expectException(InvalidUploadedFileException::class);

        $file->moveTo($path);
    }

    public function testAWindowsDriveLetterIsNotMistakenForAProtocol(): void
    {
        $file = new UploadedFile('C:/dossier/absent.tmp');

        // Le chemin est accepté ; c'est seulement la lecture qui échoue, car le fichier n'existe pas.
        $this->expectException(UploadedFileException::class);

        $file->getStream();
    }

    /**
     * Le nom donné par le navigateur est retourné tel quel (PSR-7 l'exige),
     * mais la classe ne s'en sert jamais pour choisir où écrire.
     */
    public function testTheClientFilenameNeverDecidesWhereTheFileGoes(): void
    {
        $file = new UploadedFile($this->temporaryFile('contenu'), 7, UPLOAD_ERR_OK, '../../public/index.php', 'image/png');
        $target = $this->directory . '/avatar.png';

        $file->moveTo($target);

        self::assertSame('../../public/index.php', $file->getClientFilename());
        self::assertStringEqualsFile($target, 'contenu');
        self::assertSame(['avatar.png'], array_values(array_diff(scandir($this->directory) ?: [], ['.', '..'])));
    }

    public function testErrorMessagesNeverRevealPathsOrTheClientFilename(): void
    {
        $source = $this->directory . '/secret-disparu.tmp';
        $file = new UploadedFile($source, null, UPLOAD_ERR_OK, 'nom-du-client.png');
        $messages = [];

        foreach (['/dossier-absent/cible.txt', 'phar://cible-secrete.phar/x'] as $target) {
            try {
                $file->moveTo($this->directory . $target);
            } catch (UploadedFileException|InvalidUploadedFileException $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        try {
            $file->getStream();
        } catch (UploadedFileException $exception) {
            $messages[] = $exception->getMessage();
        }

        self::assertCount(3, $messages);

        foreach ($messages as $message) {
            self::assertStringNotContainsString('secret', $message);
            self::assertStringNotContainsString('nom-du-client', $message);
            self::assertStringNotContainsString($this->directory, $message);
        }
    }

    // --- Outils ------------------------------------------------------------

    private function temporaryFile(string $content): string
    {
        $file = $this->directory . DIRECTORY_SEPARATOR . 'envoi-' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($file, $content);

        return $file;
    }
}
