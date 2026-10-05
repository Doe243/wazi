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

Sans rien d'autre, la console affiche son écran d'accueil : la version de Wazi, puis les commandes, rangées par famille.

```text
██╗    ██╗ █████╗ ███████╗██╗
██║    ██║██╔══██╗╚══███╔╝██║
██║ █╗ ██║███████║  ███╔╝ ██║
██║███╗██║██╔══██║ ███╔╝  ██║
╚███╔███╔╝██║  ██║███████╗██║
 ╚══╝╚══╝ ╚═╝  ╚═╝╚══════╝╚═╝

Wazi 0.5.0 · le framework PHP où tout est clair

Utilisation :
  wazi <commande> [arguments] [--options]

Options :
  --help  Explique une commande, sans l'exécuter : wazi serve --help

Commandes :
  explain          Explique ce qu'une adresse traverse : route, middlewares, contrôleur.
  messages         Affiche les messages reçus par le formulaire de contact.
  routes           Liste les routes de l'application : adresse, code exécuté, middlewares.
  serve            Lance le site sur votre ordinateur, pour développer.
 db
  db:migrate       Applique les migrations en attente : crée ou modifie les tables de la base.
  db:status        Montre les migrations faites et celles qui restent à appliquer.
 make
  make:controller  Crée un contrôleur et sa page, commentés et prêts à modifier.
  make:migration   Crée un fichier de migration : un changement de la structure de la base, en SQL.
 views
  views:compile    Prépare les templates à l'avance, pour la mise en ligne.
 zones
  zones:install    Installe public/wazi.js : le script qui met à jour des morceaux de page sans la recharger.

Pour le détail d'une commande : wazi <commande> --help
```

Une **famille** est ce qui précède les deux-points dans le nom d'une commande : `db:migrate` et `db:status` sont de la famille `db`. Nommez vos commandes de la même façon (`facture:envoyer`, `facture:relancer`) et elles se rangeront ensemble.

## Le détail d'une commande

Ajoutez `--help` à n'importe quelle commande. Elle n'est pas exécutée : la console dit ce qu'elle fait et comment l'écrire.

```bash
wazi explain --help
```

```text
Description :
  Explique ce qu'une adresse traverse : route, middlewares, contrôleur.

Utilisation :
  wazi explain <adresse> [--options]

Arguments :
  adresse  Le chemin à expliquer : /notes/42, ou notes/42

Options :
  --method=…  La méthode de la requête : GET, POST, PUT, PATCH, DELETE (par défaut : GET)
  --help      Affiche cette aide, sans exécuter la commande

Exemples :
  wazi explain notes/3                Ce que traverse GET /notes/3
  wazi explain contact --method=POST  Ce que traverse l'envoi du formulaire de contact

Aide :
  Montre la route choisie, les étapes traversées dans l'ordre, le code exécuté
  et d'où vient chacun de ses arguments.
  ...
```

Un argument entre `<` et `>` est obligatoire ; entre crochets, il est facultatif.

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

## Comprendre une adresse

Quand une page ne répond pas comme prévu, demandez à Wazi ce que son adresse traverse :

```bash
wazi explain notes/3
```

```text
GET /notes/3

1. La route choisie

   GET /notes/{id:int}   (la 8e des 12 route(s) déclarée(s))
   {id} = 3   (un nombre entier)

2. Ce que la requête traverse, dans l'ordre

    1. SecurityHeaders               ajoute les en-têtes de sécurité à la réponse
    2. CsrfCookie                    lit le cookie du jeton des formulaires, et l'envoie si une page en a besoin
    3. SessionMiddleware             retrouve la session du visiteur, et l'enregistre au retour
    4. le routeur                    choisit la route ci-dessus
    5. ConnexionRequise              Le middleware : un garde placé devant les routes réservées aux visiteurs connectés.
    6. CsrfProtection                ne demande rien : GET ne fait que lire
    7. Demo\NoteController::voir()   votre code : il retourne la réponse

   La réponse repasse ensuite par les mêmes étapes, en sens inverse.

3. Le code exécuté

   Demo\NoteController::voir()
   …/src/NoteController.php, ligne 70

   Le conteneur fabrique le contrôleur, et lui fournit ce que son constructeur demande :
     Demo\Carnet $carnet
     Wazi\View\Kioo $kioo

   Les arguments de la méthode :
     int $id   ← le paramètre {id} de la route : 3
```

Pour une autre méthode que GET :

```bash
wazi explain notes --method=POST
```

Ce que la commande vous apprend :

- **quelle route est choisie**, et la valeur de ses paramètres. Si l'adresse convient aussi à une route déclarée plus loin, elle vous prévient : la première déclarée gagne, l'autre n'est jamais atteinte ;
- **chaque étape traversée**, dans l'ordre. Pour vos propres middlewares, elle cite la première phrase du commentaire de leur classe : commentez-les, et l'explication parle avec vos mots ;
- **quel code s'exécute**, où il est écrit, et d'où vient chacun de ses arguments ;
- **pourquoi une adresse ne répond pas** : 404 (aucune route), 405 (pas pour cette méthode), ou adresse refusée par protection.

Elle **n'exécute rien** : ni middleware, ni contrôleur. Vous pouvez expliquer `--method=DELETE` sans rien supprimer. En contrepartie, elle ne dit pas ce qu'un de vos middlewares décidera (laisser passer ou refuser) : cela dépend de la vraie requête.

L'adresse s'écrit avec ou sans la barre du début. Sous Windows, le terminal Git Bash transforme ce qui commence par `/` en chemin de fichier : écrivez `notes/3`.

## Préparer les templates pour la mise en ligne

```bash
wazi views:compile
```

Cette commande analyse tous vos templates une fois pour toutes, pour qu'ils ne le soient plus à chaque requête. Elle se lance au déploiement, pas pendant que vous développez. Elle est expliquée dans [Mettre en ligne](12-deploiement.md).

## Construire la base de données

```bash
wazi make:migration creer_notes    # crée un fichier SQL, à remplir
wazi db:migrate                    # applique les migrations en attente
wazi db:status                     # montre où en est chaque migration
```

Ces trois commandes sont expliquées dans [La base de données](14-base-de-donnees.md).

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

### Des exemples et un texte d'aide

Pour que `wazi bonjour --help` montre aussi des exemples et un texte d'aide, la commande implémente `DetailedCommand` au lieu de `Command`, et ajoute deux méthodes :

```php
use Wazi\Console\DetailedCommand;

final class BonjourCommand implements DetailedCommand
{
    // name(), description(), arguments(), options() et run() : comme avant.

    public function help(): string
    {
        return "Salue la personne nommée.\nAvec --fort, le texte est écrit en majuscules.";
    }

    public function examples(): array
    {
        // Ce qu'on tape après le nom de la commande => ce que cela fait.
        return [
            'Alice' => 'Salue Alice',
            'Alice --fort' => 'La même chose, en majuscules',
        ];
    }
}
```

C'est facultatif : une commande qui n'implémente que `Command` fonctionne exactement pareil, avec une aide plus courte.

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
| `$output->section('…')` | le nom d'une rubrique : « Options : » |
| `$output->accent('…')` | une ligne mise en avant, dans la couleur de Wazi |
| `$output->note('…')` | une ligne discrète : une précision, un rappel |

La couleur vient de ces méthodes. Elle n'est utilisée que dans un terminal : rediriger la sortie vers un fichier donne du texte simple.

## Ce que la console fait pour vous

- **Elle ne s'exécute que dans un terminal.** Appelée par un serveur web, elle refuse : elle donnerait à un visiteur les pouvoirs du développeur. Le fichier `wazi` est à la racine du projet, hors du dossier `public/`.
- **Ce qu'elle affiche est nettoyé.** Une valeur venue d'un visiteur (un message, une adresse) peut contenir des séquences qui effacent l'écran ou piègent le terminal. `Output` les retire de tout ce qu'il écrit : vous n'avez pas à y penser.
- **Une erreur n'affiche jamais de trace** : elle contiendrait les valeurs passées aux fonctions, parfois des secrets. Vous voyez le message, la sorte d'erreur, le fichier et la ligne.

## Les limites

La console est à ses débuts. Elle n'a pas encore de saisie interactive (poser une question). D'autres générateurs (middleware, commande) sont prévus.

Une erreur dans `app.php` (un réglage manquant, une route mal écrite) empêche la console de démarrer, quelle que soit la commande. Elle vous dit laquelle, et où.

Suite : [La base de données](14-base-de-donnees.md).
