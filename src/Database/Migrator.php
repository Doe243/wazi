<?php

declare(strict_types=1);

namespace Wazi\Database;

use Wazi\Database\Exception\DatabaseException;

/**
 * Applique les migrations : les fichiers SQL qui construisent votre base, pas à pas.
 *
 *     migrations/
 *         20261004_153000_creer_notes.sql
 *         20261012_091500_ajouter_couleur_aux_notes.sql
 *
 * Une migration est un changement de la STRUCTURE de la base : créer une
 * table, ajouter une colonne. Chaque fichier est appliqué une seule fois, dans
 * l'ordre de son nom (qui commence par sa date). La table « wazi_migrations »
 * retient ceux qui sont faits.
 *
 * L'idée à retenir : la base est le résultat de ces fichiers, dans l'ordre.
 * Sur votre ordinateur, sur celui d'un collègue, sur le serveur : la même
 * commande (« wazi db:migrate ») mène à la même base.
 *
 * Deux règles :
 *   - on avance seulement : pour défaire un changement, on écrit une nouvelle migration ;
 *   - on ne modifie pas une migration déjà appliquée : elle ne serait pas rejouée.
 *
 * Sécurité (ADR-032) : cette classe ne sert qu'à la console. Rien dans Wazi ne
 * l'appelle pendant une requête HTTP.
 */
final readonly class Migrator
{
    public const string TABLE = 'wazi_migrations';

    public const string APPLIED = 'faite';
    public const string PENDING = 'à faire';
    public const string MODIFIED = 'modifiée depuis';
    public const string MISSING = 'fichier disparu';

    /** 20261004_153000_creer_notes.sql : la date, l'heure, puis ce que fait la migration. */
    public const string FILE_NAME = '/^\d{8}_\d{6}_[a-z0-9]+(?:_[a-z0-9]+)*\.sql$/D';

    /** Au-delà, ce n'est plus un changement de structure. */
    private const int MAX_SIZE = 1_048_576;

    /**
     * @param string $directory le dossier des fichiers de migration
     */
    public function __construct(private Database $database, private string $directory) {}

    /**
     * Applique, dans l'ordre, les migrations qui ne l'ont pas encore été.
     *
     * @param (callable(string): void)|null $onApplied appelée après chaque migration réussie, avec son nom
     *
     * @return list<string> les noms des migrations appliquées par cet appel
     */
    public function migrate(?callable $onApplied = null): array
    {
        $applied = $this->applied();
        $done = [];

        foreach ($this->files() as $name) {
            if (isset($applied[$name])) {
                continue;
            }

            $this->apply($name);
            $done[] = $name;

            if ($onApplied !== null) {
                $onApplied($name);
            }
        }

        return $done;
    }

    /**
     * Où en est chaque migration.
     *
     * @return list<array{name: string, state: self::APPLIED|self::PENDING|self::MODIFIED|self::MISSING, appliedAt: ?string}>
     */
    public function status(): array
    {
        $applied = $this->applied();
        $status = [];

        foreach ($this->files() as $name) {
            $record = $applied[$name] ?? null;
            unset($applied[$name]);

            $status[] = [
                'name' => $name,
                'state' => match (true) {
                    $record === null => self::PENDING,
                    $record['checksum'] !== self::checksum($this->read($name)) => self::MODIFIED,
                    default => self::APPLIED,
                },
                'appliedAt' => $record['appliedAt'] ?? null,
            ];
        }

        // Ce qui reste a été appliqué un jour, mais son fichier n'est plus là.
        foreach ($applied as $name => $record) {
            $status[] = ['name' => $name, 'state' => self::MISSING, 'appliedAt' => $record['appliedAt']];
        }

        usort($status, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $status;
    }

    /**
     * Les fichiers de migration, dans l'ordre où ils s'appliquent.
     *
     * @return list<string>
     */
    private function files(): array
    {
        if (!is_dir($this->directory)) {
            throw DatabaseException::migrationsDirectoryMissing($this->directory);
        }

        $names = [];

        foreach (scandir($this->directory) ?: [] as $file) {
            // Seuls les fichiers .sql sont des migrations : un LISEZ-MOI peut vivre à côté.
            if (!str_ends_with($file, '.sql') || !is_file($this->directory . '/' . $file)) {
                continue;
            }

            // Un fichier .sql mal nommé n'est pas ignoré en silence : on
            // croirait la migration faite.
            if (preg_match(self::FILE_NAME, $file) !== 1) {
                throw DatabaseException::invalidMigrationName($file);
            }

            $names[] = $file;
        }

        // L'ordre des noms est l'ordre des dates, puisque chaque nom commence par la sienne.
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Les migrations déjà appliquées, d'après la base.
     *
     * @return array<string, array{checksum: string, appliedAt: string}>
     */
    private function applied(): array
    {
        // IF NOT EXISTS : la table est créée au premier appel, et retrouvée ensuite.
        $this->database->execute(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE
            . ' (name VARCHAR(190) NOT NULL PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at VARCHAR(19) NOT NULL)',
        );

        $applied = [];

        foreach ($this->database->select('SELECT name, checksum, applied_at FROM ' . self::TABLE) as $row) {
            if (is_string($row['name']) && is_string($row['checksum']) && is_string($row['applied_at'])) {
                // CHAR(64) : certaines bases complètent par des espaces.
                $applied[$row['name']] = ['checksum' => trim($row['checksum']), 'appliedAt' => $row['applied_at']];
            }
        }

        return $applied;
    }

    private function apply(string $name): void
    {
        $content = $this->read($name);
        $driver = $this->database->driver();
        $statements = SqlSplitter::split($content, $driver === Database::MYSQL);

        if ($statements === []) {
            throw DatabaseException::emptyMigration($name);
        }

        $run = function () use ($name, $content, $statements): void {
            foreach ($statements as $index => $statement) {
                try {
                    $this->database->execute($statement);
                } catch (DatabaseException $error) {
                    throw DatabaseException::migrationFailed(
                        $name,
                        $index + 1,
                        count($statements),
                        $this->database->driver() !== Database::MYSQL,
                        $error,
                    );
                }
            }

            $this->database->insert(self::TABLE, [
                'name' => $name,
                'checksum' => self::checksum($content),
                'applied_at' => date('Y-m-d H:i:s'),
            ]);
        };

        // SQLite et PostgreSQL savent défaire un changement de structure : une
        // migration qui échoue ne laisse aucune trace. MySQL valide chaque
        // changement aussitôt : une transaction n'y changerait rien.
        if ($driver === Database::MYSQL) {
            $run();

            return;
        }

        $this->database->transaction($run);
    }

    /**
     * L'empreinte d'un fichier : elle change dès que son contenu change.
     *
     * Les fins de ligne ne comptent pas : Git les convertit parfois d'un
     * ordinateur à l'autre, sans que le SQL ait changé.
     */
    private static function checksum(string $content): string
    {
        return hash('sha256', str_replace("\r\n", "\n", $content));
    }

    private function read(string $name): string
    {
        $file = $this->directory . '/' . $name;
        $size = @filesize($file);

        if ($size === false || $size > self::MAX_SIZE) {
            throw DatabaseException::unreadableMigration($name);
        }

        $content = @file_get_contents($file);

        if ($content === false) {
            throw DatabaseException::unreadableMigration($name);
        }

        return $content;
    }
}
