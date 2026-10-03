# ADR-017 : la configuration se lit dans un fichier .env, et y reste

**Statut :** acceptée

## Contexte
Une application a des réglages qui changent d'une machine à l'autre (adresse de la base, nom du site) et des secrets (mots de passe, clés). La question était ouverte : des tableaux PHP, ou un fichier `.env`. Le choix du `.env` a été fait le 3 octobre 2026 : c'est ce que le débutant retrouvera dans Symfony et Laravel. Il reste à décider comment le lire sans créer de fuite.

## Options envisagées

**Lecture**
- Une bibliothèque existante (`vlucas/phpdotenv`, `symfony/dotenv`) : éprouvée, mais c'est une dépendance d'exécution hors PSR (ADR-001), et elles recopient les valeurs dans l'environnement du processus.
- Un lecteur maison, au format volontairement réduit.

**Où vivent les valeurs**
- Dans `$_ENV`, `$_SERVER` et `putenv()`, comme le font Symfony et Laravel : lisibles par `phpinfo()`, par tout programme lancé par PHP, et présentes dans tout affichage de `$_SERVER`.
- Dans un objet, et nulle part ailleurs.

**Variables d'environnement du système**
- Les lire en priorité sur le fichier : utile sur certains hébergements, mais une variable du système (`USER`, `PATH`) peut remplacer un réglage sans que rien ne le dise.
- Ne lire que le fichier.

## Décision
1. `Config::fromEnvFile($chemin)` lit un fichier `.env` avec un lecteur maison (`EnvFile`). Un fichier absent donne une configuration vide : l'application démarre avec ses valeurs par défaut.
2. Format : `NOM=valeur`, nom en majuscules, commentaires `#`, guillemets simples (texte brut) ou doubles (`\n`, `\t`, `\"`, `\\`), une valeur par ligne. **Rien n'est interpolé ni exécuté** : `${AUTRE}` et `$(commande)` restent du texte.
3. Les valeurs se lisent avec leur type : `string()`, `int()`, `bool()`, `list()`. Une valeur du mauvais type est une erreur. Un booléen s'écrit `true` ou `false`, rien d'autre.
4. Une clé sans valeur par défaut est obligatoire.
5. Les valeurs ne sont **jamais** copiées dans `$_ENV`, `$_SERVER` ou `putenv()`, et les variables d'environnement du système ne sont **pas** lues.
6. Un `.env` situé dans le dossier public du site est refusé. La sortie est explicite : `unsafeAllowPublicLocation: true`.
7. Aucun message d'erreur ne contient une valeur ni le contenu d'une ligne ; `var_dump()` de la configuration masque les valeurs ; les valeurs passées au constructeur sont masquées dans les traces.

## Pourquoi
- Un lecteur d'une centaine de lignes se lit en entier et ne fait rien de caché (principes 1 et 5).
- Le `.env` exposé par le serveur web est l'une des fuites de mots de passe les plus fréquentes : la refuser dès la lecture protège celui qui a mal rangé son fichier (principe 8).
- Garder les valeurs dans un objet réduit les endroits où un secret peut fuir à un seul.
- « La valeur vient du fichier que vous voyez » est une règle que l'on peut expliquer en une phrase.

## Conséquences
- Les fichiers `.env` écrits pour d'autres outils ne sont pas tous lisibles : ni `export`, ni valeur sur plusieurs lignes, ni interpolation.
- Les hébergements qui fournissent la configuration par variables d'environnement (conteneurs, plateformes) ne sont pas pris en charge. À ajouter par un nouvel ADR, avec une liste explicite des variables à lire.
- Une bibliothèque tierce qui lit `getenv()` ne verra pas les réglages : il faudra les lui passer.
- Le `Kernel` ne lit pas la configuration de lui-même. C'est le fichier d'entrée de l'application qui écrit `new Kernel(development: $config->bool('APP_DEBUG', false))` : le lien entre un réglage et son effet reste visible. Le projet de départ (`wazi/skeleton`) fournira ce fichier, un `.env.example`, et un `.gitignore` qui exclut le `.env`.
- La vérification du dossier public s'appuie sur `$_SERVER['DOCUMENT_ROOT']` : elle ne s'applique pas en ligne de commande, et ne voit pas un serveur web mal réglé qui distribuerait un dossier parent.
