<?php

declare(strict_types=1);

namespace Wazi\Http;

/**
 * Le jeton du jour pour les scripts : une valeur tirée au hasard à chaque requête.
 *
 * La politique de sécurité de contenu (CSP) de Wazi interdit les scripts
 * écrits dans la page, parce que c'est la forme que prend une attaque XSS.
 * Mais VOS scripts, eux, sont légitimes. Le jeton les distingue :
 *
 *   1. l'en-tête de la réponse dit au navigateur : « n'exécute que les
 *      scripts qui portent le jeton abc123 » ;
 *   2. Kioo écrit nonce="abc123" sur chaque balise <script> de vos templates ;
 *   3. un script injecté par un attaquant ne porte pas le jeton : il ne peut
 *      pas le deviner, puisqu'il change à chaque requête. Le navigateur le refuse.
 *
 * (« Nonce » vient de l'anglais « number used once » : nombre à usage unique.)
 *
 * Cette classe vit dans Http, la couche du bas, parce que deux composants s'en
 * servent : Middleware (qui écrit l'en-tête) et View (qui écrit les balises).
 *
 * Sécurité : avec PHP, chaque requête démarre un programme neuf, donc un jeton
 * neuf. Si un jour Wazi tourne dans un serveur qui garde le programme en vie
 * entre deux requêtes, il faudra créer un jeton par requête.
 */
final readonly class CspNonce
{
    public string $value;

    public function __construct()
    {
        // 16 octets au hasard, écrits avec des caractères sûrs dans un en-tête
        // comme dans un attribut HTML.
        $this->value = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
