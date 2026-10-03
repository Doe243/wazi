<?php

declare(strict_types=1);

namespace Wazi\Http\Exception;

/**
 * Levée quand la requête reçue du navigateur est refusée avant même d'atteindre
 * votre code : corps trop gros, en-tête Host invalide ou non autorisé...
 *
 * Ce n'est pas une erreur de votre application : c'est le client qui a envoyé
 * quelque chose d'inacceptable. Le code de statut HTTP à lui répondre est
 * dans $statusCode (400 « requête incorrecte », 413 « contenu trop gros »).
 *
 * Les messages s'adressent au développeur qui lit le journal, pas au visiteur :
 * ils disent quoi régler si le refus n'est pas voulu.
 */
final class RequestRejectedException extends \RuntimeException
{
    private function __construct(string $message, public readonly int $statusCode)
    {
        parent::__construct($message);
    }

    public static function bodyTooLarge(int $maxBodySize): self
    {
        return new self(sprintf(
            'La requête annonce un corps plus gros que la limite de %d octets : elle est refusée avant'
            . ' d\'être lue, pour qu\'un envoi énorme ne puisse pas saturer le serveur. Si vos visiteurs'
            . ' doivent envoyer de plus gros fichiers, augmentez la limite :'
            . ' new ServerRequestCreator(maxBodySize: ...), et vérifiez post_max_size dans le php.ini.',
            $maxBodySize,
        ), 413);
    }

    public static function invalidContentLength(): self
    {
        return new self(
            'L\'en-tête Content-Length de la requête n\'est pas un nombre entier positif.'
            . ' Wazi refuse la requête : une longueur ambiguë sert à faire passer une requête cachée'
            . ' derrière une autre. Un navigateur n\'envoie jamais cela.',
            400,
        );
    }

    public static function invalidHost(string $host): self
    {
        return new self(sprintf(
            'L\'en-tête Host de la requête, « %s », n\'est pas un nom d\'hôte valide'
            . ' (un nom comme exemple.com, suivi éventuellement de « : » et d\'un port).'
            . ' Wazi refuse la requête. Un navigateur n\'envoie jamais cela.',
            ValuePreview::of($host),
        ), 400);
    }

    public static function untrustedHost(string $host): self
    {
        return new self(sprintf(
            'La requête est adressée à l\'hôte « %s », qui ne fait pas partie des hôtes de confiance.'
            . ' Wazi la refuse : l\'en-tête Host est écrit par le client, et un faux hôte peut détourner'
            . ' les liens que votre application génère. Si cet hôte est bien le vôtre, ajoutez-le :'
            . ' new ServerRequestCreator(trustedHosts: [\'exemple.com\']).',
            ValuePreview::of($host),
        ), 400);
    }

    public static function malformedUploadedFiles(): self
    {
        return new self(
            'La liste des fichiers envoyés n\'a pas la forme attendue (celle de $_FILES :'
            . ' les clés « tmp_name », « error », « size », « name », « type »).'
            . ' Passez $_FILES tel que PHP le fournit, sans le modifier.',
            400,
        );
    }
}
