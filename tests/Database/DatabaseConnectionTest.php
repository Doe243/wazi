<?php

declare(strict_types=1);

namespace Wazi\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Database\Database;
use Wazi\Database\Exception\DatabaseException;

/**
 * Décrire une base et s'y connecter : ce qui est accepté, ce qui est refusé,
 * et ce qui ne doit jamais fuiter.
 */
final class DatabaseConnectionTest extends TestCase
{
    /** Un dossier temporaire, supprimé après chaque test. */
    private string $directory;

    private mixed $documentRoot;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wazi-db-' . bin2hex(random_bytes(8));
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'public', 0o777, true);
        $this->documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
    }

    protected function tearDown(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->documentRoot;

        // Sous Windows, un fichier SQLite ne se supprime qu'une fois la connexion fermée.
        gc_collect_cycles();

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($this->directory);
    }

    // --- La connexion attend la première requête --------------------------------

    public function testNothingHappensUntilTheFirstQuery(): void
    {
        $file = $this->directory . '/var/app.sqlite';
        $db = Database::sqlite($file);

        self::assertFileDoesNotExist($file);
        self::assertSame(Database::SQLITE, $db->driver());

        self::assertSame(1, $db->selectValue('SELECT 1'));
        // Le dossier et le fichier ont été créés à ce moment-là.
        self::assertFileExists($file);
    }

    /**
     * Une base injoignable ne gêne pas tant qu'on ne lui demande rien : la
     * console peut lister les routes sans base de données.
     */
    public function testDescribingAnUnreachableDatabaseDoesNotFail(): void
    {
        $db = Database::mysql('base', 'utilisateur', 'secret', 'hote.invalid');

        self::assertSame(Database::MYSQL, $db->driver());
        self::assertSame(Database::POSTGRES, Database::postgres('base', 'utilisateur', 'secret')->driver());
    }

    public function testDataIsKeptInTheFile(): void
    {
        $file = $this->directory . '/app.sqlite';

        $first = Database::sqlite($file);
        $first->execute('CREATE TABLE notes (texte TEXT)');
        $first->insert('notes', ['texte' => 'Pain']);
        unset($first);

        self::assertSame('Pain', Database::sqlite($file)->selectValue('SELECT texte FROM notes'));
    }

    // --- Sécurité : le fichier SQLite ---------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function notPlainPaths(): iterable
    {
        yield 'php://' => ['php://filter/resource=/etc/passwd'];
        yield 'phar://' => ['phar:///tmp/x.phar/base'];
        yield 'http://' => ['http://exemple.com/base.sqlite'];
        yield 'file://' => ['file:///tmp/base.sqlite'];
        yield 'octet nul' => ["/tmp/base.sqlite\0.txt"];
        yield 'vide' => [''];
    }

    #[DataProvider('notPlainPaths')]
    public function testOnlyAPlainFilePathIsAccepted(string $file): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('chemin de fichier ordinaire');

        Database::sqlite($file);
    }

    /**
     * Le site démarre dans public/, la console à la racine du projet : un
     * chemin relatif désignerait deux fichiers différents.
     */
    public function testARelativePathIsRefused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('est relatif');

        Database::sqlite('var/app.sqlite');
    }

    /**
     * Sécurité : dans le dossier public, la base entière serait téléchargeable.
     */
    public function testAFileInsideThePublicFolderIsRefused(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->directory . '/public';

        $paths = [
            '/public/app.sqlite',
            '/public/donnees/pas/encore/creees/app.sqlite',
            // Des « .. » qui passent par des dossiers qui n'existent pas (encore).
            '/var/../public/app.sqlite',
            '/var/pas/encore/../../../public/./app.sqlite',
            '/public/../public/app.sqlite',
        ];

        foreach ($paths as $path) {
            try {
                Database::sqlite($this->directory . $path);
                self::fail('Un fichier public aurait dû être refusé : ' . $path);
            } catch (DatabaseException $error) {
                self::assertStringContainsString('dans le dossier public du site', $error->getMessage());
            }
        }

        // Hors du dossier public : accepté. Dedans, seulement en le demandant.
        self::assertSame(Database::SQLITE, Database::sqlite($this->directory . '/var/app.sqlite')->driver());
        self::assertSame(Database::SQLITE, Database::sqlite($this->directory . '/public/app.sqlite', unsafeAllowPublicLocation: true)->driver());
    }

    /**
     * Sécurité : le chemin vérifié est celui qui est ouvert. Un « .. » qui
     * traverse un dossier absent ne crée pas ce dossier au passage.
     */
    public function testDotsInThePathAreResolvedBeforeAnythingIsCreated(): void
    {
        Database::sqlite($this->directory . '/absent/../var/./app.sqlite')->execute('CREATE TABLE notes (id INTEGER)');

        self::assertFileExists($this->directory . '/var/app.sqlite');
        self::assertDirectoryDoesNotExist($this->directory . '/absent');
    }

    public function testADirectoryThatCannotBeCreatedIsExplained(): void
    {
        // Un fichier occupe la place du dossier.
        file_put_contents($this->directory . '/occupe', '');

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('n\'a pas pu être créé');

        Database::sqlite($this->directory . '/occupe/app.sqlite')->select('SELECT 1');
    }

    // --- Une adresse : DATABASE_URL -------------------------------------------------

    public function testASqliteAddressIsRelativeToTheProject(): void
    {
        $db = Database::fromUrl('sqlite:var/app.sqlite', $this->directory);
        $db->execute('CREATE TABLE notes (texte TEXT)');

        self::assertFileExists($this->directory . '/var/app.sqlite');
        self::assertSame(Database::SQLITE, Database::fromUrl('sqlite::memory:')->driver());
        self::assertSame(Database::SQLITE, Database::fromUrl('sqlite:' . $this->directory . '/autre.sqlite')->driver());
    }

    public function testARelativeSqliteAddressNeedsTheProjectDirectory(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Database::fromUrl($url, __DIR__)');

        Database::fromUrl('sqlite:var/app.sqlite');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function serverAddresses(): iterable
    {
        yield 'mysql' => ['mysql://alice:secret@localhost/boutique', Database::MYSQL];
        yield 'mysql, avec port' => ['mysql://alice:secret@db.exemple.com:3307/boutique', Database::MYSQL];
        yield 'mariadb' => ['mariadb://alice:secret@localhost/boutique', Database::MYSQL];
        yield 'mot de passe vide' => ['mysql://root@localhost/boutique', Database::MYSQL];
        yield 'mot de passe encodé' => ['mysql://alice:p%40ss%2Fw%3Ard%25@localhost/boutique', Database::MYSQL];
        yield 'postgres' => ['postgres://alice:secret@localhost/boutique', Database::POSTGRES];
        yield 'postgresql' => ['postgresql://alice:secret@localhost:5433/boutique', Database::POSTGRES];
        yield 'postgres, chiffré' => ['postgres://alice:secret@db.exemple.com/boutique?sslmode=require', Database::POSTGRES];
        yield 'adresse IPv6' => ['postgres://alice:secret@[::1]/boutique', Database::POSTGRES];
    }

    #[DataProvider('serverAddresses')]
    public function testAServerAddressIsUnderstood(string $url, string $driver): void
    {
        self::assertSame($driver, Database::fromUrl($url)->driver());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badAddresses(): iterable
    {
        yield 'vide' => [''];
        yield 'pas une adresse' => ['boutique'];
        yield 'base inconnue' => ['oracle://alice:secret-du-test@localhost/boutique'];
        yield 'sans nom de base' => ['mysql://alice:secret-du-test@localhost'];
        yield 'sans nom de base, avec barre' => ['mysql://alice:secret-du-test@localhost/'];
        yield 'sans utilisateur' => ['mysql://localhost/boutique'];
        yield 'sans hôte' => ['mysql:///boutique'];
        yield 'espace dans le mot de passe' => ['mysql://alice:secret-du-test avec espace@localhost/boutique'];
        yield 'fragment' => ['mysql://alice:secret-du-test@localhost/boutique#x'];
        yield 'sqlite à deux barres' => ['sqlite://var/app.sqlite'];
        yield 'sqlite sans fichier' => ['sqlite:'];
    }

    /**
     * Sécurité : une adresse refusée n'est jamais citée, elle contient un mot de passe.
     */
    #[DataProvider('badAddresses')]
    public function testAMalformedAddressIsRefusedWithoutBeingQuoted(string $url): void
    {
        try {
            Database::fromUrl($url, $this->directory);
            self::fail('L\'adresse aurait dû être refusée.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('L\'adresse de la base de données est mal écrite', $error->getMessage());
            self::assertStringNotContainsString('secret-du-test', $error->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownOptions(): iterable
    {
        yield 'option inconnue' => ['postgres://alice:secret@localhost/boutique?options=-c%20statement_timeout%3D0'];
        yield 'valeur inconnue' => ['postgres://alice:secret@localhost/boutique?sslmode=parfois'];
        yield 'deux options' => ['postgres://alice:secret@localhost/boutique?sslmode=require&host=ailleurs'];
        yield 'option pour mysql' => ['mysql://alice:secret@localhost/boutique?charset=latin1'];
    }

    /**
     * Sécurité : ce qui suit le « ? » pourrait changer les réglages imposés.
     */
    #[DataProvider('unknownOptions')]
    public function testOnlyKnownOptionsAreAccepted(string $url): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('une option que Wazi ne connaît pas');

        Database::fromUrl($url);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function settingsThatCouldAddSettings(): iterable
    {
        yield 'point-virgule dans la base' => ['boutique;unix_socket=/tmp/x', 'localhost'];
        yield 'point-virgule dans l\'hôte' => ['boutique', 'localhost;dbname=mysql'];
        yield 'espace' => ['bou tique', 'localhost'];
        yield 'apostrophe' => ["bou'tique", 'localhost'];
        yield 'égal' => ['boutique', 'a=b'];
        yield 'retour à la ligne' => ["boutique\n", 'localhost'];
        yield 'vide' => ['', 'localhost'];
    }

    /**
     * Sécurité : le nom de la base et l'hôte entrent dans l'adresse de
     * connexion. Un « ; » y ajouterait un réglage.
     */
    #[DataProvider('settingsThatCouldAddSettings')]
    public function testAHostOrDatabaseNameCannotCarryAnotherSetting(string $database, string $host): void
    {
        foreach (['mysql', 'postgres'] as $kind) {
            try {
                $kind === 'mysql' ? Database::mysql($database, 'alice', 'secret', $host) : Database::postgres($database, 'alice', 'secret', $host);
                self::fail('Le réglage aurait dû être refusé.');
            } catch (DatabaseException $error) {
                self::assertStringContainsString('contient un caractère qui n\'y a pas sa place', $error->getMessage());
            }
        }
    }

    // --- Sécurité : le mot de passe ne fuit pas ---------------------------------------

    public function testDebuggingToolsShowNeitherTheAddressNorThePassword(): void
    {
        $db = Database::fromUrl('mysql://alice:mot-de-passe-secret@db.exemple.com/boutique');

        ob_start();
        var_dump($db);
        $dump = (string) ob_get_clean() . print_r($db, true);

        self::assertStringNotContainsString('mot-de-passe-secret', $dump);
        self::assertStringNotContainsString('db.exemple.com', $dump);
        self::assertStringNotContainsString('alice', $dump);
        self::assertStringContainsString('mysql', $dump);
    }

    public function testThePasswordIsMaskedInATrace(): void
    {
        // La CI ne garde pas les arguments dans les traces : ce test les demande.
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            Database::fromUrl('mysql://alice:mot-de-passe-secret@db.exemple.com/boutique?charset=latin1');
            self::fail('L\'adresse aurait dû être refusée.');
        } catch (DatabaseException $error) {
            self::assertStringNotContainsString('mot-de-passe-secret', serialize(array_map(
                static fn(array $frame): array => array_map(static fn(mixed $argument): string => is_string($argument) ? $argument : get_debug_type($argument), $frame['args'] ?? []),
                $error->getTrace(),
            )));
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    /**
     * Sans l'extension PHP de la base, le message dit laquelle activer.
     * (Sur une machine où elle est chargée, il n'y a rien à vérifier.)
     */
    public function testAMissingPhpExtensionIsExplained(): void
    {
        if (extension_loaded('pdo_mysql')) {
            self::markTestSkipped('L\'extension pdo_mysql est chargée sur cette machine.');
        }

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('l\'extension « pdo_mysql » n\'est pas chargée');

        Database::mysql('base', 'utilisateur', 'secret')->select('SELECT 1');
    }
}
