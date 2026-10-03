<?php

declare(strict_types=1);

namespace Wazi\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Wazi\Http\Exception\InvalidStreamException;
use Wazi\Http\Exception\StreamException;
use Wazi\Http\Stream;

final class StreamTest extends TestCase
{
    /** @var list<string> Fichiers temporaires à supprimer après chaque test. */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->temporaryFiles = [];
    }

    // --- Création ----------------------------------------------------------

    public function testItImplementsPsr7(): void
    {
        self::assertInstanceOf(StreamInterface::class, Stream::fromString());
    }

    public function testFromStringStartsAtTheBeginningOfTheContent(): void
    {
        $stream = Stream::fromString('Bonjour');

        self::assertSame(0, $stream->tell());
        self::assertSame(7, $stream->getSize());
        self::assertSame('Bonjour', $stream->getContents());
    }

    public function testFromStringIsReadableWritableAndSeekable(): void
    {
        $stream = Stream::fromString('Bonjour');

        self::assertTrue($stream->isReadable());
        self::assertTrue($stream->isWritable());
        self::assertTrue($stream->isSeekable());
    }

    public function testItWrapsAnExistingResource(): void
    {
        $stream = new Stream($this->openFile('contenu du fichier', 'r'));

        self::assertSame('contenu du fichier', (string) $stream);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesThatAreNotStreams(): iterable
    {
        yield 'texte' => ['Bonjour'];
        yield 'nombre' => [42];
        yield 'null' => [null];
        yield 'tableau' => [[]];
        yield 'ressource d\'un autre type' => [stream_context_create()];
    }

    #[DataProvider('valuesThatAreNotStreams')]
    public function testItRejectsAnythingThatIsNotAStreamResource(mixed $value): void
    {
        $this->expectException(InvalidStreamException::class);

        new Stream($value);
    }

    public function testItRejectsAnAlreadyClosedResource(): void
    {
        $resource = $this->openFile('contenu', 'r');
        fclose($resource);

        $this->expectException(InvalidStreamException::class);

        new Stream($resource);
    }

    public function testTheConstructorExceptionIsAnInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Stream('Bonjour');
    }

    // --- Sécurité ----------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesThatMustNeverBeOpened(): iterable
    {
        yield 'fichier du système' => ['/etc/passwd'];
        yield 'corps de la requête' => ['php://input'];
        yield 'adresse distante' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'archive phar' => ['phar://piege.phar/fichier.txt'];
    }

    /**
     * Un flux ne s'ouvre jamais à partir d'une adresse : si un nom de fichier
     * venu d'une requête arrivait ici, il ne doit rien pouvoir ouvrir.
     */
    #[DataProvider('addressesThatMustNeverBeOpened')]
    public function testItNeverOpensAnAddressGivenAsText(string $address): void
    {
        $this->expectException(InvalidStreamException::class);

        new Stream($address);
    }

    public function testFromStringStoresTheTextWithoutInterpretingItAsAnAddress(): void
    {
        self::assertSame('php://input', (string) Stream::fromString('php://input'));
    }

    public function testTheErrorMessageDoesNotRevealTheRejectedValue(): void
    {
        try {
            new Stream("secret\nFAUSSE LIGNE DE JOURNAL");
            self::fail('Une exception était attendue.');
        } catch (InvalidStreamException $exception) {
            self::assertStringNotContainsString('secret', $exception->getMessage());
            self::assertStringNotContainsString("\n", $exception->getMessage());
        }
    }

    // --- Lecture -----------------------------------------------------------

    public function testReadReturnsAtMostTheRequestedLength(): void
    {
        $stream = Stream::fromString('Bonjour');

        self::assertSame('Bon', $stream->read(3));
        self::assertSame(3, $stream->tell());
        self::assertSame('jour', $stream->read(100));
    }

    public function testReadingZeroBytesReturnsAnEmptyString(): void
    {
        self::assertSame('', Stream::fromString('Bonjour')->read(0));
    }

    public function testReadRejectsANegativeLength(): void
    {
        $this->expectException(StreamException::class);

        Stream::fromString('Bonjour')->read(-1);
    }

    public function testGetContentsReturnsWhatRemainsAfterThePosition(): void
    {
        $stream = Stream::fromString('Bonjour');
        $stream->seek(3);

        self::assertSame('jour', $stream->getContents());
    }

    public function testEofIsReachedAfterReadingEverything(): void
    {
        $stream = Stream::fromString('Bonjour');

        self::assertFalse($stream->eof());

        $stream->getContents();
        $stream->read(1);

        self::assertTrue($stream->eof());
    }

    public function testAWriteOnlyStreamCannotBeRead(): void
    {
        $stream = new Stream($this->openFile('', 'w'));

        self::assertFalse($stream->isReadable());

        $this->expectException(StreamException::class);

        $stream->read(10);
    }

    public function testGetContentsFailsOnAWriteOnlyStream(): void
    {
        $stream = new Stream($this->openFile('', 'w'));

        $this->expectException(StreamException::class);

        $stream->getContents();
    }

    // --- Écriture ----------------------------------------------------------

    public function testWriteReturnsTheNumberOfBytesWritten(): void
    {
        $stream = Stream::fromString();

        self::assertSame(5, $stream->write('café'), 'Le « é » occupe deux octets en UTF-8.');
        self::assertSame(5, $stream->getSize());
        self::assertSame('café', (string) $stream);
    }

    public function testAReadOnlyStreamCannotBeWritten(): void
    {
        $stream = new Stream($this->openFile('contenu', 'r'));

        self::assertFalse($stream->isWritable());

        $this->expectException(StreamException::class);

        $stream->write('piège');
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function openingModes(): iterable
    {
        yield 'r' => ['r', true, false];
        yield 'rb' => ['rb', true, false];
        yield 'r+' => ['r+', true, true];
        yield 'w' => ['w', false, true];
        yield 'w+' => ['w+', true, true];
        yield 'a' => ['a', false, true];
        yield 'a+' => ['a+', true, true];
        yield 'c' => ['c', false, true];
        yield 'c+' => ['c+', true, true];
    }

    #[DataProvider('openingModes')]
    public function testReadableAndWritableFollowTheOpeningMode(string $mode, bool $readable, bool $writable): void
    {
        $stream = new Stream($this->openFile('contenu', $mode));

        self::assertSame($readable, $stream->isReadable());
        self::assertSame($writable, $stream->isWritable());
    }

    // --- Déplacement -------------------------------------------------------

    public function testSeekMovesThePosition(): void
    {
        $stream = Stream::fromString('Bonjour');

        $stream->seek(3);
        self::assertSame(3, $stream->tell());

        $stream->seek(-2, SEEK_END);
        self::assertSame('ur', $stream->getContents());
    }

    public function testRewindGoesBackToTheBeginning(): void
    {
        $stream = Stream::fromString('Bonjour');
        $stream->getContents();

        $stream->rewind();

        self::assertSame(0, $stream->tell());
    }

    public function testSeekFailsBeforeTheBeginning(): void
    {
        $this->expectException(StreamException::class);

        Stream::fromString('Bonjour')->seek(-1);
    }

    public function testANonSeekableStreamCannotBeMoved(): void
    {
        // php://output écrit vers la sortie : on ne peut pas y revenir en arrière.
        $stream = new Stream($this->open('php://output', 'w'));

        self::assertFalse($stream->isSeekable());

        $this->expectException(StreamException::class);

        $stream->rewind();
    }

    // --- Conversion en texte ----------------------------------------------

    public function testToStringReadsFromTheBeginningWhateverThePosition(): void
    {
        $stream = Stream::fromString('Bonjour');
        $stream->seek(4);

        self::assertSame('Bonjour', (string) $stream);
    }

    public function testToStringNeverThrowsAsPsr7Requires(): void
    {
        $writeOnly = new Stream($this->openFile('', 'w'));
        $detached = Stream::fromString('Bonjour');
        $detached->detach();

        self::assertSame('', (string) $writeOnly);
        self::assertSame('', (string) $detached);
    }

    // --- Fermeture et détachement -----------------------------------------

    public function testDetachReturnsTheResourceAndLeavesItOpen(): void
    {
        $resource = $this->openFile('contenu', 'r');
        $stream = new Stream($resource);

        self::assertSame($resource, $stream->detach());
        self::assertIsNotClosedResource($resource);
        self::assertNull($stream->detach(), 'Un second detach() ne rend plus rien.');
    }

    public function testCloseClosesTheResource(): void
    {
        $resource = $this->openFile('contenu', 'r');
        $stream = new Stream($resource);

        $stream->close();
        $stream->close();

        self::assertIsClosedResource($resource);
    }

    public function testADetachedStreamAnswersWithNeutralValues(): void
    {
        $stream = Stream::fromString('Bonjour');
        $stream->detach();

        self::assertFalse($stream->isReadable());
        self::assertFalse($stream->isWritable());
        self::assertFalse($stream->isSeekable());
        self::assertNull($stream->getSize());
        self::assertTrue($stream->eof());
        self::assertSame([], $stream->getMetadata());
        self::assertNull($stream->getMetadata('mode'));
    }

    /**
     * @return iterable<string, array{\Closure(Stream): mixed}>
     */
    public static function operationsThatNeedAResource(): iterable
    {
        yield 'read' => [static fn(Stream $stream): string => $stream->read(1)];
        yield 'write' => [static fn(Stream $stream): int => $stream->write('a')];
        yield 'seek' => [static fn(Stream $stream) => $stream->seek(0)];
        yield 'rewind' => [static fn(Stream $stream) => $stream->rewind()];
        yield 'tell' => [static fn(Stream $stream): int => $stream->tell()];
        yield 'getContents' => [static fn(Stream $stream): string => $stream->getContents()];
    }

    /**
     * @param \Closure(Stream): mixed $operation
     */
    #[DataProvider('operationsThatNeedAResource')]
    public function testADetachedStreamRefusesEveryOperation(\Closure $operation): void
    {
        $stream = Stream::fromString('Bonjour');
        $stream->detach();

        $this->expectException(StreamException::class);

        $operation($stream);
    }

    /**
     * @param \Closure(Stream): mixed $operation
     */
    #[DataProvider('operationsThatNeedAResource')]
    public function testAResourceClosedBehindTheStreamGivesAClearError(\Closure $operation): void
    {
        $resource = $this->openFile('contenu', 'r+');
        $stream = new Stream($resource);
        fclose($resource);

        $this->expectException(StreamException::class);

        $operation($stream);
    }

    public function testTheStreamExceptionIsARuntimeExceptionAsPsr7Requires(): void
    {
        $stream = Stream::fromString();
        $stream->close();

        $this->expectException(\RuntimeException::class);

        $stream->tell();
    }

    public function testTheErrorMessageExplainsHowToFixIt(): void
    {
        $stream = new Stream($this->openFile('contenu', 'r'));

        try {
            $stream->write('texte');
            self::fail('Une exception était attendue.');
        } catch (StreamException $exception) {
            self::assertStringContainsString('« r »', $exception->getMessage());
            self::assertStringContainsString('fopen', $exception->getMessage());
        }
    }

    // --- Métadonnées -------------------------------------------------------

    public function testGetMetadataReturnsEverythingOrOneKey(): void
    {
        $stream = new Stream($this->openFile('contenu', 'r'));

        self::assertIsArray($stream->getMetadata());
        self::assertSame('r', $stream->getMetadata('mode'));
        self::assertNull($stream->getMetadata('cle_inconnue'));
    }

    // --- Outils ------------------------------------------------------------

    /**
     * Crée un fichier temporaire avec ce contenu, puis l'ouvre dans le mode demandé.
     *
     * @return resource
     */
    private function openFile(string $content, string $mode)
    {
        $file = tempnam(sys_get_temp_dir(), 'wazi');
        self::assertIsString($file);
        $this->temporaryFiles[] = $file;
        file_put_contents($file, $content);

        return $this->open($file, $mode);
    }

    /**
     * @return resource
     */
    private function open(string $address, string $mode)
    {
        $resource = fopen($address, $mode);
        self::assertIsResource($resource);

        return $resource;
    }
}
