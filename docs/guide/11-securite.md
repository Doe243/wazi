# 11. La sécurité

Le principe de Wazi : **le réglage par défaut est le réglage sûr**. Ce qui est dangereux demande un geste explicite, nommé, et posé à un endroit précis. Chaque refus explique pourquoi.

Cette page fait le point : ce que Wazi fait sans vous, puis ce qui reste à votre charge. Aucun framework ne rend une application sûre à lui seul.

## Ce que Wazi fait pour vous

| Attaque | Ce qu'elle cherche | La protection | Détail |
| --- | --- | --- | --- |
| Injection de code dans une page (XSS) | Faire exécuter un script par le navigateur d'un autre visiteur | Kioo échappe tout ce qu'il affiche ; la politique de sécurité refuse les scripts qui ne viennent pas de vous | [Kioo](06-kioo.md), [Middlewares](05-middlewares.md) |
| Falsification de requête (CSRF) | Faire agir un visiteur connecté à son insu | Un jeton vérifié sur toute requête qui modifie | [Formulaires](09-formulaires.md) |
| Vol ou fixation de session | Prendre la place d'un visiteur connecté | Cookie `HttpOnly`, `SameSite`, `Secure` ; identifiant impossible à deviner, jamais adopté s'il vient d'ailleurs | [Sessions](08-sessions.md) |
| Piégeage de clics | Afficher votre site dans un cadre invisible | `X-Frame-Options: DENY` | [Middlewares](05-middlewares.md) |
| Fuite d'informations | Lire un message d'erreur, une trace, un réglage | Mode production par défaut ; aucun outil de débogage par le navigateur | [Erreurs](10-erreurs.md) |
| Fuite de secrets | Télécharger le `.env` ou les sessions | Refus de démarrer s'ils sont dans le dossier public | [Configuration](07-configuration.md) |
| Fuite par le code source des pages | Lire vos commentaires de travail | Les commentaires d'un template ne sont jamais écrits dans la page | [Kioo](06-kioo.md) |
| Adresses piégées | Remonter dans les dossiers avec `..` | Une adresse dont un segment est dangereux ne correspond à aucune route | [Routes](02-routes.md) |
| Requêtes démesurées | Saturer le serveur | Contenu refusé au-delà de 8 Mo | [Requêtes](04-requetes-et-reponses.md) |
| En-têtes falsifiés | Se faire passer pour un autre hôte, une autre adresse IP | Hôte validé ; `X-Forwarded-*` ignorés sauf depuis vos proxies déclarés | [Mettre en ligne](12-deploiement.md) |
| Falsification de journaux | Glisser de fausses lignes dans le journal | Les valeurs venues d'un visiteur sont nettoyées avant d'y entrer | [Erreurs](10-erreurs.md) |

## Les sorties, et leur nom

Désactiver une protection est possible, mais cela se voit dans le code. Cherchez ces mots dans un projet pour savoir où il prend des risques :

| Ce qu'on écrit | Ce qu'on désactive |
| --- | --- |
| `{valeur \| unsafe_raw}` | L'échappement de Kioo, pour cette valeur |
| `[WithoutCsrf::class]` | La vérification du jeton, pour cette route |
| `new Kernel(development: true)` | La discrétion des pages d'erreur |
| `new Kernel(securityHeaders: null)` | Les en-têtes de sécurité |
| `SecurityHeaders::WITHOUT_POLICY` | La politique de sécurité du contenu |
| `Config::fromEnvFile($f, unsafeAllowPublicLocation: true)` | Le refus d'un `.env` dans le dossier public |

Il n'existe aucun interrupteur qui désactive une protection « partout ».

## Ce qui reste à votre charge

### Vérifier ce qui entre

Tout ce qui vient d'une requête peut être n'importe quoi : champs, paramètres d'adresse, cookies, en-têtes, fichiers. Vérifiez la présence, le type, la longueur, et l'appartenance à une liste quand il y en a une.

### Vérifier les droits, pas seulement la connexion

« Est-il connecté ? » ne suffit pas. Pour chaque objet, demandez aussi : **« est-ce bien à lui ? »**

```php
// ⚠ N'importe quel visiteur connecté peut supprimer n'importe quelle note.
$this->carnet->supprimer($id);

// La note n'est supprimée que si elle appartient à celui qui le demande.
$this->carnet->supprimer($id, $auteur);
```

C'est l'erreur la plus répandue dans les applications web. Le plus sûr est de la rendre impossible : dans la démonstration, chaque méthode de [`Carnet`](../../examples/demo/src/Carnet.php) exige l'auteur.

### Les mots de passe

- `password_hash()` pour en garder l'empreinte, `password_verify()` pour comparer. Jamais le mot de passe lui-même, jamais `md5()` ou `sha1()`.
- Un message d'échec qui ne dit pas si le compte existe.
- Une limite au nombre d'essais : Wazi ne la fournit pas.

### Comparer un secret

Jeton, signature, clé : avec `hash_equals()`, jamais avec `==`. La durée d'une comparaison ordinaire révèle peu à peu la bonne valeur.

### Les fichiers reçus

- Vous choisissez le nom et l'extension du fichier enregistré, jamais le visiteur.
- Rangez-les hors de `public/`, ou dans un dossier où le serveur n'exécute pas le PHP.
- Vérifiez le contenu, pas le type annoncé par le navigateur.

### Les redirections

Vers une adresse écrite dans votre code, jamais vers une adresse reçue dans la requête.

### La base de données

Quand vous en utiliserez une : **des requêtes préparées**, toujours. Ne construisez jamais une requête SQL en y collant une valeur.

```php
$requete = $pdo->prepare('SELECT * FROM notes WHERE auteur = ?');
$requete->execute([$auteur]);
```

### Vos dépendances

Chaque bibliothèque installée est du code que vous exécutez. Avant de mettre en ligne, et régulièrement ensuite :

```bash
composer audit
```

Cette commande signale les failles connues dans ce que vous avez installé.

### HTTPS

En ligne, un site sans HTTPS n'a pas de sécurité : tout ce qui circule peut être lu et modifié en chemin, cookies compris. Voir [Mettre en ligne](12-deploiement.md).

## Signaler une faille dans Wazi

Ne l'écrivez pas dans un ticket public. La marche à suivre est dans le fichier [`SECURITY.md`](../../SECURITY.md) du dépôt.

Suite : [Mettre en ligne](12-deploiement.md).
