<?php

declare(strict_types=1);

namespace Wazi\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Config\Config;
use Wazi\Config\EnvFile;
use Wazi\Config\Exception\ConfigException;

final class ConfigTest extends TestCase
{
    /** Un dossier temporaire propre à chaque test, supprimé ensuite. */
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wazi-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'public');
    }

    protected function tearDown(): void
    {
        foreach ([$this->directory . '/public/.env', $this->directory . '/.env'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($this->directory . DIRECTORY_SEPARATOR . 'public');
        rmdir($this->directory);
    }

    // --- Lire des réglages -------------------------------------------------

    public function testItReadsTypedSettingsFromAnEnvFile(): void
    {
        $config = Config::fromEnvFile($this->envFile("APP_NAME=\"Mon carnet\"\nPAGINATION=20\nAPP_DEBUG=true\nAPP_HOSTS=exemple.com, www.exemple.com\n"));

        self::assertSame('Mon carnet', $config->string('APP_NAME'));
        self::assertSame(20, $config->int('PAGINATION'));
        self::assertTrue($config->bool('APP_DEBUG'));
        self::assertSame(['exemple.com', 'www.exemple.com'], $config->list('APP_HOSTS'));
    }

    public function testDefaultValuesAreUsedForMissingKeys(): void
    {
        $config = new Config();

        self::assertSame('Mon site', $config->string('APP_NAME', 'Mon site'));
        self::assertSame(20, $config->int('PAGINATION', 20));
        self::assertFalse($config->bool('APP_DEBUG', false));
        self::assertTrue($config->bool('APP_DEBUG', true));
        self::assertSame([], $config->list('APP_HOSTS', []));
        self::assertSame('', $config->string('APP_NAME', ''));
        self::assertSame(0, $config->int('PAGINATION', 0));
    }

    public function testAValueFromTheFileWinsOverTheDefault(): void
    {
        $config = new Config(['APP_NAME' => 'Carnet', 'APP_DEBUG' => 'false']);

        self::assertSame('Carnet', $config->string('APP_NAME', 'Mon site'));
        self::assertFalse($config->bool('APP_DEBUG', true));
    }

    public function testHasTellsWhetherAKeyIsDefined(): void
    {
        $config = new Config(['APP_NAME' => '']);

        self::assertTrue($config->has('APP_NAME'), 'Une clé vide est tout de même définie.');
        self::assertFalse($config->has('AUTRE'));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function lists(): iterable
    {
        yield 'une valeur' => ['exemple.com', ['exemple.com']];
        yield 'espaces' => [' a , b ,c ', ['a', 'b', 'c']];
        yield 'éléments vides ignorés' => ['a,,b,', ['a', 'b']];
        yield 'vide' => ['', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('lists')]
    public function testAListIsSplitOnCommas(string $value, array $expected): void
    {
        self::assertSame($expected, new Config(['CLE' => $value])->list('CLE'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function integers(): iterable
    {
        yield 'zéro' => ['0', 0];
        yield 'positif' => ['3306', 3306];
        yield 'négatif' => ['-5', -5];
    }

    #[DataProvider('integers')]
    public function testItReadsAnInteger(string $value, int $expected): void
    {
        self::assertSame($expected, new Config(['CLE' => $value])->int('CLE'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function booleans(): iterable
    {
        yield 'true' => ['true', true];
        yield 'false' => ['false', false];
        yield 'majuscules' => ['TRUE', true];
        yield 'casse mélangée' => ['False', false];
    }

    #[DataProvider('booleans')]
    public function testItReadsABoolean(string $value, bool $expected): void
    {
        self::assertSame($expected, new Config(['CLE' => $value])->bool('CLE'));
    }

    // --- Erreurs pédagogiques ----------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesThatAreNotIntegers(): iterable
    {
        yield 'texte' => ['vingt'];
        yield 'vide' => [''];
        yield 'virgule' => ['1.5'];
        yield 'unité' => ['20px'];
        yield 'espace' => ['2 0'];
        yield 'zéro devant' => ['007'];
        yield 'hexadécimal' => ['0x1F'];
        yield 'trop grand pour un entier' => ['99999999999999999999'];
        yield 'retour à la ligne' => ["20\n"];
    }

    #[DataProvider('valuesThatAreNotIntegers')]
    public function testAValueThatIsNotAnIntegerIsRejectedWithoutBeingShown(string $value): void
    {
        try {
            new Config(['PAGINATION' => $value])->int('PAGINATION', 20);
            self::fail('Une exception était attendue.');
        } catch (ConfigException $exception) {
            self::assertStringContainsString('« PAGINATION »', $exception->getMessage());
            self::assertStringContainsString('PAGINATION=20', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesThatAreNotBooleans(): iterable
    {
        yield '1' => ['1'];
        yield '0' => ['0'];
        yield 'oui' => ['oui'];
        yield 'on' => ['on'];
        yield 'yes' => ['yes'];
        yield 'vide' => [''];
        yield 'presque' => ['true '];
    }

    /**
     * Sécurité : pour un réglage comme APP_DEBUG, une valeur ambiguë ne doit
     * pas être devinée.
     */
    #[DataProvider('valuesThatAreNotBooleans')]
    public function testOnlyTrueAndFalseAreBooleans(string $value): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('ni « true » ni « false »');

        new Config(['APP_DEBUG' => $value])->bool('APP_DEBUG', false);
    }

    public function testAMissingRequiredKeyIsExplained(): void
    {
        try {
            new Config([], '/var/www/.env')->string('DATABASE_PASSWORD');
            self::fail('Une exception était attendue.');
        } catch (ConfigException $exception) {
            self::assertStringContainsString('« DATABASE_PASSWORD »', $exception->getMessage());
            self::assertStringContainsString('/var/www/.env', $exception->getMessage());
            self::assertStringContainsString('DATABASE_PASSWORD=...', $exception->getMessage());
        }
    }

    public function testEveryGetterRequiresTheKeyWhenThereIsNoDefault(): void
    {
        $config = new Config();
        $failures = 0;

        foreach (['string', 'int', 'bool', 'list'] as $getter) {
            try {
                $config->{$getter}('ABSENTE');
            } catch (ConfigException) {
                $failures++;
            }
        }

        self::assertSame(4, $failures);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeyNames(): iterable
    {
        yield 'minuscules' => ['app_name'];
        yield 'vide' => [''];
        yield 'espace' => ['APP NAME'];
        yield 'retour à la ligne' => ["APP\nFAUSSE LIGNE"];
        yield 'chiffre au début' => ['1APP'];
    }

    #[DataProvider('invalidKeyNames')]
    public function testAnInvalidKeyNameIsRejectedWithoutBeingRepeated(string $key): void
    {
        try {
            new Config()->string($key, 'défaut');
            self::fail('Une exception était attendue.');
        } catch (ConfigException $exception) {
            self::assertStringNotContainsString("\n", $exception->getMessage());
            self::assertStringNotContainsString('FAUSSE', $exception->getMessage());
        }
    }

    // --- Fichier -----------------------------------------------------------

    public function testAMissingFileGivesAnEmptyConfiguration(): void
    {
        $config = Config::fromEnvFile($this->directory . '/.env');

        self::assertFalse($config->has('APP_NAME'));
        self::assertSame('Mon site', $config->string('APP_NAME', 'Mon site'));
    }

    public function testAMissingFileIsMentionedWhenARequiredKeyIsAbsent(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('.env n\'existe pas');

        Config::fromEnvFile($this->directory . '/.env')->string('DATABASE_PASSWORD');
    }

    public function testAnInvalidFileIsRejectedWhenItIsRead(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('ligne 2');

        Config::fromEnvFile($this->envFile("APP_NAME=Carnet\nligne sans signe\n"));
    }

    public function testADirectoryIsNotAConfigurationFile(): void
    {
        $this->expectException(ConfigException::class);

        Config::fromEnvFile($this->directory);
    }

    public function testAFileThatIsTooLargeIsRejected(): void
    {
        $file = $this->envFile('CLE=' . str_repeat('a', EnvFile::MAX_SIZE));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('taille maximale');

        Config::fromEnvFile($file);
    }

    // --- Sécurité ----------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsThatAreNotPlainFiles(): iterable
    {
        yield 'flux php' => ['php://filter/resource=/etc/passwd'];
        yield 'adresse distante' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'archive phar' => ['phar://piege.phar/.env'];
        yield 'octet nul' => ["/var/www/.env\0.txt"];
        yield 'vide' => [''];
    }

    #[DataProvider('pathsThatAreNotPlainFiles')]
    public function testOnlyAPlainFilePathIsAccepted(string $path): void
    {
        try {
            Config::fromEnvFile($path);
            self::fail('Une exception était attendue.');
        } catch (ConfigException $exception) {
            self::assertStringNotContainsString('passwd', $exception->getMessage());
            self::assertStringNotContainsString('169.254', $exception->getMessage());
        }
    }

    /**
     * Un .env dans le dossier public se télécharge en tapant son adresse :
     * c'est la fuite de mots de passe la plus courante.
     */
    public function testAnEnvFileInsideThePublicDirectoryIsRefused(): void
    {
        $file = $this->envFile('DATABASE_PASSWORD=secret', 'public/.env');

        try {
            $this->withDocumentRoot($this->directory . '/public', static fn(): Config => Config::fromEnvFile($file));
            self::fail('Une exception était attendue.');
        } catch (ConfigException $exception) {
            self::assertStringContainsString('dossier public', $exception->getMessage());
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public function testADetourInThePathDoesNotFoolTheCheck(): void
    {
        $this->envFile('DATABASE_PASSWORD=secret', 'public/.env');
        $detour = $this->directory . '/public/../public/.env';

        $this->expectException(ConfigException::class);

        $this->withDocumentRoot($this->directory . '/public/', static fn(): Config => Config::fromEnvFile($detour));
    }

    public function testAnEnvFileNextToThePublicDirectoryIsAccepted(): void
    {
        $file = $this->envFile('APP_NAME=Carnet');

        $config = $this->withDocumentRoot($this->directory . '/public', static fn(): Config => Config::fromEnvFile($file));

        self::assertSame('Carnet', $config->string('APP_NAME'));
    }

    public function testThePublicLocationCanOnlyBeAcceptedExplicitly(): void
    {
        $file = $this->envFile('APP_NAME=Carnet', 'public/.env');

        $config = $this->withDocumentRoot(
            $this->directory . '/public',
            static fn(): Config => Config::fromEnvFile($file, unsafeAllowPublicLocation: true),
        );

        self::assertSame('Carnet', $config->string('APP_NAME'));
    }

    /**
     * Les valeurs ne doivent pas se répandre dans le processus, où phpinfo()
     * ou un programme lancé par PHP pourraient les lire.
     */
    public function testValuesAreNeverCopiedIntoTheProcessEnvironment(): void
    {
        Config::fromEnvFile($this->envFile('WAZI_TEST_SECRET=mot-de-passe'));

        self::assertFalse(getenv('WAZI_TEST_SECRET'));
        self::assertArrayNotHasKey('WAZI_TEST_SECRET', $_ENV);
        self::assertArrayNotHasKey('WAZI_TEST_SECRET', $_SERVER);
    }

    // --- Variables d'environnement du serveur ------------------------------

    /**
     * C'est ainsi qu'on configure un site hébergé dans un conteneur ou sur
     * une plateforme : il n'y a aucun fichier .env en ligne.
     */
    public function testAServerEnvironmentVariableIsUsedWhenThereIsNoFile(): void
    {
        $config = $this->withEnvironment(
            ['WAZI_TEST_NAME' => 'Site en ligne', 'WAZI_TEST_DEBUG' => 'false', 'WAZI_TEST_PORT' => '3306', 'WAZI_TEST_HOSTS' => 'a.com,b.com'],
            fn(): Config => Config::fromEnvFile($this->directory . '/.env'),
            static function (Config $config): void {
                self::assertSame('Site en ligne', $config->string('WAZI_TEST_NAME'));
                self::assertFalse($config->bool('WAZI_TEST_DEBUG', true));
                self::assertSame(3306, $config->int('WAZI_TEST_PORT'));
                self::assertSame(['a.com', 'b.com'], $config->list('WAZI_TEST_HOSTS'));
                self::assertTrue($config->has('WAZI_TEST_NAME'));
            },
        );

        self::assertFalse($config->has('WAZI_TEST_NAME'), 'Une fois la variable retirée, la clé n\'existe plus.');
    }

    public function testAServerEnvironmentVariableWinsOverTheFile(): void
    {
        $file = $this->envFile("WAZI_TEST_NAME=Depuis le fichier\nWAZI_TEST_OTHER=Fichier seul\n");

        $this->withEnvironment(
            ['WAZI_TEST_NAME' => 'Depuis le serveur'],
            static fn(): Config => Config::fromEnvFile($file),
            static function (Config $config): void {
                self::assertSame('Depuis le serveur', $config->string('WAZI_TEST_NAME'));
                self::assertSame('Fichier seul', $config->string('WAZI_TEST_OTHER'));
            },
        );
    }

    public function testAnEmptyServerEnvironmentVariableIsStillAValue(): void
    {
        $file = $this->envFile('WAZI_TEST_NAME=Depuis le fichier');

        $this->withEnvironment(
            ['WAZI_TEST_NAME' => ''],
            static fn(): Config => Config::fromEnvFile($file),
            static function (Config $config): void {
                self::assertSame('', $config->string('WAZI_TEST_NAME', 'défaut'));
            },
        );
    }

    public function testAValueFromTheEnvironmentIsTypeCheckedLikeAnyOther(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('ni « true » ni « false »');

        $this->withEnvironment(
            ['WAZI_TEST_DEBUG' => '1'],
            fn(): Config => Config::fromEnvFile($this->directory . '/.env'),
            static function (Config $config): void {
                $config->bool('WAZI_TEST_DEBUG', false);
            },
        );
    }

    /**
     * Une configuration construite à la main (dans un test, par exemple) ne
     * contient que ce qu'on lui a donné.
     */
    public function testAConfigurationBuiltByHandDoesNotReadTheEnvironment(): void
    {
        $this->withEnvironment(
            ['WAZI_TEST_NAME' => 'Depuis le serveur'],
            static fn(): Config => new Config(['WAZI_TEST_NAME' => 'Donnée à la main']),
            static function (Config $config): void {
                self::assertSame('Donnée à la main', $config->string('WAZI_TEST_NAME'));
                self::assertFalse(new Config()->has('WAZI_TEST_NAME'));
            },
        );
    }

    /**
     * Sécurité (faille dite « httpoxy ») : sur certains serveurs, l'en-tête
     * « Proxy: pirate.com » d'un visiteur devient la variable HTTP_PROXY.
     * Aucun réglage ne doit pouvoir se lire sous un tel nom.
     */
    public function testAKeyStartingWithHttpCanNeverBeRead(): void
    {
        $this->withEnvironment(
            ['HTTP_PROXY' => 'pirate.com:8080', 'HTTP_X_FORWARDED_HOST' => 'pirate.com'],
            fn(): Config => Config::fromEnvFile($this->directory . '/.env'),
            static function (Config $config): void {
                foreach (['HTTP_PROXY', 'HTTP_X_FORWARDED_HOST', 'HTTP_'] as $key) {
                    try {
                        $config->string($key, 'défaut');
                        self::fail('Une exception était attendue pour ' . $key);
                    } catch (ConfigException $exception) {
                        self::assertStringContainsString('réservé', $exception->getMessage());
                        self::assertStringNotContainsString('pirate', $exception->getMessage());
                    }
                }
            },
        );
    }

    public function testAKeyStartingWithHttpCannotBeWrittenInTheFileEither(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('ligne 2');

        Config::fromEnvFile($this->envFile("APP_NAME=Carnet\nHTTP_PROXY=proxy.exemple.com\n"));
    }

    public function testAKeyThatOnlyContainsHttpIsFine(): void
    {
        $config = Config::fromEnvFile($this->envFile("APP_HTTP_TIMEOUT=30\nHTTPS_ONLY=true\n"));

        self::assertSame(30, $config->int('APP_HTTP_TIMEOUT'));
        self::assertTrue($config->bool('HTTPS_ONLY'));
    }

    public function testAMissingKeyMentionsBothSources(): void
    {
        try {
            Config::fromEnvFile($this->envFile('APP_NAME=Carnet'))->string('WAZI_TEST_ABSENT');
            self::fail('Une exception était attendue.');
        } catch (ConfigException $exception) {
            self::assertStringContainsString('variable d\'environnement', $exception->getMessage());
            self::assertStringContainsString('.env', $exception->getMessage());
        }
    }

    // --- Sécurité : ne pas laisser fuir les valeurs --------------------------

    public function testDumpingTheConfigurationHidesItsValues(): void
    {
        $config = new Config(['DATABASE_PASSWORD' => 'mot-de-passe-tres-secret', 'APP_NAME' => 'Carnet'], '/var/www/.env');

        ob_start();
        var_dump($config);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString('mot-de-passe-tres-secret', $dump);
        self::assertStringNotContainsString('mot-de-passe-tres-secret', print_r($config, true));
        self::assertStringContainsString('DATABASE_PASSWORD', $dump, 'Le nom des clés reste visible.');
    }

    /**
     * Une trace d'erreur liste les arguments des fonctions. Ceux du
     * constructeur sont marqués sensibles : PHP les remplace par un objet opaque.
     */
    public function testAStackTraceHidesTheValuesGivenToTheConstructor(): void
    {
        try {
            // Un second argument du mauvais type fait échouer le constructeur
            // après qu'il a reçu les valeurs : elles sont donc dans la trace.
            new \ReflectionClass(Config::class)->newInstanceArgs([['DATABASE_PASSWORD' => 'secret'], new \stdClass()]);
            self::fail('Une exception était attendue.');
        } catch (\TypeError $error) {
            $constructorCall = $error->getTrace()[0];

            self::assertSame('__construct', $constructorCall['function']);
            self::assertInstanceOf(\SensitiveParameterValue::class, $constructorCall['args'][0] ?? null);
        }
    }

    // --- Outils ------------------------------------------------------------

    /**
     * Définit des variables d'environnement le temps d'une vérification,
     * puis les retire, même si la vérification échoue.
     *
     * @param array<string, string>  $variables
     * @param \Closure(): Config     $create
     * @param \Closure(Config): void $check
     */
    private function withEnvironment(array $variables, \Closure $create, \Closure $check): Config
    {
        foreach ($variables as $name => $value) {
            putenv($name . '=' . $value);
        }

        try {
            $config = $create();
            $check($config);
        } finally {
            foreach (array_keys($variables) as $name) {
                putenv($name);
            }
        }

        return $config;
    }

    private function envFile(string $content, string $name = '.env'): string
    {
        $file = $this->directory . DIRECTORY_SEPARATOR . $name;
        file_put_contents($file, $content);

        return $file;
    }

    /**
     * Exécute une fonction comme si le serveur web distribuait ce dossier.
     *
     * @param \Closure(): Config $callback
     */
    private function withDocumentRoot(string $documentRoot, \Closure $callback): Config
    {
        $previous = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $_SERVER['DOCUMENT_ROOT'] = $documentRoot;

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                unset($_SERVER['DOCUMENT_ROOT']);
            } else {
                $_SERVER['DOCUMENT_ROOT'] = $previous;
            }
        }
    }
}
