<?php

declare(strict_types=1);

namespace Demo;

/**
 * Le service : il range les notes dans un fichier, et ne sait rien du web.
 *
 * Wazi n'a pas encore de composant de base de données (il arrive en 0.4). Un
 * fichier JSON suffit pour une démonstration ; un vrai site utilisera une base.
 *
 * Sécurité : chaque méthode demande l'auteur. Une note ne se lit, ne se
 * modifie et ne se supprime que si elle appartient à celui qui la demande.
 * Vérifier « est-il connecté ? » ne suffit pas : il faut aussi vérifier
 * « est-ce bien à lui ? ». Ici, l'oubli est impossible.
 *
 * @phpstan-type Note array{id: int, auteur: string, texte: string, importante: bool, creee: \DateTimeImmutable}
 */
final readonly class Carnet
{
    /** La longueur maximale d'une note, en caractères. */
    public const int LONGUEUR_MAX = 280;

    public function __construct(private string $fichier) {}

    /**
     * Les notes d'un auteur, la plus récente en premier.
     *
     * @return list<Note>
     */
    public function de(string $auteur): array
    {
        $notes = array_filter($this->lire()['notes'], static fn(array $note): bool => $note['auteur'] === $auteur);

        return array_reverse(array_values($notes));
    }

    /**
     * @return Note|null la note, ou null si elle n'existe pas OU si elle n'est pas à cet auteur
     */
    public function trouver(int $id, string $auteur): ?array
    {
        return array_find($this->de($auteur), static fn(array $note): bool => $note['id'] === $id);
    }

    public function ajouter(string $auteur, string $texte): void
    {
        $this->modifierLeFichier(static function (array $contenu) use ($auteur, $texte): array {
            $contenu['notes'][] = [
                'id' => $contenu['suivant'],
                'auteur' => $auteur,
                'texte' => $texte,
                'importante' => false,
                'creee' => new \DateTimeImmutable(),
            ];
            $contenu['suivant']++;

            return $contenu;
        });
    }

    /**
     * @return bool faux si la note n'existe pas ou n'est pas à cet auteur
     */
    public function modifier(int $id, string $auteur, string $texte, bool $importante): bool
    {
        return $this->changer($id, $auteur, static fn(array $note): array => [...$note, 'texte' => $texte, 'importante' => $importante]);
    }

    /**
     * Inverse le caractère « importante » d'une note.
     *
     * @return bool|null la nouvelle valeur, ou null si la note n'existe pas ou n'est pas à cet auteur
     */
    public function basculer(int $id, string $auteur): ?bool
    {
        $changee = $this->changer($id, $auteur, static fn(array $note): array => [...$note, 'importante' => !$note['importante']]);

        return $changee ? $this->trouver($id, $auteur)['importante'] ?? null : null;
    }

    public function supprimer(int $id, string $auteur): bool
    {
        return $this->changer($id, $auteur, static fn(): null => null);
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * Applique un changement à UNE note, si elle appartient à l'auteur.
     *
     * @param \Closure(Note): (Note|null) $changement retourne la note changée, ou null pour la supprimer
     */
    private function changer(int $id, string $auteur, \Closure $changement): bool
    {
        $trouvee = false;

        $this->modifierLeFichier(static function (array $contenu) use ($id, $auteur, $changement, &$trouvee): array {
            foreach ($contenu['notes'] as $position => $note) {
                if ($note['id'] !== $id || $note['auteur'] !== $auteur) {
                    continue;
                }

                $trouvee = true;
                $changee = $changement($note);

                if ($changee === null) {
                    unset($contenu['notes'][$position]);
                } else {
                    $contenu['notes'][$position] = $changee;
                }
            }

            $contenu['notes'] = array_values($contenu['notes']);

            return $contenu;
        });

        return $trouvee;
    }

    /**
     * @return array{suivant: int, notes: list<Note>}
     */
    private function lire(): array
    {
        if (!is_file($this->fichier)) {
            return self::notesDeDepart();
        }

        $texte = file_get_contents($this->fichier);

        return $texte === false || $texte === '' ? self::notesDeDepart() : self::decoder($texte);
    }

    /**
     * Lit le fichier, applique le changement et réécrit le fichier, sans
     * qu'une autre requête puisse s'intercaler : le fichier est réservé
     * (flock) du début à la fin.
     *
     * @param \Closure(array{suivant: int, notes: list<Note>}): array{suivant: int, notes: list<Note>} $changement
     */
    private function modifierLeFichier(\Closure $changement): void
    {
        if (!is_dir(dirname($this->fichier))) {
            mkdir(dirname($this->fichier), 0o700, true);
        }

        // « c+ » : ouvre en lecture et écriture, crée le fichier s'il manque, sans le vider.
        $fichier = fopen($this->fichier, 'c+');

        if ($fichier === false || !flock($fichier, LOCK_EX)) {
            throw new \RuntimeException('Le fichier des notes n\'a pas pu être ouvert. Vérifiez que PHP a le droit d\'écrire dans le dossier var/.');
        }

        try {
            $texte = (string) stream_get_contents($fichier);
            $contenu = $changement($texte === '' ? self::notesDeDepart() : self::decoder($texte));

            ftruncate($fichier, 0);
            rewind($fichier);
            fwrite($fichier, self::encoder($contenu));
        } finally {
            flock($fichier, LOCK_UN);
            fclose($fichier);
        }
    }

    /**
     * @param array{suivant: int, notes: list<Note>} $contenu
     */
    private static function encoder(array $contenu): string
    {
        $notes = array_map(
            static fn(array $note): array => [...$note, 'creee' => $note['creee']->format(DATE_ATOM)],
            $contenu['notes'],
        );

        return json_encode(['suivant' => $contenu['suivant'], 'notes' => $notes], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Relit le fichier en vérifiant chaque valeur : ce qui vient d'un fichier
     * n'est pas plus sûr que ce qui vient d'un formulaire.
     *
     * @return array{suivant: int, notes: list<Note>}
     */
    private static function decoder(string $texte): array
    {
        $contenu = json_decode($texte, true);
        $lues = is_array($contenu) && is_array($contenu['notes'] ?? null) ? $contenu['notes'] : [];
        $notes = [];
        $suivant = 1;

        foreach ($lues as $note) {
            if (!is_array($note) || !is_int($note['id'] ?? null) || !is_string($note['auteur'] ?? null) || !is_string($note['texte'] ?? null)) {
                continue;
            }

            $creee = is_string($note['creee'] ?? null) ? \DateTimeImmutable::createFromFormat(DATE_ATOM, $note['creee']) : false;

            $notes[] = [
                'id' => $note['id'],
                'auteur' => $note['auteur'],
                'texte' => $note['texte'],
                'importante' => ($note['importante'] ?? false) === true,
                'creee' => $creee instanceof \DateTimeImmutable ? $creee : new \DateTimeImmutable(),
            ];
            $suivant = max($suivant, $note['id'] + 1);
        }

        $annonce = is_array($contenu) && is_int($contenu['suivant'] ?? null) ? $contenu['suivant'] : 1;

        return ['suivant' => max($suivant, $annonce), 'notes' => $notes];
    }

    /**
     * Ce que contient le carnet au premier lancement.
     *
     * @return array{suivant: int, notes: list<Note>}
     */
    private static function notesDeDepart(): array
    {
        $note = static fn(int $id, string $auteur, string $texte, bool $importante, string $quand): array => [
            'id' => $id,
            'auteur' => $auteur,
            'texte' => $texte,
            'importante' => $importante,
            'creee' => new \DateTimeImmutable($quand),
        ];

        return [
            'suivant' => 5,
            'notes' => [
                $note(1, 'alice', 'Lire le code du routeur : il tient en un fichier.', true, '-3 days'),
                $note(2, 'alice', 'Essayer d\'écrire <script>alert(1)</script> dans une note.', false, '-2 days'),
                $note(3, 'alice', 'Acheter du pain.', false, '-5 hours'),
                $note(4, 'bob', 'Cette note est à Bob : Alice ne peut pas la voir.', false, '-1 day'),
            ],
        ];
    }
}
