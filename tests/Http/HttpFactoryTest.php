<?php

declare(strict_types=1);

namespace Wazi\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Wazi\Http\Exception\InvalidStreamException;
use Wazi\Http\Exception\InvalidUriException;
use Wazi\Http\Exception\StreamException;
use Wazi\Http\HttpFactory;
use Wazi\Http\Stream;
use Wazi\Http\Uri;

final class HttpFactoryTest extends TestCase
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

    public function testItImplementsTheSixPsr17Interfaces(): void
    {
        $factory = new HttpFactory();

        self::assertInstanceOf(RequestFactoryInterface::class, $factory);
        self::assertInstanceOf(ResponseFactoryInterface::class, $factory);
        self::assertInstanceOf(ServerRequestFactoryInterface::class, $factory);
        self::assertInstanceOf(StreamFactoryInterface::class, $factory);
        self::assertInstanceOf(UploadedFileFactoryInterface::class, $factory);
        self::assertInstanceOf(UriFactoryInterface::class, $factory);
    }

    // --- Messages et URI ---------------------------------------------------

    public function testCreateUri(): void
    {
        self::assertSame('https://exemple.com/', (string) new HttpFactory()->createUri('https://exemple.com/'));
        self::assertSame('', (string) new HttpFactory()->createUri());
    }

    public function testCreateRequestAcceptsATextOrAUriObject(): void
    {
        $factory = new HttpFactory();
        $uri = new Uri('https://exemple.com/');

        self::assertSame('https://exemple.com/a', (string) $factory->createRequest('GET', 'https://exemple.com/a')->getUri());
        self::assertSame($uri, $factory->createRequest('POST', $uri)->getUri());
        self::assertSame('POST', $factory->createRequest('POST', $uri)->getMethod());
    }

    public function testCreateRequestRejectsAnythingElseAsUri(): void
    {
        $this->expectException(InvalidUriException::class);

        self::callUnchecked(new HttpFactory(), 'createRequest', 'GET', 42);
    }

    public function testCreateServerRequestNeverReadsThePhpGlobals(): void
    {
        $previous = $_GET;
        $_GET = ['page' => '2'];

        try {
            $request = new HttpFactory()->createServerRequest('GET', '/articles', ['REMOTE_ADDR' => '127.0.0.1']);
        } finally {
            $_GET = $previous;
        }

        self::assertSame('/articles', (string) $request->getUri());
        self::assertSame(['REMOTE_ADDR' => '127.0.0.1'], $request->getServerParams());
        self::assertSame([], $request->getQueryParams());
    }

    public function testCreateResponse(): void
    {
        $factory = new HttpFactory();

        self::assertSame(200, $factory->createResponse()->getStatusCode());
        self::assertSame('Not Found', $factory->createResponse(404)->getReasonPhrase());
        self::assertSame('Introuvable', $factory->createResponse(404, 'Introuvable')->getReasonPhrase());
    }

    // --- Flux --------------------------------------------------------------

    public function testCreateStream(): void
    {
        self::assertSame('Bonjour', (string) new HttpFactory()->createStream('Bonjour'));
    }

    public function testCreateStreamFromResource(): void
    {
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        fwrite($resource, 'Bonjour');

        self::assertSame('Bonjour', (string) new HttpFactory()->createStreamFromResource($resource));
    }

    public function testCreateStreamFromResourceRejectsAnAddress(): void
    {
        $this->expectException(InvalidStreamException::class);

        self::callUnchecked(new HttpFactory(), 'createStreamFromResource', 'php://input');
    }

    public function testCreateStreamFromFileReadsAFile(): void
    {
        $file = $this->file('contenu');

        $stream = new HttpFactory()->createStreamFromFile($file);

        self::assertSame('contenu', (string) $stream);
        self::assertFalse($stream->isWritable());

        $stream->close();
    }

    public function testCreateStreamFromFileCanCreateAFile(): void
    {
        $file = $this->directory . '/nouveau.txt';

        $stream = new HttpFactory()->createStreamFromFile($file, 'w');
        $stream->write('écrit');
        $stream->close();

        self::assertStringEqualsFile($file, 'écrit');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidModes(): iterable
    {
        yield 'vide' => [''];
        yield 'mot' => ['lecture'];
        yield 'lettre inconnue' => ['z'];
        yield 'injection' => ["r\n"];
        yield 'deux plus' => ['r++'];
    }

    #[DataProvider('invalidModes')]
    public function testCreateStreamFromFileRejectsAnInvalidMode(string $mode): void
    {
        $file = $this->file('contenu');

        $this->expectException(InvalidStreamException::class);

        new HttpFactory()->createStreamFromFile($file, $mode);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validModes(): iterable
    {
        foreach (['r', 'rb', 'r+', 'rb+', 'r+b', 'w', 'wb', 'w+', 'a', 'a+', 'c', 'c+', 'ct'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('validModes')]
    public function testCreateStreamFromFileAcceptsTheFopenModes(string $mode): void
    {
        $stream = new HttpFactory()->createStreamFromFile($this->file('contenu'), $mode);

        self::assertInstanceOf(Stream::class, $stream);

        $stream->close();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function filesThatCannotBeOpened(): iterable
    {
        yield 'lecture d\'un fichier absent' => ['absent.txt', 'r'];
        yield 'écriture dans un dossier absent' => ['dossier-absent/fichier.txt', 'w'];
        yield 'création exclusive d\'un fichier existant' => ['existant.txt', 'x'];
        yield 'un dossier' => ['', 'r'];
    }

    #[DataProvider('filesThatCannotBeOpened')]
    public function testCreateStreamFromFileExplainsWhenTheFileCannotBeOpened(string $name, string $mode): void
    {
        file_put_contents($this->directory . '/existant.txt', 'contenu');

        try {
            new HttpFactory()->createStreamFromFile($this->directory . '/' . $name, $mode);
            self::fail('Une exception était attendue.');
        } catch (StreamException $exception) {
            self::assertStringContainsString('« ' . $mode . ' »', $exception->getMessage());
            self::assertStringNotContainsString($this->directory, $exception->getMessage(), 'Le chemin ne doit pas fuiter.');
        }
    }

    public function testTheOpenFailureIsARuntimeExceptionAsPsr17Requires(): void
    {
        $this->expectException(\RuntimeException::class);

        new HttpFactory()->createStreamFromFile($this->directory . '/absent.txt');
    }

    // --- Sécurité : adresses à protocole -----------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesThatMustNeverBeOpened(): iterable
    {
        yield 'corps de la requête' => ['php://input'];
        yield 'filtre php' => ['php://filter/convert.base64-encode/resource=/etc/passwd'];
        yield 'archive phar' => ['phar://piege.phar/fichier.txt'];
        yield 'adresse distante' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'protocole file' => ['file:///etc/passwd'];
        yield 'données en ligne' => ['data://text/plain;base64,SGVsbG8='];
        yield 'données en ligne, forme courte' => ['data:text/plain,Bonjour'];
        yield 'commande' => ['expect://ls'];
        yield 'octet nul' => ["fichier.txt\0.png"];
        yield 'vide' => [''];
    }

    #[DataProvider('addressesThatMustNeverBeOpened')]
    public function testCreateStreamFromFileNeverOpensAnAddressWithAProtocol(string $address): void
    {
        try {
            new HttpFactory()->createStreamFromFile($address);
            self::fail('Une exception était attendue.');
        } catch (StreamException $exception) {
            self::assertStringNotContainsString('passwd', $exception->getMessage());
            self::assertStringNotContainsString('169.254', $exception->getMessage());
        }
    }

    #[DataProvider('addressesThatMustNeverBeOpened')]
    public function testCreateStreamFromFileNeverWritesToAnAddressWithAProtocol(string $address): void
    {
        $this->expectException(StreamException::class);

        new HttpFactory()->createStreamFromFile($address, 'w');
    }

    // --- Fichiers envoyés --------------------------------------------------

    public function testCreateUploadedFile(): void
    {
        $stream = Stream::fromString('contenu');

        $file = new HttpFactory()->createUploadedFile($stream, 7, UPLOAD_ERR_OK, 'photo.png', 'image/png');

        self::assertSame($stream, $file->getStream());
        self::assertSame(7, $file->getSize());
        self::assertSame('photo.png', $file->getClientFilename());
        self::assertSame('image/png', $file->getClientMediaType());
    }

    public function testCreateUploadedFileTakesTheSizeOfTheStreamByDefault(): void
    {
        self::assertSame(7, new HttpFactory()->createUploadedFile(Stream::fromString('contenu'))->getSize());
    }

    // --- Outils ------------------------------------------------------------

    private function file(string $content): string
    {
        $file = $this->directory . DIRECTORY_SEPARATOR . 'fichier-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($file, $content);

        return $file;
    }

    /**
     * Appelle une méthode comme le ferait un code sans analyse statique :
     * PHPStan refuserait, à raison, de laisser passer ces mauvais arguments.
     */
    private static function callUnchecked(object $object, string $method, mixed ...$arguments): mixed
    {
        return $object->{$method}(...$arguments);
    }
}
