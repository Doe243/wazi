<?php

declare(strict_types=1);

namespace Wazi\Config;

use Wazi\Config\Exception\ConfigException;

/**
 * Les réglages de votre application, lus dans un fichier .env ou dans les
 * variables d'environnement du serveur.
 *
 *     $config = Config::fromEnvFile(__DIR__ . '/../.env');
 *
 *     $config->string('APP_NAME', 'Mon site');   // un texte, avec une valeur par défaut
 *     $config->int('PAGINATION', 20);            // un nombre entier
 *     $config->bool('APP_DEBUG', false);         // true ou false
 *     $config->list('APP_HOSTS', []);            // « a.com, b.com » devient ['a.com', 'b.com']
 *     $config->string('DATABASE_PASSWORD');      // sans valeur par défaut : la clé est obligatoire
 *
 * Dans un .env, tout est du texte. Ces quatre méthodes disent quel type vous
 * attendez, et le vérifient : « PAGINATION=vingt » donne une erreur claire
 * plutôt qu'un zéro silencieux.
 *
 * Un fichier absent n'est pas une erreur : l'application démarre avec ses
 * valeurs par défaut. Seule une clé obligatoire manquante en est une.
 *
 * D'où vient une valeur ? Pour chaque clé, dans cet ordre :
 *   1. une variable d'environnement de ce nom, si le serveur en définit une
 *      (c'est ainsi qu'on configure un site hébergé dans un conteneur ou sur
 *      une plateforme : il n'y a alors aucun fichier .env en ligne) ;
 *   2. sinon, la ligne du fichier .env ;
 *   3. sinon, la valeur par défaut donnée dans le code.
 *
 * ⚠ Le système définit lui-même des variables (PATH, USER, HOME, LANG...).
 * Nommez vos clés avec un préfixe (APP_, DATABASE_, MAIL_) pour qu'aucune ne
 * porte par hasard le nom de l'une d'elles.
 *
 * Sécurité (ADR-006, ADR-017 et ADR-018) :
 *   - les valeurs du fichier restent DANS cet objet. Elles ne sont jamais
 *     copiées dans $_ENV, $_SERVER ou putenv(), où phpinfo() ou un programme
 *     lancé par PHP pourraient les lire : l'environnement est lu, jamais écrit ;
 *   - une clé ne peut pas commencer par « HTTP_ » : sur certains serveurs, ces
 *     variables sont fabriquées à partir des en-têtes de la requête, donc
 *     choisies par le visiteur ;
 *   - un .env placé dans le dossier public du site est refusé : il serait
 *     téléchargeable par n'importe qui ;
 *   - var_dump($config) montre le nom des clés, pas leurs valeurs ;
 *   - aucun message d'erreur ne contient une valeur.
 */
final readonly class Config
{
    /** Une adresse à protocole (« php://… ») : jamais un chemin de fichier. */
    private const string WRAPPER = '#^[a-zA-Z][a-zA-Z0-9+.\-]+://#';

    private const string CONTROL_CHARACTER = '/[\x00-\x1F\x7F]/';

    private const string INTEGER = '/^-?(?:0|[1-9]\d{0,17})$/D';

    /**
     * @param array<string, string> $values          les réglages : nom en majuscules => texte
     * @param string                $file            le fichier d'où ils viennent, cité dans les messages d'erreur
     * @param bool                  $fileExists      false si ce fichier n'existait pas
     * @param bool                  $readEnvironment true pour que les variables d'environnement du serveur passent avant ces réglages
     */
    public function __construct(
        #[\SensitiveParameter]
        private array $values = [],
        private string $file = '.env',
        private bool $fileExists = true,
        private bool $readEnvironment = false,
    ) {}

    /**
     * Lit un fichier .env. S'il n'existe pas, seules les variables
     * d'environnement du serveur et les valeurs par défaut serviront.
     *
     * @param bool $unsafeAllowPublicLocation true pour accepter un .env situé dans le dossier public (dangereux, voir la classe)
     *
     * @throws ConfigException si le fichier est mal écrit, illisible, ou placé dans le dossier public du site
     */
    public static function fromEnvFile(string $file, bool $unsafeAllowPublicLocation = false): self
    {
        if ($file === '' || preg_match(self::WRAPPER, $file) === 1 || preg_match(self::CONTROL_CHARACTER, $file) === 1) {
            throw ConfigException::notALocalPath();
        }

        if (!file_exists($file)) {
            return new self([], $file, false, true);
        }

        if (!$unsafeAllowPublicLocation && self::isInsideDocumentRoot($file)) {
            throw ConfigException::publiclyAccessible($file);
        }

        if (!is_file($file) || !is_readable($file) || filesize($file) > EnvFile::MAX_SIZE) {
            throw ConfigException::unreadable($file);
        }

        $content = file_get_contents($file);

        if ($content === false) {
            throw ConfigException::unreadable($file);
        }

        return new self(EnvFile::parse($content, $file), $file, true, true);
    }

    // ------------------------------------------------------------------
    // Lire un réglage
    // ------------------------------------------------------------------

    public function has(string $key): bool
    {
        return $this->value($key) !== null;
    }

    /**
     * @throws ConfigException si la clé est absente et qu'aucune valeur par défaut n'est donnée
     */
    public function string(string $key, ?string $default = null): string
    {
        return $this->value($key) ?? $default ?? throw $this->missing($key);
    }

    /**
     * @throws ConfigException si la valeur n'est pas un nombre entier, ou si la clé est absente sans valeur par défaut
     */
    public function int(string $key, ?int $default = null): int
    {
        $value = $this->value($key);

        if ($value === null) {
            return $default ?? throw $this->missing($key);
        }

        return preg_match(self::INTEGER, $value) === 1 ? (int) $value : throw ConfigException::notAnInteger($key);
    }

    /**
     * Seuls « true » et « false » sont acceptés : « 1 », « oui » ou « on »
     * laisseraient trop de place au doute pour un réglage de sécurité.
     *
     * @throws ConfigException si la valeur n'est ni « true » ni « false », ou si la clé est absente sans valeur par défaut
     */
    public function bool(string $key, ?bool $default = null): bool
    {
        $value = $this->value($key);

        if ($value === null) {
            return $default ?? throw $this->missing($key);
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            default => throw ConfigException::notABoolean($key),
        };
    }

    /**
     * Une liste écrite avec des virgules : « a.com, b.com » devient ['a.com', 'b.com'].
     *
     * @param list<string>|null $default
     *
     * @return list<string>
     *
     * @throws ConfigException si la clé est absente et qu'aucune valeur par défaut n'est donnée
     */
    public function list(string $key, ?array $default = null): array
    {
        $value = $this->value($key);

        if ($value === null) {
            return $default ?? throw $this->missing($key);
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn(string $item): bool => $item !== ''));
    }

    // ------------------------------------------------------------------
    // Sécurité : ne pas laisser fuir les valeurs
    // ------------------------------------------------------------------

    /**
     * Ce que montrent var_dump() et print_r() : le nom des clés, pas leurs valeurs.
     *
     * @return array{file: string, keys: list<string>, values: string}
     */
    public function __debugInfo(): array
    {
        return [
            'file' => $this->file,
            'keys' => array_keys($this->values),
            'values' => '(masquées)',
        ];
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * La valeur brute d'une clé, ou null si elle n'est définie nulle part.
     */
    private function value(string $key): ?string
    {
        if (preg_match(EnvFile::KEY, $key) !== 1) {
            throw ConfigException::invalidKeyName();
        }

        if (str_starts_with($key, EnvFile::RESERVED_PREFIX)) {
            throw ConfigException::reservedKeyName($key);
        }

        if ($this->readEnvironment) {
            // getenv() LIT l'environnement du processus. On n'y écrit jamais.
            $fromEnvironment = getenv($key);

            if ($fromEnvironment !== false) {
                return $fromEnvironment;
            }
        }

        return $this->values[$key] ?? null;
    }

    private function missing(string $key): ConfigException
    {
        return ConfigException::missingKey($key, $this->file, $this->fileExists);
    }

    /**
     * Vrai si le fichier se trouve dans le dossier que le serveur web distribue.
     * En ligne de commande, ce dossier n'existe pas : la vérification ne s'applique pas.
     */
    private static function isInsideDocumentRoot(string $file): bool
    {
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';

        if (!is_string($documentRoot) || $documentRoot === '') {
            return false;
        }

        // realpath() donne le chemin réel, sans « .. » ni lien symbolique : la
        // comparaison ne peut pas être trompée par une écriture détournée.
        $realFile = realpath($file);
        $realRoot = realpath($documentRoot);

        return $realFile !== false
            && $realRoot !== false
            && str_starts_with($realFile, rtrim($realRoot, '/\\') . DIRECTORY_SEPARATOR);
    }
}
