<?php

declare(strict_types=1);

namespace Wazi\Http;

use Wazi\Http\Exception\SessionException;

/**
 * La session : ce dont votre application se souvient d'un visiteur, d'une page à l'autre.
 *
 * HTTP n'a pas de mémoire : chaque requête arrive seule. Pour reconnaître un
 * visiteur, on lui donne un identifiant tiré au hasard, rangé dans un cookie ;
 * il le renvoie à chaque requête, et le serveur retrouve ce qu'il avait noté.
 *
 *     $session->set('user_id', 42);       // noter
 *     $session->get('user_id');           // relire à la requête suivante : 42
 *     $session->regenerate();             // à faire juste après une connexion réussie
 *     $session->clear();                  // tout oublier : déconnexion
 *
 *     $session->flash('succes', 'Note ajoutée.');   // un message pour la page suivante
 *     $session->takeFlash('succes');                // le lire, une seule fois
 *     $session->remember(30);                       // « se souvenir de moi » pendant 30 jours
 *
 * Un contrôleur demande la session dans son constructeur, comme tout service.
 *
 * Cet objet ne lit ni n'écrit rien lui-même : c'est le middleware des sessions
 * qui le remplit au début de la requête et l'enregistre à la fin.
 *
 * Sécurité (ADR-006, ADR-021 et ADR-024) :
 *   - l'identifiant fait 256 bits de hasard : impossible à deviner ;
 *   - regenerate() change l'identifiant. Appelez-le après une connexion : si un
 *     attaquant avait réussi à imposer son identifiant au visiteur avant qu'il
 *     se connecte (« fixation de session »), cet identifiant ne vaut plus rien ;
 *   - une session ne garde que des valeurs simples, enregistrées en JSON. Aucun
 *     objet n'est jamais reconstruit à partir d'un fichier de session.
 */
final class Session
{
    /** 64 chiffres hexadécimaux : 32 octets au hasard. */
    private const string ID = '/^[a-f0-9]{64}$/D';

    /** La clé où sont rangés les messages laissés par flash(). */
    private const string FLASH = '_flash';

    /** La clé où est rangée la durée demandée par remember(), en secondes. */
    private const string REMEMBER = '_remember';

    /** Au-delà d'un an, une session oubliée sur un ordinateur partagé est un risque sans contrepartie. */
    private const int MAX_REMEMBER_DAYS = 365;

    private ?string $id = null;

    /** @var array<string, mixed> */
    private array $data = [];

    /** Vrai si le visiteur n'avait pas encore de session en arrivant. */
    private bool $new = true;

    private bool $changed = false;

    /** L'identifiant abandonné par regenerate(), dont le fichier doit être supprimé. */
    private ?string $discardedId = null;

    // ------------------------------------------------------------------
    // Ce dont votre code se sert
    // ------------------------------------------------------------------

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertStarted();

        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function has(string $key): bool
    {
        $this->assertStarted();

        return array_key_exists($key, $this->data);
    }

    /**
     * @throws SessionException si la valeur n'est pas une valeur simple, ou si la clé est réservée
     */
    public function set(string $key, mixed $value): void
    {
        $this->assertStarted();

        if (str_starts_with($key, '_')) {
            throw SessionException::reservedKey($key);
        }

        if (!self::isStorable($value)) {
            throw SessionException::unsupportedValue($key, get_debug_type($value));
        }

        $this->data[$key] = $value;
        $this->changed = true;
    }

    public function remove(string $key): void
    {
        $this->assertStarted();

        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            $this->changed = true;
        }
    }

    /**
     * Change l'identifiant de la session en gardant son contenu.
     * À appeler juste après une connexion réussie.
     */
    public function regenerate(): void
    {
        $this->assertStarted();

        // On ne retient que le PREMIER identifiant abandonné : c'est le seul
        // qui a un fichier sur le disque.
        if (!$this->new) {
            $this->discardedId ??= $this->id;
        }

        $this->id = self::newId();
        $this->changed = true;
    }

    /**
     * Oublie tout, et change d'identifiant : c'est la déconnexion.
     */
    public function clear(): void
    {
        $this->assertStarted();

        $this->data = [];
        $this->regenerate();
    }

    /**
     * Laisse un message pour la page suivante.
     *
     * Après un formulaire réussi, on redirige le visiteur (pour qu'un
     * rechargement ne renvoie pas le formulaire). Le message « Note ajoutée »
     * doit donc survivre à la redirection, puis disparaître :
     *
     *     $session->flash('succes', 'Note ajoutée.');
     *     return new Response(303, ['Location' => '/notes']);
     *
     * @throws SessionException si la valeur n'est pas une valeur simple
     */
    public function flash(string $key, mixed $value): void
    {
        $this->assertStarted();

        if (!self::isStorable($value)) {
            throw SessionException::unsupportedValue($key, get_debug_type($value));
        }

        $messages = $this->flashMessages();
        $messages[$key] = $value;

        $this->data[self::FLASH] = $messages;
        $this->changed = true;
    }

    /**
     * Lit un message laissé par flash(), et l'efface : il ne sera affiché qu'une fois.
     *
     * Tant qu'aucune page ne le lit, le message attend.
     */
    public function takeFlash(string $key, mixed $default = null): mixed
    {
        $this->assertStarted();

        $messages = $this->flashMessages();

        if (!array_key_exists($key, $messages)) {
            return $default;
        }

        $value = $messages[$key];
        unset($messages[$key]);

        if ($messages === []) {
            unset($this->data[self::FLASH]);
        } else {
            $this->data[self::FLASH] = $messages;
        }

        $this->changed = true;

        return $value;
    }

    /**
     * « Se souvenir de moi » : la session survit à la fermeture du navigateur,
     * et dure $days jours après la dernière visite.
     *
     * À appeler à la connexion, seulement si le visiteur a coché la case.
     *
     * Sécurité (ADR-024) : sans cet appel, la session disparaît quand le
     * navigateur se ferme, ou après deux heures sans visite. Une session
     * longue reste ouverte sur l'ordinateur où elle a été créée : ne
     * l'activez jamais sans que le visiteur l'ait demandé.
     *
     * @throws SessionException si la durée n'est pas comprise entre 1 et 365 jours
     */
    public function remember(int $days = 30): void
    {
        $this->assertStarted();

        if ($days < 1 || $days > self::MAX_REMEMBER_DAYS) {
            throw SessionException::invalidRememberDuration($days, self::MAX_REMEMBER_DAYS);
        }

        $this->data[self::REMEMBER] = $days * 86400;
        $this->changed = true;
    }

    // ------------------------------------------------------------------
    // Ce dont le middleware des sessions se sert
    // ------------------------------------------------------------------

    /**
     * Remplit la session au début d'une requête.
     *
     * @param string|null          $id   l'identifiant reçu dans le cookie, s'il correspond à une session connue ; null pour une nouvelle session
     * @param array<string, mixed> $data ce qui avait été noté
     *
     * @internal appelé par le middleware des sessions
     */
    public function start(?string $id, array $data = []): void
    {
        $this->new = $id === null;
        $this->id = $id ?? self::newId();
        $this->data = $data;
        $this->changed = false;
        $this->discardedId = null;
    }

    public function isStarted(): bool
    {
        return $this->id !== null;
    }

    public function id(): string
    {
        return $this->id ?? throw SessionException::notStarted();
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->assertStarted();

        return $this->data;
    }

    public function isNew(): bool
    {
        return $this->new;
    }

    public function hasChanged(): bool
    {
        return $this->changed;
    }

    public function discardedId(): ?string
    {
        return $this->discardedId;
    }

    /**
     * La durée demandée par remember(), en secondes, ou null pour une session ordinaire.
     *
     * @internal lu par le middleware des sessions
     */
    public function lifetime(): ?int
    {
        $lifetime = $this->data[self::REMEMBER] ?? null;

        // La valeur est relue d'un fichier : hors des bornes, elle est ignorée.
        return is_int($lifetime) && $lifetime >= 86400 && $lifetime <= self::MAX_REMEMBER_DAYS * 86400 ? $lifetime : null;
    }

    /**
     * Vrai si ce texte a la forme d'un identifiant de session.
     *
     * Sécurité : un identifiant reçu d'un cookie sert à nommer un fichier. Il
     * n'est utilisé que s'il a exactement cette forme : aucun « ../ » possible.
     */
    public static function isValidId(string $id): bool
    {
        return preg_match(self::ID, $id) === 1;
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    private static function newId(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function assertStarted(): void
    {
        if ($this->id === null) {
            throw SessionException::notStarted();
        }
    }

    /**
     * @return array<mixed>
     */
    private function flashMessages(): array
    {
        $messages = $this->data[self::FLASH] ?? [];

        return is_array($messages) ? $messages : [];
    }

    /**
     * Une valeur simple : texte, nombre, vrai/faux, null, ou tableau de valeurs simples.
     */
    private static function isStorable(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::isStorable($item)) {
                    return false;
                }
            }

            return true;
        }

        // Un nombre « infini » ou « pas un nombre » ne s'écrit pas en JSON.
        if (is_float($value)) {
            return is_finite($value);
        }

        // Un texte qui n'est pas de l'UTF-8 valide ne s'écrit pas en JSON non plus.
        if (is_string($value)) {
            return preg_match('//u', $value) === 1;
        }

        return $value === null || is_int($value) || is_bool($value);
    }
}
