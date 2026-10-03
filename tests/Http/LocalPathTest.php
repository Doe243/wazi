<?php

declare(strict_types=1);

namespace Wazi\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Http\LocalPath;

final class LocalPathTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function plainPaths(): iterable
    {
        yield 'absolu' => ['/var/www/storage/avatar.png'];
        yield 'relatif' => ['storage/avatar.png'];
        yield 'avec retour au dossier parent' => ['/var/www/public/../storage/avatar.png'];
        yield 'lecteur Windows' => ['C:\\Sites\\storage\\avatar.png'];
        yield 'lecteur Windows avec barres obliques' => ['C:/Sites/storage/avatar.png'];
        yield 'espaces et accents' => ['/var/www/mes fichiers/été.png'];
        yield 'deux-points dans le nom' => ['/var/www/rapport:final.txt'];
    }

    #[DataProvider('plainPaths')]
    public function testAnOrdinaryFilePathIsPlain(string $path): void
    {
        self::assertTrue(LocalPath::isPlain($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsThatAreNotPlain(): iterable
    {
        yield 'vide' => [''];
        yield 'php' => ['php://input'];
        yield 'phar' => ['phar://archive.phar/fichier.txt'];
        yield 'http' => ['http://exemple.com/fichier.txt'];
        yield 'https' => ['https://exemple.com/fichier.txt'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'majuscules' => ['PHP://input'];
        yield 'protocole composé' => ['compress.zlib://fichier.gz'];
        yield 'octet nul' => ["avatar.png\0.php"];
        yield 'retour à la ligne' => ["avatar\n.png"];
        yield 'tabulation' => ["avatar\t.png"];
    }

    #[DataProvider('pathsThatAreNotPlain')]
    public function testAnAddressWithAProtocolOrAControlCharacterIsNotPlain(string $path): void
    {
        self::assertFalse(LocalPath::isPlain($path));
    }
}
