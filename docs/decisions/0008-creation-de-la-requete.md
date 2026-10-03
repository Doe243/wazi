# ADR-008 : ce que Wazi croit, ou non, dans la requête reçue

**Statut :** acceptée

## Contexte
`ServerRequestCreator` construit la `ServerRequest` à partir des variables globales de PHP. Tout ce qu'il lit est écrit par le client : la taille annoncée du corps, l'en-tête `Host`, les en-têtes `X-Forwarded-*`. Trois questions se posent, et chacune oppose le principe 2 (zéro configuration) au principe 8 (sécurité stricte).

## Options envisagées

**Taille du corps**
- Ne rien limiter et s'en remettre au `php.ini` : le débutant ne le maîtrise pas (hébergement mutualisé), et la limite peut y être très haute.
- Une limite propre à Wazi, avec une valeur par défaut.

**En-tête `Host`**
- Exiger une liste d'hôtes de confiance : le plus sûr, mais plus rien ne démarre sans configuration.
- Remplacer `Host` par `SERVER_NAME` : protection illusoire, car Apache y recopie par défaut l'en-tête `Host`.
- Accepter tout hôte dont la forme est valide, et refuser les autres dès qu'une liste est déclarée.

**En-têtes de proxy (`X-Forwarded-Proto`, `X-Forwarded-Host`, `Forwarded`)**
- Les croire : n'importe quel client peut les écrire.
- Les ignorer tant qu'aucun proxy de confiance n'est déclaré.

## Décision
1. **Corps :** une requête qui annonce un corps de plus de `maxBodySize` octets est refusée avant d'être lue (413). Valeur par défaut : 8 Mo. Un `Content-Length` qui n'est pas un entier positif est refusé (400).
2. **Hôte :** l'en-tête `Host` doit être un hôte suivi éventuellement d'un port, vérifié par l'analyseur d'URI natif ; sinon la requête est refusée (400). Sans liste `trustedHosts`, tout hôte valide est accepté. Avec une liste, tout autre hôte est refusé (400). La comparaison est exacte : pas de joker, pas d'expression régulière.
3. **Proxies :** les en-têtes `X-Forwarded-*` et `Forwarded` ne servent jamais à construire la requête. Le schéma vient de `$_SERVER['HTTPS']`. Ils restent lisibles dans `getHeaders()`.
4. **Méthode :** aucune méthode de remplacement (champ `_method`, en-tête `X-HTTP-Method-Override`).
5. Un refus lève `RequestRejectedException`, qui porte le code de statut à répondre.

## Pourquoi
- Une limite visible dans le code, avec un message qui dit comment la changer, vaut mieux qu'un réglage caché du serveur.
- Pour l'hôte, la liste obligatoire casserait le démarrage en cinq minutes. La validation de forme écarte les attaques par `Host` mal formé ; la liste, optionnelle, écarte les autres. C'est le seul point où le défaut n'est pas le plus strict possible.
- Ignorer les en-têtes de proxy est le défaut sûr : y croire à tort permet de falsifier le schéma et l'hôte.

## Conséquences
- **Risque accepté :** sans `trustedHosts`, une application qui génère des liens absolus à partir de l'hôte de la requête (courriel de réinitialisation de mot de passe) reste exposée à un `Host` falsifié. Le `Kernel` devra exiger `trustedHosts` ou avertir quand l'application tourne en production ; à trancher avec le composant `Config` (0.3).
- Derrière un proxy ou un répartiteur de charge qui termine le HTTPS, l'URI de la requête sera en `http`. La déclaration de proxies de confiance reste à écrire, par un nouvel ADR, quand le besoin se présentera.
- La limite ne porte que sur la taille annoncée. `post_max_size` du `php.ini` doit rester supérieur ou égal à `maxBodySize`, sinon PHP vide `$_POST` et `$_FILES` sans prévenir.
- Les formulaires qui veulent simuler `PUT` ou `DELETE` devront utiliser `POST`.
