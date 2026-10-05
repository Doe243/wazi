# La démonstration : un carnet de notes

Une petite application complète, qui utilise chaque pièce de Wazi : routes, contrôleurs, conteneur, middlewares, réglages, templates Kioo, sessions, protection des formulaires, pages d'erreur.

Elle est rangée comme le sera un vrai projet. Chaque fichier explique ce qu'il fait, et pourquoi.

## La lancer

Depuis le dossier du framework :

```bash
composer install
cd examples/demo
wazi db:migrate    # une fois : crée la base de données et ses premières notes
wazi serve
```

Puis ouvrez http://localhost:8000. Deux comptes existent : `alice` et `bob`, mot de passe `wazi`.

`wazi db:migrate` crée le fichier `var/demo.sqlite` à partir des fichiers du dossier `migrations/`. Si vous l'oubliez, la page des notes vous le rappelle : « Cette table n'existe pas (encore). Avez-vous lancé wazi db:migrate ? ». Pour repartir de zéro, supprimez `var/demo.sqlite` et relancez la commande.

Si votre terminal ne connaît pas `wazi`, tapez `php wazi serve` : c'est le même fichier qui s'exécute. Pour voir les routes de la démonstration : `wazi routes`.

`wazi serve` ne sert que le dossier `public/`. C'est important : si tout le projet était visible depuis un navigateur, Wazi refuserait de démarrer les sessions, parce que leurs fichiers seraient téléchargeables.

Pour voir le détail des erreurs dans le navigateur, copiez `.env.example` sous le nom `.env` (il contient `APP_DEBUG=true`). Sans fichier `.env`, la démonstration fonctionne en mode production.

## Ce que contient le dossier

```text
examples/demo/
├── app.php                  L'application : réglages, services, routes
├── wazi                     La console : charge app.php et exécute une commande
├── public/                  Le seul dossier visible depuis un navigateur
│   ├── index.php            Le point d'entrée : charge app.php et répond
│   ├── app.css              Les styles, thème clair et thème sombre
│   ├── app.js               Le script du site : thème, compteur, confirmation, épingle
│   ├── theme.js             Applique le thème choisi avant l'affichage de la page
│   └── icones.svg           Les icônes, réunies dans un seul fichier
├── src/                     Le code de l'application
│   ├── Carnet.php           Le service qui range les notes (il ne sait rien du web)
│   ├── Comptes.php          Les comptes et la vérification des mots de passe
│   ├── Filtres.php          Les filtres Kioo de l'application : « depuis », « initiale »
│   ├── Visiteur.php         Ce qu'on sait du visiteur : son nom, ses messages
│   ├── ConnexionRequise.php Le middleware qui garde les pages réservées
│   ├── PageController.php       L'accueil, et une panne volontaire
│   ├── ConnexionController.php  Se connecter, se déconnecter
│   ├── NoteController.php       Lire, chercher, ajouter, modifier, supprimer
│   └── WebhookController.php    Une route appelée par un autre programme
├── views/                   Les templates Kioo
│   ├── base.kioo            La mise en page commune
│   ├── partiels/            Les morceaux inclus par d'autres vues
│   └── ...
├── migrations/              La structure de la base, pas à pas : des fichiers SQL
├── var/                     Créé au premier lancement : sessions et base de données
└── .env.example             Les réglages, à copier sous le nom .env
```

## Le chemin d'une requête

Quand vous envoyez le formulaire « Nouvelle note » :

1. `public/index.php` reçoit la requête, charge l'application construite par `app.php`, et la confie au noyau (`Kernel`).
2. Les en-têtes de sécurité, le cookie du jeton et la session sont préparés (trois middlewares de Wazi).
3. Le routeur trouve la route `POST /notes`, écrite au-dessus de `NoteController::ajouter()`.
4. Wazi vérifie que le formulaire porte le bon jeton. Sinon : 403, et rien d'autre ne s'exécute.
5. `ConnexionRequise` vérifie qu'un visiteur est connecté. Sinon : redirection vers `/connexion`.
6. Le conteneur fabrique `NoteController`, en lui fournissant le `Carnet` (qui reçoit lui-même la base de données), le moteur de templates et la `Session`.
7. `ajouter()` vérifie le texte, l'enregistre, note le message « Note ajoutée. », et redirige.
8. La page suivante affiche le message, une seule fois.

## À essayer

| Essai | Ce qui se passe | Où regarder |
| --- | --- | --- |
| Se connecter en cochant « Se souvenir de moi » | Le cookie de session reçoit une durée de 30 jours | `src/ConnexionController.php` |
| Écrire `<script>alert(1)</script>` dans une note | Le texte s'affiche tel quel, rien ne s'exécute | `views/notes/liste.kioo` |
| Connecté en tant qu'Alice, ouvrir `/notes/4` (une note de Bob) | « Note introuvable » | `src/Carnet.php` |
| Cliquer sur l'épingle d'une note | La note est épinglée sans recharger la page | `public/app.js` |
| Chercher un mot, puis regarder l'adresse | La recherche est un formulaire GET : elle part dans l'adresse (`/notes?q=pain`) | `views/notes/liste.kioo` |
| Choisir une couleur pour une note | Seules les couleurs d'une liste sont acceptées ; toute autre valeur est ignorée | `src/Carnet.php` |
| Regarder « il y a 3 h » sous une note | Un filtre Kioo écrit par l'application | `src/Filtres.php` |
| Cliquer sur la lune, en haut à droite | Le site passe en thème sombre, et s'en souvient | `public/theme.js` |
| Couper JavaScript dans le navigateur | Tout fonctionne encore : le script n'apporte que du confort | `public/app.js` |
| Ouvrir `/nulle-part` | 404 | — |
| Ouvrir `/panne` | Le message de l'erreur avec `APP_DEBUG=true`, une page neutre sans | `src/PageController.php` |

Et depuis un terminal, pendant que le serveur tourne :

```bash
# Un formulaire envoyé sans le jeton de protection : 403.
curl -i -X POST http://localhost:8000/connexion -d "nom=alice&mot_de_passe=wazi"

# Le webhook sans signature : 403.
curl -i -X POST http://localhost:8000/webhook -d "bonjour"

# Le webhook avec la bonne signature : 200. (DEMO_WEBHOOK_SECRET=secret-de-demo dans .env)
curl -i -X POST http://localhost:8000/webhook -d "bonjour" \
     -H "X-Signature: $(php -r "echo hash_hmac('sha256', 'bonjour', 'secret-de-demo');")"
```

## Ce que la démonstration ne fait pas

Elle montre Wazi, pas un site prêt à mettre en ligne.

- **Les comptes sont écrits dans le code**, avec le même mot de passe. Un vrai site les range dans une base de données.
- **Le nombre d'essais de connexion n'est pas limité.** Un vrai site doit ralentir ou bloquer quelqu'un qui essaie des milliers de mots de passe.
- **La base est un fichier SQLite.** C'est parfait pour une démonstration et pour un site modeste ; un site plus fréquenté passera à MySQL ou PostgreSQL en changeant un seul réglage, `DATABASE_URL`.
- **Les classes sont chargées une à une** dans `app.php`. Dans un vrai projet, Composer s'en charge.
