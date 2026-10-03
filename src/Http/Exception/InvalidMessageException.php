<?php

declare(strict_types=1);

namespace Wazi\Http\Exception;

/**
 * Levée quand une partie d'un message HTTP (requête ou réponse) est invalide :
 * en-tête, méthode, code de statut, version du protocole...
 *
 * Chaque message suit la règle des erreurs pédagogiques de Wazi :
 * ce qui s'est passé, pourquoi, et comment corriger.
 *
 * Elle étend \InvalidArgumentException, comme l'exige PSR-7.
 */
final class InvalidMessageException extends \InvalidArgumentException
{
    // ------------------------------------------------------------------
    // En-têtes
    // ------------------------------------------------------------------

    public static function invalidHeaderName(string $name): self
    {
        return new self(sprintf(
            '« %s » n\'est pas un nom d\'en-tête valide. Un nom d\'en-tête ne contient que des lettres,'
            . ' des chiffres et quelques signes comme « - » : ni espace, ni « : », ni retour à la ligne.'
            . ' Exemple valide : Content-Type.',
            ValuePreview::of($name),
        ));
    }

    /**
     * Sécurité (ADR-006) : le message cite le nom de l'en-tête, jamais sa valeur.
     * Elle peut contenir un secret (le jeton d'un en-tête Authorization, un cookie)
     * qui se retrouverait alors dans les journaux.
     */
    public static function invalidHeaderValue(string $name): self
    {
        return new self(sprintf(
            'La valeur de l\'en-tête « %s » contient un caractère interdit : un retour à la ligne'
            . ' ou un caractère de contrôle. Wazi la refuse, car un retour à la ligne permettrait'
            . ' d\'injecter de faux en-têtes dans le message. Retirez ces caractères ; si la valeur'
            . ' vient d\'un utilisateur, validez-la avant de l\'utiliser.',
            ValuePreview::of($name),
        ));
    }

    public static function headerValueNotText(string $name, string $givenType): self
    {
        return new self(sprintf(
            'La valeur de l\'en-tête « %s » doit être un texte (ou un nombre entier), ou une liste'
            . ' de textes ; ici, c\'est « %s ». Exemple : withHeader(\'Content-Type\', \'text/html\').',
            ValuePreview::of($name),
            $givenType,
        ));
    }

    public static function emptyHeaderValues(string $name): self
    {
        return new self(sprintf(
            'L\'en-tête « %s » a reçu une liste vide : un en-tête a au moins une valeur.'
            . ' Pour supprimer un en-tête, utilisez withoutHeader().',
            ValuePreview::of($name),
        ));
    }

    // ------------------------------------------------------------------
    // Première ligne du message
    // ------------------------------------------------------------------

    public static function invalidProtocolVersion(string $version): self
    {
        return new self(sprintf(
            '« %s » n\'est pas une version du protocole HTTP. Écrivez seulement le numéro,'
            . ' par exemple « 1.1 » ou « 2 », sans le préfixe « HTTP/ ».',
            ValuePreview::of($version),
        ));
    }

    public static function invalidMethod(string $method): self
    {
        return new self(sprintf(
            '« %s » n\'est pas une méthode HTTP valide. Une méthode est un mot sans espace,'
            . ' en général en majuscules : GET, POST, PUT, PATCH, DELETE...',
            ValuePreview::of($method),
        ));
    }

    public static function invalidRequestTarget(string $requestTarget): self
    {
        return new self(sprintf(
            '« %s » ne peut pas servir de cible de requête : elle ne doit être ni vide, ni contenir'
            . ' d\'espace ou de retour à la ligne. Exemple valide : /articles?page=2',
            ValuePreview::of($requestTarget),
        ));
    }

    public static function invalidStatusCode(int $code): self
    {
        return new self(sprintf(
            '%d n\'est pas un code de statut HTTP : il doit être compris entre 100 et 599.'
            . ' Les plus courants : 200 (tout va bien), 404 (page introuvable), 500 (erreur du serveur).',
            $code,
        ));
    }

    public static function invalidReasonPhrase(string $reasonPhrase): self
    {
        return new self(sprintf(
            '« %s » ne peut pas servir de phrase de statut : elle contient un retour à la ligne'
            . ' ou un caractère de contrôle. Laissez-la vide pour obtenir la phrase standard du code.',
            ValuePreview::of($reasonPhrase),
        ));
    }

    // ------------------------------------------------------------------
    // Requête reçue par le serveur
    // ------------------------------------------------------------------

    public static function notAnUploadedFile(string $givenType): self
    {
        return new self(sprintf(
            'La liste des fichiers envoyés ne peut contenir que des objets UploadedFileInterface'
            . ' (ou des tableaux qui en contiennent) ; ici, elle contient « %s ».',
            $givenType,
        ));
    }

    public static function invalidParsedBody(string $givenType): self
    {
        return new self(sprintf(
            'Le corps analysé d\'une requête est un tableau, un objet, ou null quand il n\'y en a pas ;'
            . ' ici, c\'est « %s ». Pour un formulaire, passez le tableau des champs.',
            $givenType,
        ));
    }

    public static function negativeBodyLimit(int $maxBodySize): self
    {
        return new self(sprintf(
            'La taille maximale d\'un corps de requête ne peut pas être négative (%d reçu).'
            . ' Donnez-la en octets, par exemple 8 * 1024 * 1024 pour 8 Mo.',
            $maxBodySize,
        ));
    }

    public static function invalidProxy(string $proxy): self
    {
        return new self(sprintf(
            '« %s » n\'est pas une adresse de proxy valide. Donnez une adresse IP (\'10.0.0.5\') ou une plage'
            . ' (\'10.0.0.0/8\'). C\'est l\'adresse de la machine qui transmet les requêtes à PHP : un répartiteur'
            . ' de charge, un proxy nginx, le réseau de votre hébergeur.',
            ValuePreview::of($proxy),
        ));
    }
}
