# 7. La configuration

Certaines valeurs changent d'une machine à l'autre (l'adresse du site, le mode développement), et d'autres doivent rester secrètes (un mot de passe, une clé). Elles n'ont pas leur place dans le code : **le code est partagé, les réglages ne le sont pas**.

## Le fichier `.env`

À la racine du projet, à côté de `composer.json` et **jamais dans `public/`** :

```text
# Un commentaire
APP_DEBUG=true
APP_NAME="Mon carnet"
PAGINATION=20
APP_HOSTS=exemple.com, www.exemple.com
MAIL_PASSWORD='p@ss # ce dièse fait partie du mot de passe'
```

Les règles sont peu nombreuses :

- `NOM=valeur`, le nom en majuscules ;
- sans guillemets, la valeur s'arrête à la fin de la ligne, ou à un `#` précédé d'un espace ;
- entre guillemets simples, tout est pris tel quel ;
- entre guillemets doubles, `\n`, `\t`, `\"` et `\\` sont interprétés ;
- une valeur tient sur une ligne.

Une valeur est un **texte**. Rien n'y est jamais exécuté ni remplacé : `${AUTRE}` reste `${AUTRE}`.

### Ce fichier ne se partage pas

Le `.env` contient vos secrets : il ne doit pas entrer dans Git. Ajoutez-le à `.gitignore`, et partagez à la place un fichier `.env.example`, avec les mêmes clés et des valeurs sans danger. Chaque personne le copie sous le nom `.env`.

```text
# .gitignore
/.env
/var/
```

## Lire un réglage

```php
use Wazi\Config\Config;

$config = Config::fromEnvFile(__DIR__ . '/../.env');

$config->string('APP_NAME', 'Mon site');   // un texte
$config->int('PAGINATION', 20);            // un nombre entier
$config->bool('APP_DEBUG', false);         // true ou false
$config->list('APP_HOSTS', []);            // « a.com, b.com » devient ['a.com', 'b.com']
$config->has('MAIL_PASSWORD');             // la clé existe-t-elle ?
```

Dans un `.env`, tout est du texte. Ces méthodes disent quel **type** vous attendez, et le vérifient : `PAGINATION=vingt` donne une erreur claire plutôt qu'un zéro silencieux. Pour un vrai/faux, seuls `true` et `false` sont acceptés.

Le second argument est la **valeur par défaut**. Sans lui, la clé est obligatoire :

```php
$config->string('DATABASE_PASSWORD');   // erreur si la clé manque
```

Un fichier `.env` absent n'est pas une erreur : l'application démarre avec ses valeurs par défaut.

## D'où vient une valeur

Pour chaque clé, dans cet ordre :

1. une **variable d'environnement** du serveur qui porte ce nom ;
2. sinon, la ligne du fichier `.env` ;
3. sinon, la valeur par défaut écrite dans le code.

C'est ce qui permet de mettre en ligne sans fichier `.env` : sur une plateforme d'hébergement ou dans un conteneur, on déclare les réglages dans l'interface de l'hébergeur, et l'application les lit.

### Nommez vos clés avec un préfixe

Le système définit lui-même des variables : `PATH`, `USER`, `HOME`, `LANG`... Une clé qui porterait un de ces noms prendrait la valeur du système. Préfixez les vôtres : `APP_`, `DATABASE_`, `MAIL_`.

Un nom ne peut pas commencer par `HTTP_` : sur certains serveurs, ces variables sont fabriquées à partir de la requête, donc choisies par le visiteur.

## Donner un réglage à un service

`Config` se lit dans `public/index.php`, et les valeurs sont **données** aux services qui en ont besoin :

```php
$app->container->set(Messagerie::class, fn () => new Messagerie(
    $config->string('MAIL_HOST'),
    $config->string('MAIL_PASSWORD'),
));
```

Un service qui reçoit ses réglages par son constructeur dit clairement de quoi il dépend, et se teste sans fichier `.env`.

## Ce que Wazi fait pour vos secrets

- Les valeurs du fichier restent **dans l'objet `Config`**. Elles ne sont jamais copiées dans `$_ENV`, `$_SERVER` ou `putenv()`, où un autre programme pourrait les lire.
- Un `.env` placé dans le dossier public est **refusé** : il serait téléchargeable.
- `var_dump($config)` montre le nom des clés, pas leurs valeurs.
- Aucun message d'erreur ne contient une valeur ; une ligne mal écrite est désignée par son numéro.

Dans votre propre code, marquez un argument qui reçoit un secret pour que PHP le masque dans les traces d'erreur :

```php
public function __construct(#[\SensitiveParameter] private string $motDePasse) {}
```

Suite : [Les sessions](08-sessions.md).
