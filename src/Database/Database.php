<?php

declare(strict_types=1);

namespace Wazi\Database;

use Wazi\Database\Exception\DatabaseException;

/**
 * Votre base de données : on lui écrit en SQL, elle répond par des tableaux.
 *
 *     $db = Database::sqlite(__DIR__ . '/var/app.sqlite');
 *
 *     // Lire
 *     $notes = $db->select('SELECT * FROM notes WHERE auteur = ? ORDER BY id DESC', [$auteur]);
 *     $note  = $db->selectOne('SELECT * FROM notes WHERE id = ?', [$id]);      // une ligne, ou null
 *     $total = $db->selectValue('SELECT COUNT(*) FROM notes');                 // une seule valeur
 *
 *     // Écrire
 *     $id = $db->insert('notes', ['auteur' => $auteur, 'texte' => $texte]);
 *     $db->update('notes', ['texte' => $texte], ['id' => $id]);
 *     $db->delete('notes', ['id' => $id]);
 *     $db->execute('UPDATE notes SET vues = vues + 1 WHERE id = ?', [$id]);    // tout autre SQL
 *
 * LA règle : une valeur ne s'écrit jamais dans le SQL. On écrit un marqueur
 * (« ? ») à sa place, et on donne la valeur à part. La base reçoit la requête
 * et les valeurs séparément : une valeur, quoi qu'elle contienne, ne peut pas
 * devenir du SQL. C'est ce qui rend l'injection SQL impossible.
 *
 *     $db->select("SELECT * FROM notes WHERE auteur = '$auteur'");     // DANGER : ne faites jamais cela
 *     $db->select('SELECT * FROM notes WHERE auteur = ?', [$auteur]);  // toujours ainsi
 *
 * Ce que cette classe règle pour vous (ADR-032) :
 *   - une erreur de la base devient une exception, qui dit quoi corriger ;
 *   - les requêtes sont réellement préparées par la base, une seule par appel ;
 *   - un nombre rangé en base revient comme un nombre, pas comme un texte ;
 *   - SQLite vérifie les liens entre tables (clés étrangères), MySQL parle utf8mb4 ;
 *   - le mot de passe n'apparaît dans aucun message, aucune trace, aucun var_dump().
 *
 * La connexion ne s'ouvre qu'à la première requête : créer cet objet ne coûte rien.
 */
final class Database
{
    public const string SQLITE = 'sqlite';
    public const string MYSQL = 'mysql';
    public const string POSTGRES = 'pgsql';

    /** Un nom de table, de colonne ou de valeur : lettres, chiffres, « _ ». */
    private const string IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D';

    /** Un hôte ou un nom de base : rien qui puisse ajouter un réglage à l'adresse de connexion. */
    private const string SETTING = '/^[^\s;=\'"\\\\\x00-\x1F\x7F]+$/D';

    /** Un protocole suivi de « :// ». Une lettre seule désigne un lecteur Windows (C:) : elle est permise. */
    private const string WRAPPER = '#^[a-zA-Z][a-zA-Z0-9+.\-]+://#';

    private const string CONTROL_CHARACTER = '/[\x00-\x1F\x7F]/';

    private const array SSL_MODES = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

    /** Le caractère qui neutralise « % » et « _ » dans un LIKE (voir likeEscape()). */
    private const string LIKE_ESCAPE = '!';

    private ?\PDO $pdo = null;

    /**
     * @param 'sqlite'|'mysql'|'pgsql' $driver
     * @param string  $dsn    l'adresse de connexion au format de PDO, sans nom d'utilisateur ni mot de passe
     */
    private function __construct(
        private readonly string $driver,
        private readonly string $dsn,
        private readonly ?string $user = null,
        #[\SensitiveParameter]
        private readonly ?string $password = null,
    ) {}

    // ------------------------------------------------------------------
    // Se connecter
    // ------------------------------------------------------------------

    /**
     * Une base SQLite : un simple fichier, rien à installer.
     *
     * Le fichier est créé à la première requête s'il n'existe pas.
     *
     * @param string $file                      le chemin complet du fichier, hors du dossier public ; « :memory: » pour une base qui disparaît à la fin du programme
     * @param bool   $unsafeAllowPublicLocation true pour accepter un fichier situé dans le dossier public (dangereux : il devient téléchargeable)
     */
    public static function sqlite(string $file, bool $unsafeAllowPublicLocation = false): self
    {
        if ($file === ':memory:') {
            return new self(self::SQLITE, 'sqlite::memory:');
        }

        // Sécurité : seulement un chemin ordinaire. « php://... » ou « phar://... » sont refusés.
        if ($file === '' || preg_match(self::WRAPPER, $file) === 1 || preg_match(self::CONTROL_CHARACTER, $file) === 1) {
            throw DatabaseException::notALocalPath();
        }

        if (!self::isAbsolute($file)) {
            throw DatabaseException::relativePath();
        }

        // Sécurité : le chemin vérifié ci-dessous doit être celui qui sera
        // ouvert. On retire donc les « .. » avant de vérifier, et c'est le
        // chemin ainsi simplifié qui sert ensuite.
        $file = self::withoutDots($file);

        if (!$unsafeAllowPublicLocation && self::isInsideDocumentRoot($file)) {
            throw DatabaseException::publiclyAccessible($file);
        }

        return new self(self::SQLITE, 'sqlite:' . $file);
    }

    public static function mysql(
        string $database,
        string $user,
        #[\SensitiveParameter]
        string $password,
        string $host = 'localhost',
        int $port = 3306,
    ): self {
        return new self(
            self::MYSQL,
            // utf8mb4 : le seul jeu de caractères de MySQL qui sait tout écrire, émojis compris.
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', self::setting('host', $host), $port, self::setting('database', $database)),
            $user,
            $password,
        );
    }

    /**
     * @param string $sslMode « require » pour exiger une connexion chiffrée (bases hébergées) ; voir la documentation de PostgreSQL
     */
    public static function postgres(
        string $database,
        string $user,
        #[\SensitiveParameter]
        string $password,
        string $host = 'localhost',
        int $port = 5432,
        string $sslMode = 'prefer',
    ): self {
        if (!in_array($sslMode, self::SSL_MODES, true)) {
            throw DatabaseException::unsupportedOption(self::POSTGRES);
        }

        return new self(
            self::POSTGRES,
            sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;options=\'--client_encoding=UTF8\'',
                self::setting('host', $host),
                $port,
                self::setting('database', $database),
                $sslMode,
            ),
            $user,
            $password,
        );
    }

    /**
     * La base décrite par une adresse : pratique pour un réglage unique, DATABASE_URL.
     *
     *     sqlite:var/app.sqlite                                 un fichier, dans le dossier du projet
     *     mysql://utilisateur:motdepasse@hote:3306/base
     *     postgres://utilisateur:motdepasse@hote:5432/base
     *
     *     $db = Database::fromUrl($config->string('DATABASE_URL', 'sqlite:var/app.sqlite'), __DIR__);
     *
     * @param string|null $directory le dossier du projet : un chemin SQLite relatif part de là
     */
    public static function fromUrl(#[\SensitiveParameter] string $url, ?string $directory = null): self
    {
        if (str_starts_with($url, 'sqlite:')) {
            $file = substr($url, 7);

            if ($file === ':memory:') {
                return self::sqlite($file);
            }

            // « sqlite://... » se lit de trop de façons différentes : on ne devine pas.
            if ($file === '' || str_starts_with($file, '//')) {
                throw DatabaseException::invalidUrl();
            }

            if (!self::isAbsolute($file)) {
                if ($directory === null || $directory === '') {
                    throw DatabaseException::relativePath();
                }

                $file = rtrim($directory, '/\\') . '/' . $file;
            }

            return self::sqlite($file);
        }

        // Un seul analyseur d'adresses dans tout Wazi : celui de PHP.
        $uri = \Uri\Rfc3986\Uri::parse($url);
        $scheme = $uri?->getScheme();
        $host = $uri?->getHost();
        // Le nom de la base est le chemin, sans sa barre : « /ma_base ».
        $database = rawurldecode(substr($uri?->getRawPath() ?? '', 1));
        $user = $uri?->getRawUsername();

        if ($uri === null || $host === null || $host === '' || $database === '' || $user === null || $user === '' || $uri->getFragment() !== null) {
            throw DatabaseException::invalidUrl();
        }

        $user = rawurldecode($user);
        $password = rawurldecode($uri->getRawPassword() ?? '');
        // Une adresse IPv6 s'écrit entre crochets dans une adresse, sans crochets pour PDO.
        $host = trim($host, '[]');
        $query = $uri->getRawQuery();

        if ($scheme === 'mysql' || $scheme === 'mariadb') {
            if ($query !== null) {
                throw DatabaseException::unsupportedOption(self::MYSQL);
            }

            return self::mysql($database, $user, $password, $host, $uri->getPort() ?? 3306);
        }

        if ($scheme === 'postgres' || $scheme === 'postgresql' || $scheme === 'pgsql') {
            $sslMode = 'prefer';

            if ($query !== null) {
                // Une seule option connue, écrite d'une seule façon.
                if (preg_match('/^sslmode=([a-z-]+)$/D', $query, $match) !== 1) {
                    throw DatabaseException::unsupportedOption(self::POSTGRES);
                }

                $sslMode = $match[1];
            }

            return self::postgres($database, $user, $password, $host, $uri->getPort() ?? 5432, $sslMode);
        }

        throw DatabaseException::invalidUrl();
    }

    /**
     * La sorte de base : Database::SQLITE, Database::MYSQL ou Database::POSTGRES.
     *
     * @return 'sqlite'|'mysql'|'pgsql'
     */
    public function driver(): string
    {
        return $this->driver;
    }

    // ------------------------------------------------------------------
    // Lire
    // ------------------------------------------------------------------

    /**
     * Toutes les lignes que rend une requête.
     *
     *     $notes = $db->select('SELECT id, texte FROM notes WHERE auteur = ?', [$auteur]);
     *     // [['id' => 1, 'texte' => '...'], ['id' => 2, 'texte' => '...']]
     *
     * @param array<int|string, mixed> $parameters les valeurs des marqueurs : une liste pour « ? », des noms pour « :nom »
     *
     * @return list<array<string, mixed>> une ligne = un tableau « colonne => valeur » ; [] si rien ne correspond
     */
    public function select(string $sql, array $parameters = []): array
    {
        $statement = $this->run($sql, $parameters);

        try {
            /** @var list<array<string, mixed>> $rows FETCH_ASSOC est imposé à la connexion */
            $rows = $statement->fetchAll();
        } catch (\PDOException $error) {
            throw DatabaseException::queryFailed($sql, $error);
        }

        return $rows;
    }

    /**
     * La première ligne que rend une requête, ou null si rien ne correspond.
     *
     *     $note = $db->selectOne('SELECT * FROM notes WHERE id = ?', [$id]);
     *
     *     if ($note === null) { ... }     // cette note n'existe pas
     *
     * @param array<int|string, mixed> $parameters
     *
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $parameters = []): ?array
    {
        return $this->select($sql, $parameters)[0] ?? null;
    }

    /**
     * Une seule valeur : la première colonne de la première ligne.
     *
     *     $total = $db->selectValue('SELECT COUNT(*) FROM notes WHERE auteur = ?', [$auteur]);
     *
     * @param array<int|string, mixed> $parameters
     *
     * @return mixed la valeur ; null si rien ne correspond
     */
    public function selectValue(string $sql, array $parameters = []): mixed
    {
        $row = $this->selectOne($sql, $parameters);

        return $row === null ? null : array_first($row);
    }

    // ------------------------------------------------------------------
    // Écrire
    // ------------------------------------------------------------------

    /**
     * Exécute une requête qui ne rend pas de lignes : UPDATE, DELETE, CREATE TABLE...
     *
     *     $changees = $db->execute('UPDATE notes SET importante = ? WHERE auteur = ?', [true, $auteur]);
     *
     * @param array<int|string, mixed> $parameters
     *
     * @return int le nombre de lignes modifiées ou supprimées
     */
    public function execute(string $sql, array $parameters = []): int
    {
        return $this->run($sql, $parameters)->rowCount();
    }

    /**
     * Ajoute une ligne à une table.
     *
     *     $id = $db->insert('notes', ['auteur' => 'alice', 'texte' => 'Acheter du pain']);
     *
     * Sécurité : les NOMS (table, colonnes) s'écrivent dans votre code. Ne
     * donnez jamais ici un tableau reçu d'un visiteur ($request->getParsedBody()) :
     * il choisirait les colonnes. Donnez les valeurs vérifiées, une par une.
     *
     * @param array<string, mixed> $values colonne => valeur
     *
     * @return int l'identifiant que la base a donné à la ligne ; 0 si la table n'en crée pas
     */
    public function insert(string $table, array $values): int
    {
        if ($values === []) {
            throw DatabaseException::emptyValues('insert');
        }

        $columns = array_map(fn(string $column): string => $this->quote($column, 'colonne'), array_keys($values));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quote($table, 'table'),
            implode(', ', $columns),
            implode(', ', array_fill(0, count($values), '?')),
        );

        // PostgreSQL ne sait dire « le dernier identifiant créé » que si la
        // table en crée un : on lui demande plutôt de rendre la ligne ajoutée.
        if ($this->driver === self::POSTGRES) {
            $id = $this->selectOne($sql . ' RETURNING *', array_values($values))['id'] ?? 0;

            return is_int($id) ? $id : 0;
        }

        $this->run($sql, array_values($values));

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Modifie les lignes qui remplissent une condition.
     *
     *     $db->update('notes', ['texte' => $texte, 'importante' => true], ['id' => $id, 'auteur' => $auteur]);
     *     // UPDATE notes SET texte = ?, importante = ? WHERE id = ? AND auteur = ?
     *
     * @param array<string, mixed> $values colonne => nouvelle valeur
     * @param array<string, mixed> $where  colonne => valeur attendue ; toutes doivent correspondre. Jamais vide.
     *
     * @return int le nombre de lignes modifiées
     */
    public function update(string $table, array $values, array $where): int
    {
        if ($values === []) {
            throw DatabaseException::emptyValues('update');
        }

        $assignments = array_map(fn(string $column): string => $this->quote($column, 'colonne') . ' = ?', array_keys($values));
        [$condition, $parameters] = $this->condition($where, 'update');

        return $this->execute(
            sprintf('UPDATE %s SET %s WHERE %s', $this->quote($table, 'table'), implode(', ', $assignments), $condition),
            [...array_values($values), ...$parameters],
        );
    }

    /**
     * Supprime les lignes qui remplissent une condition.
     *
     *     $db->delete('notes', ['id' => $id, 'auteur' => $auteur]);
     *
     * @param array<string, mixed> $where colonne => valeur attendue ; toutes doivent correspondre. Jamais vide.
     *
     * @return int le nombre de lignes supprimées
     */
    public function delete(string $table, array $where): int
    {
        [$condition, $parameters] = $this->condition($where, 'delete');

        return $this->execute(sprintf('DELETE FROM %s WHERE %s', $this->quote($table, 'table'), $condition), $parameters);
    }

    /**
     * Tout réussit, ou rien n'est gardé.
     *
     *     $db->transaction(function (Database $db) use ($de, $vers, $somme): void {
     *         $db->execute('UPDATE comptes SET solde = solde - ? WHERE id = ?', [$somme, $de]);
     *         $db->execute('UPDATE comptes SET solde = solde + ? WHERE id = ?', [$somme, $vers]);
     *     });
     *
     * Si la fonction lève une exception, tout ce qu'elle a écrit est défait,
     * et l'exception continue son chemin.
     *
     * @template T
     *
     * @param callable(self): T $work
     *
     * @return T ce que rend la fonction
     */
    public function transaction(callable $work): mixed
    {
        $pdo = $this->pdo();

        if (self::isInTransaction($pdo)) {
            throw DatabaseException::alreadyInTransaction();
        }

        try {
            $pdo->beginTransaction();
        } catch (\PDOException $error) {
            throw DatabaseException::queryFailed('BEGIN', $error);
        }

        try {
            $result = $work($this);
        } catch (\Throwable $error) {
            self::undo($pdo);

            throw $error;
        }

        try {
            // MySQL valide seul une transaction quand on change la structure
            // d'une table : il n'y a alors plus rien à valider.
            if (self::isInTransaction($pdo)) {
                $pdo->commit();
            }
        } catch (\PDOException $error) {
            self::undo($pdo);

            throw DatabaseException::queryFailed('COMMIT', $error);
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Chercher un texte
    // ------------------------------------------------------------------

    /**
     * Prépare un texte saisi par un visiteur avant de le chercher avec LIKE.
     *
     * Dans un LIKE, « % » veut dire « n'importe quoi » et « _ » « n'importe
     * quel caractère ». Un visiteur qui cherche « 100% » ne veut pas dire cela.
     *
     *     $db->select(
     *         "SELECT * FROM notes WHERE texte LIKE ? ESCAPE '!'",
     *         ['%' . Database::likeEscape($recherche) . '%'],
     *     );
     *
     * Le « ESCAPE '!' » de la requête est indispensable : il dit à la base que
     * « ! » neutralise le caractère qui le suit.
     */
    public static function likeEscape(string $text): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $text,
        );
    }

    /**
     * Sécurité : var_dump() et les outils de débogage ne montrent ni
     * l'adresse de connexion, ni le nom d'utilisateur, ni le mot de passe.
     *
     * @return array{driver: string, connected: bool}
     */
    public function __debugInfo(): array
    {
        return ['driver' => $this->driver, 'connected' => $this->pdo !== null];
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * Prépare une requête, lui lie ses valeurs, et l'exécute.
     *
     * @param array<int|string, mixed> $parameters
     */
    private function run(string $sql, array $parameters): \PDOStatement
    {
        if (trim($sql) === '') {
            throw DatabaseException::emptyQuery();
        }

        // Une seule requête par appel. MySQL et PostgreSQL le refusent
        // d'eux-mêmes ; SQLite ignorerait la suite sans rien dire.
        if (str_contains($sql, ';') && count(SqlSplitter::split($sql, $this->driver === self::MYSQL)) > 1) {
            throw DatabaseException::severalStatements();
        }

        // Les valeurs sont vérifiées avant de joindre la base : une erreur
        // d'écriture se signale même si la base est injoignable.
        $positional = array_is_list($parameters);
        $bindings = [];

        foreach ($parameters as $key => $value) {
            if ($positional !== is_int($key)) {
                throw DatabaseException::mixedParameters();
            }

            // Les marqueurs « ? » se comptent à partir de 1.
            $bindings[] = [is_int($key) ? $key + 1 : self::parameterName($key), ...$this->bindable($key, $value)];
        }

        if ($this->driver === self::SQLITE) {
            self::requireEveryValue($sql, $parameters, $positional);
        }

        try {
            $statement = $this->pdo()->prepare($sql);

            foreach ($bindings as [$marker, $bound, $type]) {
                $statement->bindValue($marker, $bound, $type);
            }

            $statement->execute();
        } catch (\PDOException $error) {
            throw DatabaseException::queryFailed($sql, $error);
        }

        return $statement;
    }

    /**
     * Vérifie que chaque marqueur de la requête reçoit une valeur.
     *
     * MySQL et PostgreSQL refusent une requête à qui il manque une valeur.
     * SQLite, lui, met null à la place sans rien dire : une faute de frappe
     * dans un nom deviendrait une recherche qui ne trouve jamais rien.
     *
     * @param array<int|string, mixed> $parameters
     */
    private static function requireEveryValue(string $sql, array $parameters, bool $positional): void
    {
        if (!str_contains($sql, '?') && !str_contains($sql, ':')) {
            return;
        }

        $bare = SqlSplitter::bare($sql);

        // « ?3 » est un marqueur numéroté, propre à SQLite : on ne le compte pas.
        if (preg_match('/\?\d/', $bare) === 1) {
            return;
        }

        $expected = substr_count($bare, '?');
        $given = count($parameters);

        if ($expected === 0) {
            // Des marqueurs « :nom ». Le même nom peut servir plusieurs fois.
            preg_match_all('/(?<![:\w]):([A-Za-z_][A-Za-z0-9_]*)/', $bare, $matches);
            $names = array_unique($matches[1]);
            $expected = count($names);

            if (!$positional) {
                $given = count(array_intersect($names, array_map(static fn(int|string $key): string => ltrim((string) $key, ':'), array_keys($parameters))));
            }
        }

        if ($given < $expected) {
            throw DatabaseException::missingValues($expected, count($parameters));
        }
    }

    /**
     * Ouvre la connexion, à la première requête seulement.
     */
    private function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if (!extension_loaded('pdo_' . $this->driver)) {
            throw DatabaseException::missingDriver($this->driver);
        }

        if ($this->driver === self::SQLITE && $this->dsn !== 'sqlite::memory:') {
            self::prepareDirectory(dirname(substr($this->dsn, 7)));
        }

        $options = [
            // Une erreur de la base devient une exception : elle ne passe jamais inaperçue.
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            // Une ligne = un tableau « colonne => valeur ». Aucun objet n'est
            // fabriqué à partir de ce que contient la base.
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            // Sécurité : la requête est préparée PAR LA BASE, qui reçoit les
            // valeurs à part. PDO sait aussi « simuler » en collant lui-même les
            // valeurs dans le SQL : on ne lui laisse pas faire.
            \PDO::ATTR_EMULATE_PREPARES => false,
            // Un nombre revient comme un nombre.
            \PDO::ATTR_STRINGIFY_FETCHES => false,
            \PDO::ATTR_PERSISTENT => false,
            // Secondes : pour joindre la base (MySQL, PostgreSQL), ou pour
            // attendre qu'une autre requête libère le fichier (SQLite).
            \PDO::ATTR_TIMEOUT => 5,
        ];

        if ($this->driver === self::MYSQL) {
            // Sécurité : jamais plusieurs requêtes dans un même envoi.
            $options[\Pdo\Mysql::ATTR_MULTI_STATEMENTS] = false;
        }

        try {
            $pdo = new \PDO($this->dsn, $this->user, $this->password, $options);

            if ($this->driver === self::SQLITE) {
                // SQLite ignore les clés étrangères tant qu'on ne le lui demande pas.
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
        } catch (\PDOException $error) {
            // L'erreur d'origine n'est pas chaînée : sa trace est celle de l'ouverture de la connexion.
            throw DatabaseException::connectionFailed($this->driver, $error);
        }

        return $this->pdo = $pdo;
    }

    /**
     * Une valeur, et le type sous lequel la donner à la base.
     *
     * @return array{int|string|bool|null, int}
     */
    private function bindable(int|string $key, mixed $value): array
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return match (true) {
            $value === null => [null, \PDO::PARAM_NULL],
            is_int($value) => [$value, \PDO::PARAM_INT],
            // PostgreSQL a un vrai type booléen. SQLite et MySQL gardent 0 ou 1 :
            // on leur donne un entier, pour que « false » ne devienne jamais un texte vide.
            is_bool($value) => $this->driver === self::POSTGRES ? [$value, \PDO::PARAM_BOOL] : [(int) $value, \PDO::PARAM_INT],
            is_string($value) => [$value, \PDO::PARAM_STR],
            // PDO n'a pas de type « nombre à virgule » : il part en texte, et la base le relit.
            is_float($value) && is_finite($value) => [(string) $value, \PDO::PARAM_STR],
            $value instanceof \DateTimeInterface => [$value->format('Y-m-d H:i:s'), \PDO::PARAM_STR],
            default => throw DatabaseException::invalidParameter($key, $value),
        };
    }

    /**
     * Le nom d'une valeur, tel que PDO l'attend : « :id ».
     */
    private static function parameterName(string $key): string
    {
        $name = ltrim($key, ':');

        if (preg_match(self::IDENTIFIER, $name) !== 1) {
            throw DatabaseException::invalidParameterName();
        }

        return ':' . $name;
    }

    /**
     * Un nom de table ou de colonne, vérifié puis écrit entre guillemets.
     *
     * Sécurité : une requête préparée protège les valeurs, pas les noms. Un
     * nom ne passe donc que s'il a la forme d'un nom ; les guillemets évitent
     * en plus qu'il soit pris pour un mot du langage (« order », « user »).
     */
    private function quote(string $name, string $what): string
    {
        if (preg_match(self::IDENTIFIER, $name) !== 1) {
            throw DatabaseException::invalidIdentifier($what);
        }

        return $this->driver === self::MYSQL ? '`' . $name . '`' : '"' . $name . '"';
    }

    /**
     * La condition d'un update() ou d'un delete() : « a = ? AND b IS NULL ».
     *
     * @param array<string, mixed> $where
     *
     * @return array{string, list<mixed>} le SQL de la condition, et ses valeurs
     */
    private function condition(array $where, string $method): array
    {
        // Sécurité : sans condition, toute la table serait touchée.
        if ($where === []) {
            throw DatabaseException::emptyCondition($method);
        }

        $parts = [];
        $parameters = [];

        foreach ($where as $column => $value) {
            $column = $this->quote($column, 'colonne');

            // En SQL, « = NULL » n'est jamais vrai : l'absence se teste par IS NULL.
            if ($value === null) {
                $parts[] = $column . ' IS NULL';

                continue;
            }

            $parts[] = $column . ' = ?';
            $parameters[] = $value;
        }

        return [implode(' AND ', $parts), $parameters];
    }

    /**
     * La réponse change d'un appel à l'autre : une transaction s'ouvre, se
     * valide, ou est validée par la base elle-même (MySQL).
     *
     * @phpstan-impure
     */
    private static function isInTransaction(\PDO $pdo): bool
    {
        return $pdo->inTransaction();
    }

    private static function undo(\PDO $pdo): void
    {
        try {
            if (self::isInTransaction($pdo)) {
                $pdo->rollBack();
            }
        } catch (\PDOException) {
            // La connexion est perdue : la base défait d'elle-même ce qui n'a pas été validé.
        }
    }

    private static function setting(string $name, string $value): string
    {
        if (preg_match(self::SETTING, $value) !== 1) {
            throw DatabaseException::invalidSetting($name);
        }

        return $value;
    }

    private static function isAbsolute(string $path): bool
    {
        return preg_match('#^(?:[/\\\\]|[A-Za-z]:[/\\\\])#', $path) === 1;
    }

    /**
     * Le même chemin, sans ses « . » ni ses « .. » : « /site/var/../public/x » devient « /site/public/x ».
     *
     * Le calcul se fait sur le texte, sans regarder le disque : un chemin qui
     * passe par un dossier pas encore créé ne peut pas tromper la vérification.
     */
    private static function withoutDots(string $path): string
    {
        // Ce qui précède le premier nom : « / », « C:\ », ou « \\ » pour un partage réseau.
        preg_match('#^(?:[A-Za-z]:[/\\\\]|[/\\\\]{1,2})#', $path, $match);
        $root = $match[0] ?? '';
        $segments = [];

        foreach (preg_split('#[/\\\\]+#', substr($path, strlen($root)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }

        return $root . implode('/', $segments);
    }

    /**
     * Le dossier du fichier SQLite doit exister : on le crée, fermé aux autres comptes de la machine.
     */
    private static function prepareDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw DatabaseException::directoryNotWritable($directory);
        }
    }

    /**
     * Vrai si ce fichier se trouve dans le dossier que le serveur web publie.
     */
    private static function isInsideDocumentRoot(string $file): bool
    {
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';

        if (!is_string($documentRoot) || $documentRoot === '') {
            return false;
        }

        // Le fichier n'existe peut-être pas encore, ni son dossier : on
        // remonte jusqu'au premier dossier qui existe.
        $directory = dirname($file);

        while (!is_dir($directory) && dirname($directory) !== $directory) {
            $directory = dirname($directory);
        }

        // realpath() donne le chemin réel, sans « .. » ni lien symbolique.
        $realDirectory = realpath($directory);
        $realRoot = realpath($documentRoot);

        return $realDirectory !== false
            && $realRoot !== false
            && str_starts_with($realDirectory . DIRECTORY_SEPARATOR, rtrim($realRoot, '/\\') . DIRECTORY_SEPARATOR);
    }
}
