<?php

declare(strict_types=1);

namespace Wazi\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Config\EnvFile;
use Wazi\Config\Exception\ConfigException;

final class EnvFileTest extends TestCase
{
    public function testItReadsOneSettingPerLine(): void
    {
        $content = "APP_NAME=Carnet\nPAGINATION=20\nAPP_DEBUG=false\n";

        self::assertSame(['APP_NAME' => 'Carnet', 'PAGINATION' => '20', 'APP_DEBUG' => 'false'], EnvFile::parse($content));
    }

    public function testCommentsAndEmptyLinesAreIgnored(): void
    {
        $content = "# Les réglages du site\n\n   \nAPP_NAME=Carnet\n  # un commentaire indenté\n";

        self::assertSame(['APP_NAME' => 'Carnet'], EnvFile::parse($content));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function values(): iterable
    {
        yield 'simple' => ['CLE=valeur', 'valeur'];
        yield 'vide' => ['CLE=', ''];
        yield 'espaces autour du signe égal' => ['CLE = valeur', 'valeur'];
        yield 'espaces en fin de ligne' => ["CLE=valeur  \t", 'valeur'];
        yield 'espaces au milieu' => ['CLE=Mon beau site', 'Mon beau site'];
        yield 'commentaire en fin de ligne' => ['CLE=valeur # un commentaire', 'valeur'];
        yield 'dièse collé : il fait partie de la valeur' => ['CLE=couleur#fff', 'couleur#fff'];
        yield 'signe égal dans la valeur' => ['CLE=a=b=c', 'a=b=c'];
        yield 'adresse complète' => ['CLE=mysql://utilisateur@localhost:3306/base?charset=utf8', 'mysql://utilisateur@localhost:3306/base?charset=utf8'];
        yield 'guillemets doubles' => ['CLE="Mon beau site"', 'Mon beau site'];
        yield 'guillemets doubles, dièse gardé' => ['CLE="p@ss # mot"', 'p@ss # mot'];
        yield 'guillemets doubles puis commentaire' => ['CLE="valeur" # commentaire', 'valeur'];
        yield 'guillemets doubles, retour à la ligne échappé' => ['CLE="ligne 1\nligne 2"', "ligne 1\nligne 2"];
        yield 'guillemets doubles, guillemet échappé' => ['CLE="il dit \"bonjour\""', 'il dit "bonjour"'];
        yield 'guillemets doubles, barre inversée échappée' => ['CLE="C:\\\\Sites\\\\wazi"', 'C:\Sites\wazi'];
        yield 'guillemets doubles, barre puis n littéral' => ['CLE="a\\\\nb"', 'a\nb'];
        yield 'guillemets doubles vides' => ['CLE=""', ''];
        yield 'guillemets simples : tout est pris tel quel' => ["CLE='a\\nb # c \"d\"'", 'a\nb # c "d"'];
        yield 'guillemets simples puis commentaire' => ["CLE='valeur'  # commentaire", 'valeur'];
        yield 'accents' => ['CLE=été à Kinshasa', 'été à Kinshasa'];
    }

    #[DataProvider('values')]
    public function testItReadsAValue(string $line, string $expected): void
    {
        self::assertSame(['CLE' => $expected], EnvFile::parse($line));
    }

    public function testItAcceptsEveryKindOfLineEnding(): void
    {
        self::assertSame(['A' => '1', 'B' => '2', 'C' => '3', 'D' => '4'], EnvFile::parse("A=1\r\nB=2\nC=3\rD=4"));
    }

    public function testTheInvisibleMarkSomeEditorsAddIsIgnored(): void
    {
        self::assertSame(['APP_NAME' => 'Carnet'], EnvFile::parse("\xEF\xBB\xBFAPP_NAME=Carnet"));
    }

    // --- Sécurité : une valeur n'est qu'un texte -----------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valuesThatLookExecutable(): iterable
    {
        yield 'référence à une autre clé' => ['CLE=${SECRET}', '${SECRET}'];
        yield 'référence courte' => ['CLE=$SECRET', '$SECRET'];
        yield 'référence entre guillemets' => ['CLE="${SECRET}/chemin"', '${SECRET}/chemin'];
        yield 'commande' => ['CLE=$(cat /etc/passwd)', '$(cat /etc/passwd)'];
        yield 'commande entre accents graves' => ['CLE=`id`', '`id`'];
        yield 'code PHP' => ['CLE=<?php system("id"); ?>', '<?php system("id"); ?>'];
    }

    /**
     * Rien n'est jamais remplacé ni exécuté dans une valeur.
     */
    #[DataProvider('valuesThatLookExecutable')]
    public function testNothingInAValueIsEverInterpreted(string $line, string $expected): void
    {
        self::assertSame(['SECRET' => 'mot-de-passe', 'CLE' => $expected], EnvFile::parse("SECRET=mot-de-passe\n" . $line));
    }

    // --- Lignes mal écrites ------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidLines(): iterable
    {
        yield 'sans signe égal' => ['APP_NAME', 'signe « = »'];
        yield 'nom en minuscules' => ['app_name=Carnet', 'majuscules'];
        yield 'nom mélangé' => ['App_Name=Carnet', 'majuscules'];
        yield 'nom commençant par un chiffre' => ['1CLE=valeur', 'signe « = »'];
        yield 'nom avec tiret' => ['APP-NAME=Carnet', 'signe « = »'];
        yield 'nom avec espace' => ['APP NAME=Carnet', 'signe « = »'];
        yield 'préfixe export' => ['export APP_NAME=Carnet', 'signe « = »'];
        yield 'guillemet double non fermé' => ['APP_NAME="Carnet', 'n\'est pas refermé'];
        yield 'guillemet simple non fermé' => ["APP_NAME='Carnet", 'n\'est pas refermé'];
        yield 'texte après le guillemet fermant' => ['APP_NAME="Carnet" suite', 'du texte suit'];
        yield 'guillemet double au milieu' => ['APP_NAME="Car"net"', 'du texte suit'];
        yield 'barre inversée qui échappe le guillemet final' => ['APP_NAME="Carnet\\"', 'n\'est pas refermé'];
    }

    #[DataProvider('invalidLines')]
    public function testAnInvalidLineIsReportedByItsNumber(string $line, string $expectedHint): void
    {
        try {
            EnvFile::parse("# commentaire\nBON=1\n" . $line . "\nAUTRE=2", '/var/www/.env');
            self::fail('Une exception était attendue.');
        } catch (ConfigException $exception) {
            self::assertStringContainsString('/var/www/.env', $exception->getMessage());
            self::assertStringContainsString('ligne 3', $exception->getMessage());
            self::assertStringContainsString($expectedHint, $exception->getMessage());
        }
    }

    /**
     * Sécurité : une ligne mal écrite contient peut-être un mot de passe.
     * Le message donne son numéro, jamais son contenu.
     */
    public function testAnErrorNeverRevealsTheContentOfALine(): void
    {
        $lines = [
            'DATABASE_PASSWORD="mot-de-passe-tres-secret',
            'mot-de-passe-tres-secret',
            'database_password=mot-de-passe-tres-secret',
            "DATABASE_PASSWORD='mot-de-passe-tres-secret' suite-secrete",
        ];

        foreach ($lines as $line) {
            try {
                EnvFile::parse($line);
                self::fail('Une exception était attendue.');
            } catch (ConfigException $exception) {
                self::assertStringNotContainsString('secret', $exception->getMessage());
            }
        }
    }

    public function testADuplicateKeyIsReportedWithBothLineNumbers(): void
    {
        try {
            EnvFile::parse("APP_NAME=Premier\nAUTRE=x\n\nAPP_NAME=Second");
            self::fail('Une exception était attendue.');
        } catch (ConfigException $exception) {
            self::assertStringContainsString('« APP_NAME »', $exception->getMessage());
            self::assertStringContainsString('lignes 1 et 4', $exception->getMessage());
            self::assertStringNotContainsString('Premier', $exception->getMessage());
            self::assertStringNotContainsString('Second', $exception->getMessage());
        }
    }

    public function testAVeryLongLineIsReadQuickly(): void
    {
        $value = str_repeat('a\\"', 80000);
        $start = hrtime(true);

        self::assertSame(['CLE' => str_repeat('a"', 80000)], EnvFile::parse('CLE="' . $value . '"'));
        self::assertLessThan(500_000_000, hrtime(true) - $start, 'La lecture doit prendre moins d\'une demi-seconde.');
    }
}
