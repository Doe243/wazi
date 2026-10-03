# ADR-023 : jeton CSRF dans un cookie, sans session

**Statut :** acceptée — remplace la partie « protection CSRF » de l'ADR-021

## Contexte
L'ADR-021 rangeait le jeton CSRF dans la session. Conséquence : afficher un formulaire à un visiteur anonyme (connexion, contact, recherche en POST) créait une session, donc un fichier sur le disque et un cookie de session. Un robot qui parcourt le site créait un fichier par page vue ; et la protection CSRF n'existait que si les sessions étaient activées.

## Options envisagées
- Garder le jeton en session et nettoyer plus souvent : le coût reste, la protection dépend toujours des sessions.
- Un jeton signé avec une clé secrète de l'application (HMAC) : aucun état côté serveur, mais il faut une clé secrète à créer, ranger et renouveler — un piège de plus pour un débutant, avant même d'afficher un formulaire.
- Se fier uniquement à `SameSite=Lax` ou aux en-têtes `Origin` / `Sec-Fetch-Site` : dépend du navigateur, et ne s'explique pas simplement.
- Le « double envoi » : le jeton est dans un cookie, le formulaire le répète, le serveur compare.

## Décision
1. **Double envoi.** `Wazi\Http\CsrfToken` porte le jeton de la requête (256 bits de hasard). Le middleware `CsrfCookie` lit le cookie à l'aller et l'envoie au retour ; `CsrfProtection` compare le champ `_csrf` (ou l'en-tête `X-CSRF-Token`) au cookie avec `hash_equals()`.
2. **Le cookie n'est créé que si une page en a besoin** : un visiteur qui ne voit aucun formulaire POST ne reçoit aucun cookie. Aucun fichier n'est jamais créé.
3. **Attributs du cookie :** `HttpOnly`, `SameSite=Lax`, `Path=/`, sans durée (il disparaît à la fermeture du navigateur). En HTTPS : `Secure` et le nom `__Host-csrf` ; en HTTP : `csrf`. En HTTPS, seul le cookie préfixé est lu.
4. **Un jeton créé pendant la requête ne valide rien** : sans cookie reçu, toute requête qui modifie est refusée.
5. **Un cookie mal formé est traité comme absent** : il n'est ni comparé, ni recopié dans une page.
6. **La protection ne dépend plus des sessions** : elle est active pour toutes les routes de toute application. La seule sortie reste `WithoutCsrf`, route par route (ADR-021, inchangé).
7. `Session::csrfToken()` et `Session::CSRF_FIELD` sont retirés ; Kioo reçoit le `CsrfToken`. Les clés de session commençant par `_` restent réservées à Wazi.

## Pourquoi
- La page piégée d'un autre site peut faire partir le cookie, mais ne peut ni le lire ni le fixer : elle ne sait pas quoi écrire dans le champ.
- Le préfixe `__Host-` comble la faiblesse connue du double envoi : sans lui, un sous-domaine (ou une page non chiffrée du même domaine) peut poser un cookie de son choix, puis envoyer un formulaire portant le même jeton. Avec lui, le navigateur refuse ces cookies.
- `SameSite=Lax` est une deuxième barrière : sur un navigateur récent, le cookie ne part même pas avec un formulaire venu d'un autre site.
- Aucune clé secrète à gérer, aucun fichier, aucune configuration.

## Conséquences
- **Limite assumée, en HTTP :** sans `__Host-`, quelqu'un qui contrôle un sous-domaine ou le réseau peut fixer le cookie. Mais en HTTP, celui qui contrôle le réseau peut déjà tout lire et tout modifier. La documentation dira : un site en production est en HTTPS (et derrière un proxy, déclarer `trustedProxies`, ADR-022, sinon Wazi croit le site en HTTP).
- Le jeton n'est pas lié à la session et ne change pas à la connexion. Ce n'est pas nécessaire : il ne prouve pas qui est le visiteur, seulement que le formulaire vient d'une page du site.
- Une application sans sessions refuse désormais un POST sans jeton. Une API appelée par d'autres programmes (sans cookie) doit marquer ses routes avec `WithoutCsrf` et vérifier autrement l'origine des requêtes (clé, signature).
- Le cookie étant `HttpOnly`, un script ne peut pas le lire : pour une requête JavaScript, le jeton est donné par la page (le contrôleur passe `CsrfToken::value()` au template).
- Une page contenant un formulaire POST porte un jeton propre au visiteur : elle ne doit pas être mise dans un cache partagé.
