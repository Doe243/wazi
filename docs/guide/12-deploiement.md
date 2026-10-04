# 12. Mettre en ligne

Le serveur de développement (`wazi serve`) sert à développer, pas à recevoir des visiteurs. En ligne, il faut un vrai serveur web, et quelques réglages.

## La liste de contrôle

Avant d'ouvrir le site :

- [ ] Le serveur web ne sert **que** le dossier `public/`.
- [ ] `APP_DEBUG` vaut `false`, ou n'est pas défini.
- [ ] Le site est en **HTTPS**.
- [ ] `APP_HOSTS` contient les noms du site.
- [ ] Derrière un proxy : `APP_TRUSTED_PROXIES` contient ses adresses.
- [ ] Le dossier `var/` est inscriptible par PHP, et par lui seul.
- [ ] `composer install --no-dev --optimize-autoloader` a été lancé.
- [ ] `wazi db:migrate` a été lancé : la base a toutes ses tables.
- [ ] `wazi views:compile` a été lancé, et le dossier `build/` n'est pas inscriptible par le serveur web.
- [ ] `composer audit` ne signale rien.
- [ ] Vous savez où lire le journal des erreurs.

Chaque point est expliqué ci-dessous.

## Le dossier public

Le serveur web doit avoir pour racine le dossier `public/` du projet, pas le projet lui-même. Sinon, `.env`, le code et les sessions deviennent téléchargeables. Wazi refuse d'ailleurs de lire un `.env` ou d'écrire des sessions dans la racine du site.

Toute adresse qui ne correspond pas à un fichier existant doit être confiée à `index.php`.

**nginx**, avec PHP-FPM :

```nginx
server {
    listen 443 ssl;
    server_name exemple.com;
    root /var/www/mon-projet/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
    }

    # Aucun autre fichier PHP ne s'exécute.
    location ~ \.php$ {
        return 404;
    }
}
```

**Apache**, dans un fichier `public/.htaccess` (le module `mod_rewrite` doit être actif, et la racine du site réglée sur `public/`) :

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]
```

Adaptez les chemins et la version de PHP à votre serveur.

## Les réglages

En ligne, les réglages se donnent de deux façons, au choix :

- un fichier `.env` posé sur le serveur, à la racine du projet (hors de `public/`), lisible par PHP seulement ;
- des **variables d'environnement**, déclarées dans l'interface de l'hébergeur ou du conteneur. Elles passent avant le fichier, et il n'y a alors aucun `.env` en ligne.

Voir [La configuration](07-configuration.md).

### Le mode développement

`APP_DEBUG=false`, ou rien. En mode développement, chaque visiteur verrait le message de vos erreurs et le chemin de vos fichiers.

## Les hôtes de confiance

Une requête annonce le nom du site qu'elle veut joindre. Rien n'empêche un attaquant d'en annoncer un autre, pour que votre application fabrique des liens vers son site (dans un courriel de réinitialisation de mot de passe, par exemple).

Déclarez les noms sous lesquels votre site répond :

```text
APP_HOSTS=exemple.com, www.exemple.com
```

```php
new ServerRequestCreator(trustedHosts: $config->list('APP_HOSTS', []))
```

Une requête pour un autre nom reçoit une réponse 400. Sans cette liste, tout nom bien formé est accepté : pratique sur votre ordinateur, à ne pas laisser ainsi en ligne.

## HTTPS

Obtenez un certificat (gratuit avec Let's Encrypt, souvent fourni par l'hébergeur) et redirigez tout le trafic HTTP vers HTTPS, dans la configuration du serveur web.

Quand le site est en HTTPS, Wazi le voit et renforce de lui-même les cookies :

- le cookie de session reçoit l'attribut `Secure` : il ne voyage jamais en clair ;
- le cookie du jeton des formulaires prend le nom `__Host-csrf`, que personne d'autre que votre site ne peut fixer.

## Derrière un proxy

Sur la plupart des hébergements modernes, un **proxy** se trouve devant PHP : répartiteur de charge, Cloudflare, nginx ou Traefik devant un conteneur. C'est lui qui reçoit la connexion HTTPS du visiteur ; il parle ensuite à PHP en HTTP, et transmet les informations d'origine dans des en-têtes `X-Forwarded-*`.

Sans réglage, Wazi **ignore ces en-têtes** : n'importe quel visiteur peut les écrire. Il croit alors le site en HTTP, et l'adresse IP qu'il voit est celle du proxy. Conséquences : cookies sans `Secure`, jeton des formulaires moins protégé.

Déclarez les adresses de **vos** proxies :

```text
APP_TRUSTED_PROXIES=10.0.0.5
APP_TRUSTED_PROXIES=10.0.0.0/8, 172.16.0.0/12
```

```php
new ServerRequestCreator(trustedProxies: $config->list('APP_TRUSTED_PROXIES', []))
```

Les en-têtes ne sont alors crus que si la requête arrive d'une de ces adresses. Votre code lit l'adresse du visiteur ainsi :

```php
$request->getAttribute('client_ip');
```

**Comment savoir si vous êtes derrière un proxy ?** Si, en ligne et en HTTPS, le cookie de session n'a pas l'attribut `Secure` (visible dans les outils du navigateur, touche F12, onglet Application ou Stockage), Wazi croit le site en HTTP : il y a un proxy à déclarer. Son adresse est dans la documentation de votre hébergeur.

Ne déclarez jamais une plage plus large que nécessaire, et jamais « toutes les adresses » : ce serait croire tout le monde.

## Le dossier `var/`

PHP doit pouvoir y écrire (sessions, fichiers de l'application), et personne d'autre ne doit pouvoir y lire. Sur un serveur Linux :

```bash
chown -R www-data:www-data var
chmod -R 700 var
```

Remplacez `www-data` par le compte qui fait tourner PHP sur votre serveur.

Ce dossier contient des données, pas du code : il n'entre pas dans Git, et il ne faut pas l'écraser à chaque mise à jour du site.

## Installer les dépendances

```bash
composer install --no-dev --optimize-autoloader
```

`--no-dev` n'installe pas les outils de développement (tests, analyse) : moins de code en ligne, moins de surface d'attaque.

## Mettre la base à jour

```bash
wazi db:migrate
```

À lancer à chaque mise en ligne, après avoir déposé le code : la base reçoit les tables et les colonnes ajoutées depuis la dernière fois. La relancer ne fait rien de plus. Voir [La base de données](14-base-de-donnees.md).

Le mot de passe de la base se donne par la variable d'environnement `DATABASE_URL` du serveur, ou par le fichier `.env` : jamais dans le code.

Avec SQLite, la base est un fichier du dossier `var/`. PHP doit pouvoir écrire dans ce fichier **et** dans son dossier. Si vous lancez `wazi db:migrate` sous un autre compte que celui de PHP, redonnez ensuite le dossier à PHP (`chown`, comme ci-dessus). Et pensez à sauvegarder ce fichier : c'est toute votre base.

## Préparer les templates

Pendant que vous développez, chaque template est analysé à chaque requête : vous modifiez, vous rechargez, c'est à jour. En ligne, ce travail répété coûte quelques millisecondes par page. La console le fait une fois pour toutes :

```bash
wazi views:compile
```

```text
  accueil
  base
  contact
  partiels/pied

OK  4 template(s) préparé(s).
```

Chaque template est analysé, et le résultat est écrit dans un fichier du dossier `build/views`. PHP garde ces fichiers en mémoire : les pages s'affichent plus vite. Sur l'application de démonstration, la page d'accueil passe de 4,8 à 0,9 ms.

Le dossier se règle dans `app.php` :

```php
$app = new Kernel(
    views: __DIR__ . '/views',
    compiledViews: __DIR__ . '/build/views',
);
```

Ce qu'il faut savoir :

- **la commande vérifie tous vos templates.** Une faute dans l'un d'eux l'arrête, avec le nom du template et la ligne. Lancez-la dans votre script de déploiement : un template cassé n'arrivera pas en ligne ;
- **une page n'est jamais périmée.** Si vous modifiez un template sans relancer la commande, Wazi le voit (sa date et sa taille ont changé) et l'analyse comme d'habitude. Vous perdez la vitesse, pas la justesse ;
- **relancez la commande à chaque mise à jour du site.**

### Le dossier `build/` ne doit pas être inscriptible par le serveur web

Les fichiers préparés sont du PHP : ce qu'ils contiennent s'exécute. Si le serveur web pouvait écrire dans ce dossier, une faille de votre site qui permettrait à un visiteur d'y déposer un fichier lui permettrait d'exécuter du code.

Wazi ne se contente pas de vous le demander : **il le vérifie à chaque requête**. Si PHP a le droit d'écrire dans le dossier, il refuse de s'en servir et analyse les templates comme en développement. Le pire qui arrive à qui l'oublie est un site plus lent.

Sur un serveur Linux, en supposant que vous déployez avec le compte `deploy` et que PHP tourne sous `www-data` :

```bash
wazi views:compile
chown -R deploy:deploy build
chmod -R a-w,a+rX build
```

C'est l'inverse du dossier `var/`, qui doit, lui, être inscriptible par PHP. C'est pourquoi ce sont deux dossiers distincts.

Si votre hébergement fait tourner PHP sous le compte qui dépose les fichiers (c'est fréquent sur un hébergement mutualisé), le dossier sera toujours inscriptible, et les templates préparés ne serviront pas. Vous pouvez l'accepter en connaissance de cause, par un geste explicite : `unsafeAllowWritableCompiledViews: true` dans `app.php`.

Si votre serveur est réglé pour ne jamais revérifier les fichiers PHP (`opcache.validate_timestamps=0`), videz OPcache après la commande, comme après toute mise à jour du code.

## Le journal des erreurs

En production, le visiteur ne voit qu'une référence. Le détail est dans le journal de PHP : repérez où il se trouve sur votre serveur (réglage `error_log` du `php.ini`, ou journal du serveur web) **avant** d'en avoir besoin. Voir [Les erreurs](10-erreurs.md).

## Les limites actuelles

- **Un seul serveur.** Les sessions sont rangées dans des fichiers locaux : une application répartie sur plusieurs serveurs ne partagerait pas ses sessions.
- **L'affichage des pages reste calculé à chaque requête**, même avec des templates préparés. Pour un site à fort trafic, placez un cache HTTP devant les pages publiques, en excluant celles qui contiennent un formulaire ou des données d'un visiteur.

Suite : [La console](13-console.md).
