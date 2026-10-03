# ADR-021 : sessions en fichiers JSON et protection CSRF par défaut

**Statut :** acceptée — partie « protection CSRF » remplacée par l'ADR-023 ; complétée par l'ADR-024

## Contexte
Une application a besoin de reconnaître un visiteur d'une page à l'autre (connexion, panier), et de protéger ses formulaires contre la falsification de requête (CSRF). L'ADR-006 demande des cookies `HttpOnly`, `Secure`, `SameSite=Lax`, une protection CSRF active par défaut, et une sortie explicite et locale. Il interdit aussi `unserialize()` sur des données manipulables.

## Options envisagées

**Sessions**
- Les sessions natives de PHP (`session_start()`, `$_SESSION`) : éprouvées, mais elles passent par une variable globale, écrivent elles-mêmes leurs en-têtes hors de la réponse PSR-7, et relisent leurs fichiers avec `unserialize()`.
- Des sessions maison : un objet `Session`, un middleware, un rangement en fichiers JSON.

**Où ranger les fichiers**
- Le dossier temporaire du système, sans rien configurer : sur un hébergement mutualisé, il est partagé avec d'autres sites.
- Un dossier de l'application, donné explicitement.

**Dispenser une route de la protection CSRF**
- Une liste d'adresses au même endroit : c'est un interrupteur central, facile à allonger sans y penser.
- Un attribut `#[WithoutCsrf]` sur la route : il faudrait que la protection connaisse le routeur, ce que la règle des couches interdit.
- Un middleware `WithoutCsrf` posé sur la route.

**Mettre le jeton dans les formulaires**
- Une balise à écrire dans chaque formulaire : un oubli laisse le formulaire sans protection, ou le fait échouer.
- Kioo l'ajoute de lui-même.

## Décision

### Sessions
1. `Wazi\Http\Session` porte les données de la session. Elle vit dans `Http` car `Middleware` (qui la remplit et l'enregistre) et `View` (qui en lit le jeton) s'en servent tous deux.
2. `new Kernel(sessions: $dossier)` active les sessions. Sans cet argument, il n'y a ni session ni protection CSRF.
3. Le contenu est écrit en JSON et relu par `json_decode()`. Une session n'accepte que des textes, des nombres, des booléens, `null` et des tableaux de ces valeurs.
4. Identifiant : 32 octets au hasard. Un identifiant reçu n'est utilisé que s'il a exactement la forme attendue, et n'est adopté que s'il correspond à une session connue.
5. Cookie `session` : `HttpOnly`, `SameSite=Lax`, `Path=/`, et `Secure` quand la requête est en HTTPS.
6. Un visiteur dont la session est vide ne reçoit ni cookie ni fichier.
7. `regenerate()` change l'identifiant et supprime l'ancien fichier ; `clear()` vide la session et efface le cookie.
8. Une session inutilisée depuis deux heures expire. Un dossier de sessions situé dans le dossier public est refusé.

### Protection CSRF
9. `CsrfProtection` s'applique à toutes les routes : toute méthode autre que GET, HEAD ou OPTIONS doit porter le jeton de la session, dans le champ `_csrf` ou dans l'en-tête `X-CSRF-Token`. Sinon : 403.
10. Elle s'exécute dans le routeur, après les middlewares de chaque route. Le routeur accepte pour cela une liste de middlewares valables pour toutes les routes.
11. `[WithoutCsrf::class]`, dans les middlewares d'une route, la dispense de la vérification.
12. Kioo ajoute le champ caché à chaque `<form>` écrit dans un template dont la méthode est `post` et dont l'adresse reste sur le site. Jamais à un formulaire en GET, ni à un formulaire qui part vers un autre site.

## Pourquoi
- Sans `unserialize()`, aucun objet ne peut être reconstruit à partir d'un fichier de session : toute une famille de failles disparaît.
- Un objet `Session` se demande dans un constructeur et se suit dans l'IDE ; `$_SESSION` est modifiable par n'importe quel code (principe 1).
- Le dossier explicite évite de ranger des sessions là où un autre site pourrait les lire.
- `WithoutCsrf` est la sortie « explicite, nommée et locale » de l'ADR-006 : elle se lit dans la déclaration de la route.
- L'ajout automatique du jeton rend la protection invisible pour le développeur, et un formulaire ne peut pas être oublié.

## Conséquences
- Pas de verrou entre deux requêtes simultanées du même visiteur : la dernière écriture l'emporte.
- La session est réécrite à chaque requête qui en a une, pour repousser son expiration.
- Toute page contenant un formulaire `post` crée une session et pose un cookie.
- Derrière un proxy qui termine le HTTPS, la requête est vue en `http` (ADR-008) : le cookie n'a alors pas l'attribut `Secure`. À régler avec les proxies de confiance.
- Pour garder un objet d'une page à l'autre, on range son identifiant, pas l'objet.
- Un formulaire écrit hors d'un template Kioo, ou une requête JavaScript, doit apporter le jeton lui-même (`$session->csrfToken()`).
- Les formulaires en `PUT`, `PATCH` ou `DELETE` envoyés par JavaScript passent le jeton par l'en-tête : `$_POST` n'est rempli que pour `POST`.
- Pas de messages « flash » ni de « se souvenir de moi » pour l'instant.
