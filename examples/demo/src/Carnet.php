<?php

declare(strict_types=1);

namespace Demo;

use Wazi\Database\Database;

/**
 * Le service : il range les notes dans la base de données, et ne sait rien du web.
 *
 * C'est le seul endroit de la démonstration qui écrit du SQL. La table
 * « notes » est créée par migrations/20261005_090000_creer_notes.sql.
 *
 * Deux règles de sécurité se lisent dans chaque méthode :
 *
 *   1. Une valeur ne s'écrit jamais dans le SQL. On écrit un marqueur (« ? »)
 *      et on donne la valeur à part : quoi qu'un visiteur ait écrit dans une
 *      note ou dans la recherche, cela reste un texte, jamais une requête.
 *
 *   2. Chaque requête porte « auteur = ? ». Une note ne se lit, ne se modifie
 *      et ne se supprime que si elle appartient à celui qui la demande.
 *      Vérifier « est-il connecté ? » ne suffit pas : il faut aussi vérifier
 *      « est-ce bien à lui ? ». Ici, l'oubli est impossible.
 *
 * @phpstan-type Note array{id: int, auteur: string, texte: string, couleur: string, importante: bool, creee: \DateTimeImmutable}
 */
final readonly class Carnet
{
    /** La longueur maximale d'une note, en caractères. */
    public const int LONGUEUR_MAX = 280;

    /**
     * Les couleurs qu'une note peut porter. La première est celle par défaut.
     *
     * Sécurité : la couleur choisie par le visiteur finit dans un attribut
     * class="…" de la page. Elle n'est acceptée que si elle figure dans cette
     * liste : on ne recopie jamais telle quelle une valeur reçue.
     */
    public const array COULEURS = ['neutre', 'lagon', 'miel', 'corail', 'ciel'];

    /** Les dates sont gardées ainsi, à l'heure universelle : 2026-10-05 09:30:00. */
    private const string DATE = 'Y-m-d H:i:s';

    public function __construct(private Database $db) {}

    /**
     * Les notes d'un auteur : les importantes d'abord, puis de la plus récente à la plus ancienne.
     *
     * @param string $recherche         ne garder que les notes qui contiennent ce texte ; vide : toutes
     * @param bool   $importantesSeules ne garder que les notes importantes
     *
     * @return list<Note>
     */
    public function de(string $auteur, string $recherche = '', bool $importantesSeules = false): array
    {
        // La requête s'allonge selon ce qui est demandé. Ce qu'on y ajoute est
        // toujours un morceau de SQL écrit ici, jamais une valeur : les
        // valeurs, elles, s'ajoutent à la liste.
        $sql = 'SELECT id, auteur, texte, couleur, importante, creee_le FROM notes WHERE auteur = ?';
        $valeurs = [$auteur];

        if ($importantesSeules) {
            $sql .= ' AND importante = 1';
        }

        if ($recherche !== '') {
            // Dans un LIKE, « % » veut dire « n'importe quoi ». Celui que tape
            // un visiteur doit rester un caractère : likeEscape() le neutralise,
            // et ESCAPE '!' dit à la base comment.
            $sql .= " AND texte LIKE ? ESCAPE '!'";
            $valeurs[] = '%' . Database::likeEscape($recherche) . '%';
        }

        $lignes = $this->db->select($sql . ' ORDER BY importante DESC, creee_le DESC, id DESC', $valeurs);

        return array_values(array_filter(array_map(self::note(...), $lignes)));
    }

    /**
     * @return Note|null la note, ou null si elle n'existe pas OU si elle n'est pas à cet auteur
     */
    public function trouver(int $id, string $auteur): ?array
    {
        $ligne = $this->db->selectOne(
            'SELECT id, auteur, texte, couleur, importante, creee_le FROM notes WHERE id = ? AND auteur = ?',
            [$id, $auteur],
        );

        return $ligne === null ? null : self::note($ligne);
    }

    public function ajouter(string $auteur, string $texte, string $couleur): void
    {
        $this->db->insert('notes', [
            'auteur' => $auteur,
            'texte' => $texte,
            'couleur' => self::couleurPermise($couleur),
            'importante' => false,
            'creee_le' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ]);
    }

    /**
     * @return bool faux si la note n'existe pas ou n'est pas à cet auteur
     */
    public function modifier(int $id, string $auteur, string $texte, string $couleur, bool $importante): bool
    {
        // Le troisième argument est la condition : la note n'est modifiée que
        // si son numéro ET son auteur correspondent.
        $modifiees = $this->db->update(
            'notes',
            ['texte' => $texte, 'couleur' => self::couleurPermise($couleur), 'importante' => $importante],
            ['id' => $id, 'auteur' => $auteur],
        );

        return $modifiees === 1;
    }

    /**
     * Inverse le caractère « importante » d'une note.
     *
     * @return bool|null la nouvelle valeur, ou null si la note n'existe pas ou n'est pas à cet auteur
     */
    public function basculer(int $id, string $auteur): ?bool
    {
        // « 1 - importante » : 0 devient 1, et 1 devient 0. La base fait le
        // calcul elle-même, en une seule requête : deux clics au même instant
        // ne se marchent pas dessus.
        $modifiees = $this->db->execute('UPDATE notes SET importante = 1 - importante WHERE id = ? AND auteur = ?', [$id, $auteur]);

        return $modifiees === 1 ? $this->trouver($id, $auteur)['importante'] ?? null : null;
    }

    public function supprimer(int $id, string $auteur): bool
    {
        return $this->db->delete('notes', ['id' => $id, 'auteur' => $auteur]) === 1;
    }

    /**
     * La couleur demandée si elle fait partie de la liste, la couleur par défaut sinon.
     */
    public static function couleurPermise(string $couleur): string
    {
        return in_array($couleur, self::COULEURS, true) ? $couleur : self::COULEURS[0];
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * Une ligne de la table, changée en note.
     *
     * Pour PHP, ce qui revient d'une base est de type inconnu : chaque valeur
     * est vérifiée. Une ligne abîmée est ignorée plutôt que d'abîmer la page.
     *
     * @param array<string, mixed> $ligne
     *
     * @return Note|null
     */
    private static function note(array $ligne): ?array
    {
        if (!is_int($ligne['id'] ?? null) || !is_string($ligne['auteur'] ?? null) || !is_string($ligne['texte'] ?? null)) {
            return null;
        }

        $creee = is_string($ligne['creee_le'] ?? null)
            ? \DateTimeImmutable::createFromFormat(self::DATE, $ligne['creee_le'], new \DateTimeZone('UTC'))
            : false;

        return [
            'id' => $ligne['id'],
            'auteur' => $ligne['auteur'],
            'texte' => $ligne['texte'],
            'couleur' => self::couleurPermise(is_string($ligne['couleur'] ?? null) ? $ligne['couleur'] : ''),
            // SQLite garde un booléen sous la forme 0 ou 1.
            'importante' => ($ligne['importante'] ?? 0) === 1,
            // Gardée à l'heure universelle, la date est rendue à l'heure du serveur.
            'creee' => $creee instanceof \DateTimeImmutable
                ? $creee->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                : new \DateTimeImmutable(),
        ];
    }
}
