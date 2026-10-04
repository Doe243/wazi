<?php

declare(strict_types=1);

namespace Wazi\Database\Exception;

/**
 * Levée quand la base de données ne peut pas être jointe, refuse une requête,
 * ou quand Wazi refuse ce qu'on lui demande d'y faire.
 *
 * Sécurité (ADR-032) :
 *   - aucun message ne contient le mot de passe de la base, ni son adresse de
 *     connexion complète ;
 *   - la requête citée est celle que vous avez écrite : elle contient des
 *     marqueurs (« ? »), jamais les valeurs ;
 *   - la réponse de la base, qui cite parfois une valeur, est débarrassée de
 *     ses caractères de contrôle et raccourcie avant d'être citée.
 */
final class DatabaseException extends \RuntimeException
{
    private const int SQL_PREVIEW = 300;

    private const int ANSWER_PREVIEW = 300;

    /**
     * @param string $sqlState  le code à cinq caractères donné par la base (« 23505 »), ou '' s'il n'y en a pas
     * @param bool   $duplicate vrai si la base a refusé une valeur déjà prise
     */
    private function __construct(
        string $message,
        public readonly string $sqlState = '',
        private readonly bool $duplicate = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Vrai si la base a refusé la requête parce qu'une valeur qui doit être
     * unique existe déjà (une adresse e-mail déjà inscrite, par exemple).
     *
     *     try {
     *         $db->insert('comptes', ['email' => $email]);
     *     } catch (DatabaseException $erreur) {
     *         if (!$erreur->isDuplicate()) {
     *             throw $erreur;
     *         }
     *         // Dire au visiteur que cette adresse est déjà utilisée.
     *     }
     */
    public function isDuplicate(): bool
    {
        return $this->duplicate;
    }

    // ------------------------------------------------------------------
    // Se connecter
    // ------------------------------------------------------------------

    public static function missingDriver(string $driver): self
    {
        return new self(sprintf(
            'PHP ne sait pas parler à cette base de données : l\'extension « pdo_%1$s » n\'est pas chargée.'
            . ' Activez-la dans php.ini (retirez le « ; » devant la ligne « extension=pdo_%1$s », ou installez'
            . ' le paquet PHP correspondant sous Linux), puis relancez le serveur. Pour voir les extensions'
            . ' chargées : php -m',
            $driver,
        ));
    }

    public static function connectionFailed(string $driver, \PDOException $error): self
    {
        return new self(
            sprintf(
                'La connexion à la base de données (%s) a échoué. Réponse de la base : « %s ». Vérifiez que la base'
                . ' est démarrée, puis le nom de la base, le nom d\'utilisateur, le mot de passe, l\'hôte et le port'
                . ' de votre configuration.',
                $driver,
                self::preview($error->getMessage(), self::ANSWER_PREVIEW),
            ),
            self::stateOf($error),
        );
    }

    public static function invalidUrl(): self
    {
        return new self(
            'L\'adresse de la base de données est mal écrite. Trois formes sont acceptées :'
            . ' « sqlite:var/app.sqlite », « mysql://utilisateur:motdepasse@hote:3306/base » et'
            . ' « postgres://utilisateur:motdepasse@hote:5432/base ». Dans le nom d\'utilisateur et le mot de passe,'
            . ' les caractères « @ », « : », « / », « ? », « # » et « % » s\'écrivent encodés (%40 pour « @ »).'
            . ' L\'adresse n\'est pas citée ici : elle contient un mot de passe.',
        );
    }

    public static function unsupportedOption(string $driver): self
    {
        return new self(sprintf(
            'L\'adresse de la base de données (%s) contient une option que Wazi ne connaît pas après le « ? ».'
            . ' Seule « sslmode » est acceptée, pour PostgreSQL : « ?sslmode=require ». Valeurs permises :'
            . ' disable, allow, prefer, require, verify-ca, verify-full.',
            $driver,
        ));
    }

    public static function invalidSetting(string $setting): self
    {
        return new self(sprintf(
            'Le réglage « %s » de la base de données contient un caractère qui n\'y a pas sa place (espace, « ; »,'
            . ' « = », guillemet ou caractère de contrôle), ou il est vide. Vérifiez votre configuration.',
            $setting,
        ));
    }

    public static function notALocalPath(): self
    {
        return new self(
            'Le fichier de la base SQLite doit être un chemin de fichier ordinaire. Une adresse à protocole'
            . ' (« php:// », « phar:// », « http:// »...) est refusée : elle permettrait de lire ou d\'exécuter'
            . ' autre chose qu\'un fichier de base. Exemple : Database::sqlite(__DIR__ . \'/var/app.sqlite\').',
        );
    }

    public static function relativePath(): self
    {
        return new self(
            'Le chemin de la base SQLite est relatif, et Wazi ne sait pas par rapport à quel dossier : le site et'
            . ' la console ne démarrent pas dans le même. Donnez le dossier du projet en second argument :'
            . ' Database::fromUrl($url, __DIR__). Ou écrivez un chemin complet.',
        );
    }

    public static function publiclyAccessible(string $file): self
    {
        return new self(sprintf(
            'Le fichier de la base SQLite, %s, est dans le dossier public du site : n\'importe qui pourrait le'
            . ' télécharger en tapant son adresse, avec toutes vos données. Rangez-le hors du dossier public,'
            . ' dans « var/ » par exemple.',
            $file,
        ));
    }

    public static function directoryNotWritable(string $directory): self
    {
        return new self(sprintf(
            'Le dossier %s, où doit se trouver la base SQLite, n\'existe pas et n\'a pas pu être créé.'
            . ' Créez-le, et vérifiez que PHP a le droit d\'y écrire.',
            $directory,
        ));
    }

    // ------------------------------------------------------------------
    // Interroger
    // ------------------------------------------------------------------

    public static function queryFailed(string $sql, \PDOException $error): self
    {
        $state = self::stateOf($error);
        $answer = $error->getMessage();
        $duplicate = self::isDuplicateError($error, $state);

        $advice = match (true) {
            $duplicate => 'Une valeur qui doit être unique existe déjà. Pour le dire au visiteur plutôt que'
                . ' d\'afficher une erreur, attrapez cette exception et testez isDuplicate().',
            self::isMissingTable($answer, $state) => 'Cette table n\'existe pas (encore). Avez-vous lancé'
                . ' « wazi db:migrate » ? Sinon, vérifiez l\'orthographe du nom de la table.',
            $state === 'HY093' || str_contains($answer, 'parameter') => 'Donnez exactement une valeur par'
                . ' marqueur (« ? » ou « :nom ») de la requête.',
            str_starts_with($state, '42') || str_contains($answer, 'syntax') => 'Relisez le SQL : un mot-clé mal'
                . ' écrit, une virgule en trop, un nom de colonne qui n\'existe pas.',
            str_starts_with($state, '23') => 'La requête ne respecte pas une règle de la table : valeur'
                . ' obligatoire absente, ou lien vers une ligne qui n\'existe pas.',
            default => 'Relisez la requête et la réponse de la base.',
        };

        return new self(
            sprintf(
                'La base de données a refusé cette requête : %s — Réponse de la base : « %s ». %s',
                self::preview($sql, self::SQL_PREVIEW),
                self::preview($answer, self::ANSWER_PREVIEW),
                $advice,
            ),
            $state,
            $duplicate,
            $error,
        );
    }

    public static function severalStatements(): self
    {
        return new self(
            'Cette requête en contient plusieurs, séparées par « ; ». Wazi n\'exécute qu\'une requête par appel :'
            . ' c\'est ce qui empêche une valeur piégée d\'en ajouter une. Faites un appel par requête ; pour'
            . ' qu\'elles réussissent ou échouent ensemble, placez-les dans $db->transaction(...).',
        );
    }

    public static function emptyQuery(): self
    {
        return new self('La requête SQL est vide. Écrivez-la en premier argument : $db->select(\'SELECT * FROM notes\').');
    }

    public static function mixedParameters(): self
    {
        return new self(
            'Les valeurs de cette requête mélangent deux écritures. Choisissez-en une : une liste, pour des'
            . ' marqueurs « ? » ($db->select(\'... WHERE id = ?\', [3])), ou des noms, pour des marqueurs « :nom »'
            . ' ($db->select(\'... WHERE id = :id\', [\'id\' => 3])).',
        );
    }

    public static function missingValues(int $expected, int $given): self
    {
        return new self(sprintf(
            'Cette requête attend %d valeur(s) et en reçoit %d. Donnez exactement une valeur par marqueur (« ? » ou'
            . ' « :nom ») ; pour des marqueurs « :nom », vérifiez que chaque nom du tableau est écrit comme dans la requête.',
            $expected,
            $given,
        ));
    }

    public static function invalidParameterName(): self
    {
        return new self(
            'Le nom d\'une valeur de cette requête est invalide. Un nom ne contient que des lettres, des chiffres'
            . ' et « _ », et correspond à un marqueur « :nom » de la requête : [\'id\' => 3] pour « :id ».',
        );
    }

    public static function invalidParameter(int|string $key, mixed $value): self
    {
        return new self(sprintf(
            'La valeur %s de cette requête est de type %s : une base de données ne sait pas la garder telle quelle.'
            . ' Sont acceptés : texte, nombre, booléen, null, date (DateTimeInterface) et énumération à valeur.'
            . ' Pour une liste (IN), écrivez un marqueur par élément ; pour un tableau, gardez-le en JSON avec json_encode().',
            is_int($key) ? 'n° ' . ($key + 1) : '« ' . self::preview($key, 40) . ' »',
            get_debug_type($value),
        ));
    }

    public static function invalidIdentifier(string $what): self
    {
        return new self(sprintf(
            'Ce nom de %s est invalide. Un nom ne contient que des lettres sans accent, des chiffres et « _ », ne'
            . ' commence pas par un chiffre et fait 63 caractères au plus : notes, date_creation. Sécurité : un nom'
            . ' ne doit jamais venir d\'un visiteur sans être comparé à une liste de noms permis.',
            $what,
        ));
    }

    public static function emptyValues(string $method): self
    {
        return new self(sprintf(
            '%s() a reçu un tableau de valeurs vide. Donnez au moins une colonne : [\'texte\' => $texte].',
            $method,
        ));
    }

    public static function emptyCondition(string $method): self
    {
        return new self(sprintf(
            '%1$s() a reçu une condition vide : toutes les lignes de la table seraient touchées. Wazi le refuse,'
            . ' pour qu\'un oubli ne vide pas une table. Donnez une condition ([\'id\' => $id]). Si vous voulez'
            . ' vraiment toucher toutes les lignes, écrivez-le en SQL avec execute().',
            $method,
        ));
    }

    public static function alreadyInTransaction(): self
    {
        return new self(
            'transaction() a été appelée à l\'intérieur d\'une autre transaction. Une seule suffit : tout ce qui'
            . ' s\'exécute dans la première réussit ou échoue ensemble. Retirez l\'appel intérieur.',
        );
    }

    // ------------------------------------------------------------------
    // Migrations
    // ------------------------------------------------------------------

    public static function migrationsDirectoryMissing(string $directory): self
    {
        return new self(sprintf(
            'Le dossier des migrations, %s, n\'existe pas. Créez votre première migration avec'
            . ' « wazi make:migration creer_notes » : le dossier sera créé avec elle.',
            $directory,
        ));
    }

    public static function invalidMigrationName(string $file): self
    {
        return new self(sprintf(
            'Le fichier « %s » du dossier des migrations n\'a pas un nom de migration. Un nom commence par la date'
            . ' et l\'heure, puis décrit le changement en minuscules : 20261004_153000_creer_notes.sql. Créez vos'
            . ' migrations avec « wazi make:migration creer_notes » : le nom est fabriqué pour vous.',
            self::preview($file, 80),
        ));
    }

    public static function unreadableMigration(string $name): self
    {
        return new self(sprintf(
            'La migration « %s » n\'a pas pu être lue, ou elle dépasse 1 Mo. Vérifiez que le fichier existe et que'
            . ' PHP a le droit de le lire.',
            $name,
        ));
    }

    public static function emptyMigration(string $name): self
    {
        return new self(sprintf(
            'La migration « %s » ne contient aucune requête : seulement des commentaires, ou rien. Écrivez-y le'
            . ' SQL du changement (CREATE TABLE..., ALTER TABLE...), ou supprimez le fichier.',
            $name,
        ));
    }

    public static function migrationFailed(string $name, int $statement, int $total, bool $undone, self $error): self
    {
        return new self(
            sprintf(
                'La migration « %s » a échoué à sa requête n° %d sur %d. %s %s',
                $name,
                $statement,
                $total,
                $undone
                    ? 'Rien n\'a été gardé : la base est dans l\'état d\'avant cette migration. Corrigez le fichier, puis relancez « wazi db:migrate ».'
                    : 'Attention : MySQL valide chaque changement de structure aussitôt. Les requêtes précédentes'
                        . ' de ce fichier sont donc appliquées, et la migration n\'est pas notée comme faite.'
                        . ' Défaites-les à la main (ou retirez-les du fichier), corrigez, puis relancez « wazi db:migrate ».',
                $error->getMessage(),
            ),
            $error->sqlState,
            false,
            $error,
        );
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * Le code à cinq caractères donné par la base, s'il a bien cette forme.
     */
    private static function stateOf(\PDOException $error): string
    {
        $state = $error->errorInfo[0] ?? $error->getCode();

        return is_string($state) && preg_match('/^[0-9A-Z]{5}$/D', $state) === 1 ? $state : '';
    }

    /**
     * Chaque base a sa façon de dire « cette valeur existe déjà ».
     */
    private static function isDuplicateError(\PDOException $error, string $state): bool
    {
        $code = $error->errorInfo[1] ?? null;

        return $state === '23505'                                                            // PostgreSQL
            || ($state === '23000' && ($code === 1062 || $code === 1586))                    // MySQL
            || ($code === 19 && str_contains($error->getMessage(), 'UNIQUE constraint'));    // SQLite
    }

    private static function isMissingTable(string $answer, string $state): bool
    {
        return $state === '42P01' || $state === '42S02' || str_contains($answer, 'no such table');
    }

    /**
     * Sécurité : ni caractère de contrôle (falsification de journaux), ni texte interminable.
     */
    private static function preview(string $text, int $max): string
    {
        $clean = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '');

        return mb_strlen($clean) > $max ? mb_substr($clean, 0, $max - 3) . '...' : $clean;
    }
}
