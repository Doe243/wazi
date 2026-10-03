<?php

declare(strict_types=1);

namespace Wazi\Config;

use Wazi\Config\Exception\ConfigException;

/**
 * Lit un fichier .env : une liste de réglages, un par ligne.
 *
 *     # Un commentaire
 *     APP_NAME="Mon carnet"
 *     DATABASE_PASSWORD='p@ss # ce dièse fait partie du mot de passe'
 *     PAGINATION=20            # un commentaire en fin de ligne
 *
 * Pourquoi un fichier à part ? Ce qui change d'une machine à l'autre (mot de
 * passe de la base, adresse du site) et ce qui doit rester secret n'ont pas
 * leur place dans le code : le code est partagé, le .env ne l'est pas.
 *
 * Les règles, volontairement peu nombreuses (ADR-017) :
 *   - NOM=valeur, le nom en majuscules ;
 *   - sans guillemets, la valeur s'arrête à la fin de la ligne, ou à un « # » précédé d'un espace ;
 *   - entre guillemets simples, tout est pris tel quel ;
 *   - entre guillemets doubles, « \n », « \t », « \" » et « \\ » sont interprétés ;
 *   - une valeur tient sur une seule ligne.
 *
 * Sécurité :
 *   - une valeur est un TEXTE. Rien n'y est jamais exécuté ni remplacé : ni
 *     « ${AUTRE} », ni « $(commande) ». Ces écritures sont gardées telles quelles ;
 *   - une ligne mal écrite est signalée par son numéro, jamais par son contenu ;
 *   - un nom ne peut pas commencer par « HTTP_ » (voir RESERVED_PREFIX).
 */
final class EnvFile
{
    /** Un fichier de réglages n'a aucune raison d'être plus gros. */
    public const int MAX_SIZE = 262_144;

    public const string KEY = '/^[A-Z][A-Z0-9_]*$/D';

    /**
     * Sur certains serveurs, chaque en-tête de la requête devient une variable
     * d'environnement « HTTP_... » : son contenu est choisi par le visiteur.
     * Aucun réglage ne peut donc porter un nom qui commence ainsi (ADR-018).
     */
    public const string RESERVED_PREFIX = 'HTTP_';

    private const string LINE = '/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/Ds';

    /** "…" suivi éventuellement d'un commentaire ; « \x » désigne un caractère échappé. */
    private const string DOUBLE_QUOTED = '/^"((?:[^"\\\\]|\\\\.)*+)"\s*(?:#.*)?$/Ds';

    /** '…' suivi éventuellement d'un commentaire. */
    private const string SINGLE_QUOTED = '/^\'([^\']*+)\'\s*(?:#.*)?$/Ds';

    private const array ESCAPES = ['\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\"' => '"', '\\\\' => '\\'];

    /**
     * @param string $file le chemin du fichier, cité dans les messages d'erreur
     *
     * @return array<string, string>
     *
     * @throws ConfigException si une ligne est mal écrite ou si une clé est définie deux fois
     */
    public static function parse(#[\SensitiveParameter] string $content, string $file = '.env'): array
    {
        // Certains éditeurs placent en tête de fichier une marque invisible (« BOM ») : on la retire.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $values = [];
        $definedAt = [];

        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $index => $line) {
            $number = $index + 1;
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match(self::LINE, $line, $matches) !== 1) {
                throw ConfigException::invalidLine($file, $number, 'il manque le signe « = », ou le nom contient un caractère interdit.');
            }

            [, $key, $rawValue] = $matches;

            if (preg_match(self::KEY, $key) !== 1) {
                throw ConfigException::invalidLine($file, $number, 'le nom doit être en majuscules (lettres, chiffres et « _ »).');
            }

            if (str_starts_with($key, self::RESERVED_PREFIX)) {
                throw ConfigException::invalidLine($file, $number, 'un nom ne peut pas commencer par « HTTP_ » : ces noms sont réservés aux en-têtes de la requête, qu\'un visiteur peut choisir.');
            }

            if (isset($definedAt[$key])) {
                throw ConfigException::duplicateKey($file, $key, $definedAt[$key], $number);
            }

            $values[$key] = self::value($rawValue, $file, $number);
            $definedAt[$key] = $number;
        }

        return $values;
    }

    private static function value(#[\SensitiveParameter] string $raw, string $file, int $number): string
    {
        if (str_starts_with($raw, '"')) {
            if (preg_match(self::DOUBLE_QUOTED, $raw, $matches) !== 1) {
                throw ConfigException::invalidLine($file, $number, 'un guillemet « " » n\'est pas refermé, ou du texte suit le guillemet fermant.');
            }

            return strtr($matches[1], self::ESCAPES);
        }

        if (str_starts_with($raw, "'")) {
            if (preg_match(self::SINGLE_QUOTED, $raw, $matches) !== 1) {
                throw ConfigException::invalidLine($file, $number, 'un guillemet « \' » n\'est pas refermé, ou du texte suit le guillemet fermant.');
            }

            return $matches[1];
        }

        // Sans guillemets : un « # » précédé d'un espace commence un commentaire.
        $comment = preg_match('/\s#/', $raw, $found, PREG_OFFSET_CAPTURE) === 1 ? $found[0][1] : null;

        return rtrim($comment === null ? $raw : substr($raw, 0, $comment));
    }
}
