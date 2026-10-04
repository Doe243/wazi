# 10. Les erreurs

Une application échoue de deux façons, que Wazi traite différemment.

- **Une réponse normale à une requête qui ne peut pas aboutir** : page introuvable (404), accès refusé (403), requête incorrecte (400). Ce n'est pas une panne.
- **Une panne** : une exception que personne n'attendait. Le visiteur reçoit une réponse 500.

Dans les deux cas, votre code n'a rien à écrire : toute exception qui remonte devient une page d'erreur.

## Ce que voit le visiteur

### En production (le mode par défaut)

Une page neutre : le code, un titre, une phrase. Pour une panne, une **référence** en plus :

```text
500 — Erreur interne
Une erreur s'est produite de notre côté. Elle a été enregistrée.
Référence de l'erreur : d57153affdcd7daf
```

Rien d'autre. Ni le message de l'exception, ni un nom de fichier, ni le nom du framework : tout cela renseignerait quelqu'un qui cherche une faille. Le visiteur peut vous transmettre la référence, et vous retrouvez l'erreur dans le journal.

### En développement

```php
$app = new Kernel(development: true);
```

La même page, avec ce qu'il faut pour corriger :

| | |
| --- | --- |
| **Ce qui s'est passé** | Le message de l'erreur |
| **Type de l'erreur** | La classe de l'exception |
| **Où, dans votre code** | Le fichier et la ligne de **votre** code, pas ceux de Wazi |
| **Référence dans le journal** | Pour retrouver le compte rendu complet |

Même en développement, le navigateur ne montre **jamais** la trace complète, la valeur d'une variable ou un réglage. C'est vrai aussi de la [barre de débogage](15-barre-de-debogage.md). Le détail est dans le journal. Wazi ne fournit aucun outil de débogage accessible par le navigateur : ce genre d'outil, oublié en ligne, est une porte ouverte.

Ce mode n'est jamais deviné. Reliez-le à un réglage, faux par défaut :

```php
$app = new Kernel(development: $config->bool('APP_DEBUG', false));
```

## Le journal

Le compte rendu complet d'une erreur y est écrit : le message, puis la suite des appels qui y a mené.

```text
[wazi] Erreur d57153affdcd7daf — 500 — RuntimeException : Le fichier des notes n'a pas pu être ouvert. (dans .../src/Carnet.php, ligne 152)
  #0 .../src/Carnet.php:48 Demo\Carnet->modifierLeFichier()
  #1 .../src/NoteController.php:60 Demo\Carnet->ajouter()
```

Où le lire :

- avec le serveur de développement (`wazi serve`), **dans le terminal** où il tourne ;
- en ligne, dans le fichier indiqué par le réglage `error_log` du `php.ini`, ou dans le journal du serveur web.

Ce qui y est écrit :

- en production, seulement les **pannes** (codes 500 et suivants). Une page introuvable n'est pas un événement à consigner ;
- en développement, tout.

La trace ne contient jamais les **arguments** des fonctions : un mot de passe passé à une fonction n'a rien à faire dans un journal.

Pour écrire ailleurs (un fichier de votre application, un service de surveillance), implémentez `ErrorLog` :

```php
use Wazi\Errors\ErrorHandler;
use Wazi\Errors\ErrorLog;

final class JournalFichier implements ErrorLog
{
    public function write(string $entry): void
    {
        file_put_contents(__DIR__ . '/../var/erreurs.log', date('c') . ' ' . $entry . "\n", FILE_APPEND | LOCK_EX);
    }
}

$app = new Kernel(errorHandler: new ErrorHandler($config->bool('APP_DEBUG', false), new JournalFichier()));
```

## Ce que Wazi transforme en erreur

Wazi ne laisse pas PHP continuer après un problème :

- un **avertissement** de PHP (une clé de tableau absente, une division par zéro) devient une exception, donc une page d'erreur. Un avertissement ignoré est souvent un bogue qui ressortira plus loin, là où il sera plus difficile à comprendre ;
- une **erreur fatale** (mémoire épuisée, temps dépassé) donne une page 500, pas une page blanche ;
- un `echo` oublié avant la réponse est signalé ;
- une **dépréciation** (un avertissement sur une future version de PHP) va au journal sans interrompre la page.

PHP n'affiche jamais lui-même une erreur dans la page : tout passe par Wazi, qui décide de ce que voit le visiteur.

## Répondre vous-même par une erreur

### Avec une page à vous

Le plus simple, et le plus courant :

```php
$article = $this->catalogue->trouver($id);

if ($article === null) {
    return $this->kioo->page('articles/introuvable', ['id' => $id], 404);
}
```

### Avec une exception

Pour interrompre le traitement depuis un service ou un middleware, levez une exception qui implémente `HttpError` : elle connaît son code de statut.

```php
use Wazi\Contracts\HttpError;

final class AccesRefuse extends \RuntimeException implements HttpError
{
    public function getStatusCode(): int
    {
        return 403;
    }

    public function getResponseHeaders(): array
    {
        return [];
    }
}
```

```php
throw new AccesRefuse('Seul l\'auteur peut modifier cette note.');
```

Le visiteur reçoit la page 403 de Wazi. Le message de l'exception n'est montré qu'en développement.

## Lire une erreur de Wazi

Les erreurs de Wazi sont écrites pour être lues. Chacune dit **ce qui s'est passé, pourquoi, et comment corriger** :

```text
La variable « nomm » n'existe pas dans ce template. Vouliez-vous écrire « nom » ?
Vérifiez son nom, et qu'elle est bien donnée au template. Pour prévoir son absence,
écrivez {nomm ?? 'valeur par défaut'}.
```

Lisez le message jusqu'au bout : la correction est presque toujours dans la dernière phrase.

## Une page ne répond pas comme prévu

Avant de chercher dans le code, demandez à Wazi ce que l'adresse traverse :

```bash
wazi explain notes/3
```

La commande montre la route choisie, chaque middleware traversé, et le code exécuté. Elle dit aussi pourquoi une adresse donne 404 ou 405. Voir [La console](13-console.md).

## Les limites

L'apparence des pages d'erreur n'est pas encore réglable : elles reprennent les couleurs de Wazi, sans logo ni nom, et suivent le thème du visiteur, clair ou sombre.

Suite : [La sécurité](11-securite.md).
