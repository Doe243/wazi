# ADR-026 : la console

**Statut :** acceptée

## Contexte
La version 0.4 a besoin d'une ligne de commande : lancer le serveur de développement, générer un contrôleur, expliquer une route (`wazi explain`), traduire les templates au déploiement. L'ADR-006 réserve déjà les outils de diagnostic à la ligne de commande. Il faut le socle sur lequel poser ces commandes.

### Analyse de menaces
Qui lance la console ? Le développeur, sur sa machine ou sur le serveur. Ce qui peut mal tourner :
- **la console atteinte par le web** : un script de console placé ou appelé dans le dossier public donnerait à un visiteur les pouvoirs du développeur ;
- **l'injection de commande** : un argument recopié dans une commande du système (`php -S …`) peut en glisser une autre ;
- **l'injection dans le terminal** : une valeur affichée (une adresse reçue, une ligne du journal) peut contenir des séquences d'échappement qui effacent l'écran, changent le titre de la fenêtre ou, sur certains terminaux, écrivent dans le presse-papiers ;
- **la fuite de secrets** : une commande qui affiche les réglages les met dans l'historique du terminal ou dans les journaux d'un outil de déploiement ;
- **le serveur de développement exposé** : lancé sur toutes les interfaces, il devient visible du réseau local.

## Options envisagées
- Dépendre de `symfony/console` : complet et éprouvé, mais c'est une dépendance d'exécution de plus (ADR-001), et son code est trop vaste pour être lu par un débutant (principe 5).
- Une console maison, réduite à ce dont Wazi a besoin.

## Décision
1. **Console maison**, dans le composant `Console` : `Application`, `Command`, `Argument`, `Option`, `Input`, `Output`.
2. **Une commande est une classe** qui implémente `Command` : un nom, une description, ses arguments et ses options déclarés, et `run(Input, Output): int`. Elle retourne un code de sortie : 0 si tout va bien.
3. **Une seule écriture** : `wazi nom argument --option=valeur --drapeau`. Pas d'options courtes (`-p`), pas de valeur séparée par un espace. `--help` existe pour toute commande ; `wazi` seul liste les commandes.
4. **Tout est déclaré, rien n'est deviné** : un argument en trop, une option inconnue ou une valeur donnée à un drapeau est une erreur, avec une suggestion.
5. **La console refuse de s'exécuter hors de la ligne de commande** (`PHP_SAPI` différent de `cli`).
6. **Ce qui est affiché est nettoyé** : `Output` retire les caractères de contrôle de tout texte, sauf le retour à la ligne et la tabulation. La couleur vient des méthodes d'`Output` (`success()`, `error()`...), jamais d'une séquence écrite par une commande. Elle n'est utilisée que si la sortie est un terminal et que `NO_COLOR` n'est pas défini.
7. **Une commande du système se lance par une liste d'arguments**, jamais par une ligne passée à un interpréteur ; chaque argument venu de l'utilisateur est validé par une forme stricte.
8. **Première commande : `wazi serve`**, le serveur de développement de PHP sur le dossier `public/`. Il écoute sur `localhost` ; une autre adresse se demande par `--host` et déclenche un avertissement.
9. **Chaque projet a son fichier `wazi`**, à sa racine, hors du dossier public : la console se lance par `php wazi`. Ce fichier déclare les commandes une par une, celles de Wazi puis celles du projet. Demandé par l'utilisateur le 2026-10-04 : des commandes propres à Wazi, pas de `composer start`.
10. Le framework fournit aussi `bin/wazi` (déclaré dans `composer.json`, donc `vendor/bin/wazi`), pour un projet qui n'a pas de fichier `wazi`.

## Pourquoi
- Une écriture unique des options se lit sans mode d'emploi et s'analyse en trente lignes.
- Le nettoyage par défaut de la sortie applique le principe 8 : le réglage sûr ne demande rien, et une commande écrite à la hâte ne peut pas ouvrir cette faille.
- Lancer un programme par une liste d'arguments supprime toute interprétation par un shell : l'injection devient impossible, pas seulement filtrée.

## Conséquences
- **Règle des couches :** `Console` ne connaît ni `Routing` ni `View`. Les commandes qui en ont besoin (`explain`, la traduction des templates) vivront dans la couche d'assemblage, à côté du `Kernel`, et seront ajoutées à l'`Application` par le script `bin/wazi`.
- Les commandes étant déclarées une par une dans le fichier `wazi` du projet, une nouvelle commande de Wazi n'apparaît pas toute seule dans un projet existant : il faut ajouter sa ligne. C'est le prix de la transparence (principe 1) ; à revoir si la liste s'allonge.
- **Question ouverte :** ces commandes auront besoin de l'application du projet (ses routes, son conteneur). Aujourd'hui `public/index.php` la construit et l'exécute d'un seul geste. Il faudra un fichier qui la construit sans l'exécuter, partagé par `index.php` et par la console. À décider avec la première commande qui en a besoin.
- Pas de saisie interactive, de barre de progression ni de tableaux en 0.4 : ils s'ajouteront si une commande les demande.
- Aucune commande n'affiche la valeur d'un réglage (ADR-017).
