<?php

declare(strict_types=1);

namespace Wazi\Http;

/**
 * Le jeton qui protège vos formulaires contre la falsification de requête (CSRF).
 *
 * L'attaque : un visiteur est connecté à votre site. Une page piégée, sur un
 * autre site, lui fait envoyer un formulaire caché vers le vôtre. Son
 * navigateur y joint ses cookies : pour votre site, c'est lui qui agit.
 *
 * La parade (dite « du double envoi ») :
 *
 *     1. votre site donne au navigateur un jeton tiré au hasard, dans un cookie ;
 *     2. chaque formulaire de VOS pages répète ce jeton dans un champ caché ;
 *     3. à la réception, le champ doit être égal au cookie.
 *
 * La page piégée peut faire partir le cookie, mais elle ne peut pas le LIRE
 * (le navigateur le lui interdit) : elle ne sait donc pas quoi écrire dans le
 * champ. Le serveur n'a rien à retenir : aucun fichier, aucune session.
 *
 * Cet objet porte le jeton de la requête en cours. Il ne lit ni n'écrit aucun
 * cookie lui-même : c'est le middleware CsrfCookie qui s'en charge.
 *
 * Il vit dans Http, la couche du bas, parce que Middleware (qui le vérifie) et
 * View (qui l'écrit dans les formulaires) s'en servent tous deux.
 */
final class CsrfToken
{
    /** Le nom du champ de formulaire qui porte le jeton. */
    public const string FIELD = '_csrf';

    /** 64 chiffres hexadécimaux : 32 octets au hasard. */
    private const string FORMAT = '/^[a-f0-9]{64}$/D';

    private bool $started = false;

    /** Le jeton que le navigateur a renvoyé dans son cookie, s'il en avait un. */
    private ?string $received = null;

    /** Un jeton neuf, créé pendant cette requête parce qu'un formulaire en avait besoin. */
    private ?string $created = null;

    /**
     * Prépare le jeton au début d'une requête.
     *
     * @param string|null $fromCookie la valeur du cookie reçu, ou null s'il n'y en avait pas
     *
     * @internal appelé par le middleware CsrfCookie
     */
    public function start(?string $fromCookie): void
    {
        $this->started = true;
        $this->created = null;

        // Un cookie qui n'a pas la forme d'un jeton est traité comme absent.
        $this->received = $fromCookie !== null && preg_match(self::FORMAT, $fromCookie) === 1 ? $fromCookie : null;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * Le jeton à écrire dans un formulaire. Kioo le fait de lui-même ; vous
     * n'en avez besoin que pour une requête envoyée par JavaScript (en-tête
     * X-CSRF-Token) ou un formulaire écrit hors d'un template.
     *
     * Si le navigateur n'a pas encore de jeton, un jeton neuf est créé, et le
     * middleware enverra le cookie correspondant avec la réponse.
     */
    public function value(): string
    {
        return $this->received ?? $this->created ??= bin2hex(random_bytes(32));
    }

    /**
     * Vrai si le jeton reçu avec le formulaire est celui du cookie.
     *
     * Sans cookie, la réponse est toujours non : un jeton créé pendant cette
     * même requête ne prouve rien.
     */
    public function matches(string $candidate): bool
    {
        // hash_equals() compare en un temps constant : la durée de la
        // comparaison ne révèle rien sur le jeton attendu.
        return $this->received !== null && $candidate !== '' && hash_equals($this->received, $candidate);
    }

    /**
     * Le jeton à envoyer au navigateur dans un cookie, ou null s'il n'y a rien à envoyer.
     *
     * @internal lu par le middleware CsrfCookie
     */
    public function toSend(): ?string
    {
        return $this->created;
    }
}
