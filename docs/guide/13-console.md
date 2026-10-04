# 13. La console

La console est ce que vous tapez dans un terminal pour agir sur votre projet : lancer le site, afficher des données, et bientôt générer du code.

Elle se tape depuis le dossier de votre projet :

```bash
wazi
```

## Installer la commande `wazi`

Pour que votre terminal connaisse le mot `wazi`, la commande s'installe une fois sur votre ordinateur, avec Composer :

```bash
composer global require wazi/framework
```

> Wazi n'est pas encore publié sur Packagist : cette ligne fonctionnera à ce moment-là. D'ici là, utilisez l'écriture ci-dessous.

Composer range ses commandes globales dans un dossier à lui. Si votre terminal répond que `wazi` est introuvable, c'est que ce dossier n'est pas dans votre `PATH` : `composer global config bin-dir --absolute` vous donne son chemin, à ajouter au `PATH` de votre système.

**Sans installation**, tout fonctionne quand même. Chaque projet contient un fichier `wazi`, à sa racine, et vous pouvez le lancer avec PHP :

```bash
php wazi
```

Les deux écritures font exactement la même chose : la commande `wazi` installée ne fait que passer la main au fichier `wazi` du projet où vous vous trouvez. C'est ce fichier qui déclare les commandes, celles de Wazi et les vôtres.

## La liste des commandes

Sans rien d'autre, la console liste ses commandes :

```text
La console de Wazi

Utilisation : wazi <commande> [arguments] [--options]

Commandes :
  make:controller  Crée un contrôleur et sa page, commentés et prêts à modifier.
  messages         Affiche les messages reçus par le formulaire de contact.
  routes           Liste les routes de l'application : adresse, code exécuté, middlewares.
  serve            Lance le site sur votre ordinateur, pour développer.

Pour le détail d'une commande : wazi <commande> --help
```

## Lancer le site

```bash
wazi serve
```

Le site est servi sur http://localhost:8000. Pour l'arrêter : `Ctrl+C`.

```bash
wazi serve --port=8080
```

C'est le serveur de développement fourni avec PHP. Il sert à développer, pas à recevoir des visiteurs : voir [Mettre en ligne](12-deploiement.md).

Il n'écoute que sur `localhost` : seul votre ordinateur peut l'atteindre. Pour le montrer à un autre appareil du réseau (un téléphone, pour tester), il faut le demander, et la console vous avertit :

```bash
wazi serve --host=192.168.1.20
```

## Voir ses routes

```bash
wazi routes
```

```text
4 route(s), dans l'ordre où le routeur les essaie

GET   /          App\PageController::accueil
GET   /a-propos  App\PageController::aPropos
GET   /contact   App\ContactController::formulaire
POST  /contact   App\ContactController::envoyer
```

Pour chaque route : la méthode, l'adresse, le code qui s'exécute, et les middlewares posés sur elle. L'ordre est celui de vos déclarations, donc celui dans lequel le routeur les essaie : si une adresse à paramètre en masque une autre, cela se voit ici.

La commande lit l'application construite par `app.php`, la même que celle que sert le site.

## Créer un contrôleur

```bash
wazi make:controller Article
```

```text
OK  Créé : src/ArticleController.php
OK  Créé : views/article.kioo

Il reste une ligne à ajouter dans app.php, avec les autres contrôleurs :

    $app->router->addController(\App\ArticleController::class);

Puis ouvrez /article dans votre navigateur.
```

La commande crée deux fichiers : le contrôleur, avec une route, et le template de sa page. Si votre projet a une mise en page `views/base.kioo`, la page s'y place.

Le code créé est **commenté** : chaque ligne dit ce qu'elle fait. Quand vous connaissez ces lignes, demandez-le sans les explications :

```bash
wazi make:controller Article --no-comments
```

Trois règles, pour qu'elle ne puisse rien abîmer :

- **elle ne remplace jamais un fichier existant.** Si l'un des deux existe déjà, elle ne crée rien et vous le dit ;
- **elle ne modifie pas `app.php`.** Elle vous donne la ligne à y ajouter : aucune route n'apparaît sans que vous l'ayez déclarée ;
- **le nom est un nom de classe** : une majuscule, puis des lettres sans accent et des chiffres. `BlogPost` donne l'adresse `/blog-post`.

## Comment s'écrit une commande

Une seule écriture, pour toutes les commandes :

```text
wazi <commande> <argument> --option=valeur --drapeau
```

| Écriture | Sens |
| --- | --- |
| `valeur` | un argument, dans l'ordre attendu par la commande |
| `--port=8080` | une option, et sa valeur collée par un signe égal |
| `--force` | un drapeau : présent ou absent |
| `--help` | l'aide de la commande, valable partout |

Il n'y a pas d'écriture courte (`-p`), ni de valeur séparée par un espace. Une commande refuse tout ce qu'elle n'attend pas, et dit quoi écrire à la place :

```text
Erreur  La commande « serv » n'existe pas. Vouliez-vous écrire « serve » ?
```

## Écrire sa propre commande

Une commande est une classe qui implémente `Command`. Elle déclare son nom, sa description, ce qu'elle accepte, et ce qu'elle fait :

```php
namespace App;

use Wazi\Console\Argument;
use Wazi\Console\Command;
use Wazi\Console\Input;
use Wazi\Console\Option;
use Wazi\Console\Output;

final readonly class BonjourCommand implements Command
{
    public function name(): string
    {
        return 'bonjour';
    }

    public function description(): string
    {
        return 'Salue quelqu\'un.';
    }

    public function arguments(): array
    {
        return [new Argument('nom', 'Qui saluer')];
    }

    public function options(): array
    {
        return [
            new Option('fort', 'Écrire en majuscules'),          // un drapeau
            new Option('fois', 'Combien de fois', '1'),          // une option, avec sa valeur par défaut
        ];
    }

    public function run(Input $input, Output $output): int
    {
        $texte = 'Bonjour ' . $input->argument('nom') . ' !';

        for ($tour = 0; $tour < (int) $input->option('fois'); $tour++) {
            $output->line($input->flag('fort') ? mb_strtoupper($texte) : $texte);
        }

        return 0;
    }
}
```

Puis déclarez-la dans le fichier `wazi` de votre projet, à côté des autres :

```php
$console->add(new BonjourCommand());
```

Si votre commande a besoin d'un service, demandez-le au conteneur de l'application, comme pour un contrôleur :

```php
$console->add(new MessagesCommand($app->container->get(Messagerie::class)));
```

```bash
wazi bonjour Alice --fort --fois=2
```

Le projet de départ en contient un exemple complet, `src/MessagesCommand.php`.

### Arguments et options

| Déclaration | À taper | À lire dans `run()` |
| --- | --- | --- |
| `new Argument('nom', '…')` | `Alice` (obligatoire) | `$input->argument('nom')` |
| `new Argument('formule', '…', 'Bonjour')` | `Salut` (facultatif) | `$input->argument('formule')` |
| `new Option('fois', '…', '1')` | `--fois=3` | `$input->option('fois')` |
| `new Option('fort', '…')` | `--fort` | `$input->flag('fort')` |

Tout ce qui est tapé arrive sous forme de **texte**. Comme pour un formulaire, vérifiez-le avant de vous en servir :

```php
if (!ctype_digit($input->option('fois'))) {
    $output->error('L\'option --fois attend un nombre, par exemple --fois=3.');

    return 2;
}
```

### Le code de sortie

`run()` retourne un nombre, que le terminal et les outils d'automatisation lisent pour savoir si la commande a réussi :

| Code | Sens |
| --- | --- |
| `0` | tout s'est bien passé |
| `1` | la commande a échoué |
| `2` | la commande a été mal écrite |

### Écrire dans le terminal

| Méthode | Usage |
| --- | --- |
| `$output->line('…')` | une ligne ordinaire |
| `$output->title('…')` | un titre |
| `$output->success('…')` | ce qui a réussi |
| `$output->warning('…')` | ce qui mérite attention |
| `$output->error('…')` | ce qui a échoué (écrit sur la sortie d'erreur) |
| `$output->definitions([...])` | une liste à deux colonnes, alignée |

La couleur vient de ces méthodes. Elle n'est utilisée que dans un terminal : rediriger la sortie vers un fichier donne du texte simple.

## Ce que la console fait pour vous

- **Elle ne s'exécute que dans un terminal.** Appelée par un serveur web, elle refuse : elle donnerait à un visiteur les pouvoirs du développeur. Le fichier `wazi` est à la racine du projet, hors du dossier `public/`.
- **Ce qu'elle affiche est nettoyé.** Une valeur venue d'un visiteur (un message, une adresse) peut contenir des séquences qui effacent l'écran ou piègent le terminal. `Output` les retire de tout ce qu'il écrit : vous n'avez pas à y penser.
- **Une erreur n'affiche jamais de trace** : elle contiendrait les valeurs passées aux fonctions, parfois des secrets. Vous voyez le message, la sorte d'erreur, le fichier et la ligne.

## Les limites

La console est à ses débuts. Elle n'a pas encore de saisie interactive (poser une question). D'autres générateurs (middleware, commande) sont prévus.

Une erreur dans `app.php` (un réglage manquant, une route mal écrite) empêche la console de démarrer, quelle que soit la commande. Elle vous dit laquelle, et où.

Retour au [sommaire](../README.md).
