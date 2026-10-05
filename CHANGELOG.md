# Journal des modifications

Ce qui change d'une version de Wazi à l'autre. Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), les numéros suivent le [versionnage sémantique](https://semver.org/lang/fr/) :

- **correctif** (`0.4.0` → `0.4.1`) : des corrections, rien d'autre. Toujours sans risque ;
- **mineur** (`0.4` → `0.5`) : des fonctionnalités nouvelles. Avant la 1.0, une version mineure peut encore changer l'API : c'est alors écrit sous « À modifier dans votre projet » ;
- **majeur** (`1` → `2`) : un changement qui oblige à modifier votre code.

Les règles complètes sont dans [la décision 033](docs/decisions/0033-versions-et-publications.md).

## À venir

Rien pour l'instant.

## 0.5.0 « Ça se voit » — 2026-10-05

Rien à modifier dans un projet créé avec la 0.4.

### Ajouté
- **Barre de débogage** (décision 035) : en mode développement, en bas des pages HTML, elle montre la requête, la route et le code exécuté, les middlewares, les templates et leur durée, le nom des clés de la session. Lecture seule, sans adresse à elle ni historique ; écrite seulement pour une requête venue de la machine elle-même, sans proxy, sous un nom local ; aucune valeur sensible n'est collectée. `new Kernel(debugBar: false)` pour s'en passer.
- `Contracts\Tracer` : un composant signale ce qu'il fait sans connaître la barre ; Kioo signale chaque page écrite.
- Barre de débogage, rubrique **Base** : les requêtes SQL de la page (leur texte et leurs marqueurs, jamais leurs valeurs) et leur durée, avec un signalement quand la même requête est lancée en boucle. `Database::withTracer($app->tracer)` dans `app.php`.
- **Console :** écran d'accueil avec le nom en grand, la version et les commandes rangées par famille ; aide détaillée de chaque commande (`--help`) : description, utilisation, arguments, options, exemples, texte d'aide. Interface `DetailedCommand` pour donner exemples et aide à vos propres commandes ; `Output::section()`, `accent()`, `note()`.
- **Pages d'erreur redessinées :** une carte centrée, le code de l'erreur en pastille, les détails en rubriques, thème sombre selon le réglage du visiteur. Toujours sans logo, sans nom et sans aucune ressource chargée.
- Démonstration : les notes sont gardées dans une base SQLite (deux migrations, `wazi db:migrate`) au lieu d'un fichier ; la barre de débogage y montre les requêtes de chaque page.
- Démonstration : « wazi » à côté du signe, halos de lumière en fond de page, lien de la page affichée marqué dans le bandeau, et les six étapes du chemin d'une requête s'ouvrent au clic pour montrer leur code. Couleurs du thème sombre alignées sur l'identité.
- Identité visuelle : thème sombre, échelle d'espacements, règles de relief et d'accessibilité (`docs/brand/`).
- Le projet vit désormais dans l'organisation GitHub `wazi-php` ; les anciennes adresses redirigent.
- Dépôt : guide de contribution (`CONTRIBUTING.md`), code de conduite, modèles de demande de fusion et d'issue, mises à jour de dépendances proposées par Dependabot ; une page de publication GitHub est créée à chaque tag, avec le texte de ce journal.

## 0.4.0 « Ça se construit » — 2026-10-04

### Ajouté
- **La console `wazi`** : `serve`, `routes`, `explain`, `make:controller`, `views:compile`, et vos propres commandes.
- **`app.php`** : l'application est construite une fois, pour le site et pour la console (`Kernel::load()`).
- **Templates préparés à la mise en ligne** (`wazi views:compile`) : les pages s'affichent plus vite.
- **Validation des formulaires** : `Validator`, une méthode par sorte de champ, qui vérifie et retourne la valeur dans son type.
- **Base de données** : `Database` (SQLite, MySQL, PostgreSQL), SQL en clair et valeurs toujours à part ; migrations avec `wazi make:migration`, `wazi db:migrate`, `wazi db:status`.
- Guide : pages « La console » et « La base de données » ; page « Les formulaires » réécrite.

### À modifier dans votre projet
- Un projet créé avec la 0.3 doit recevoir un fichier `app.php` qui construit l'application et se termine par `return $app;`, et un `public/index.php` réduit à `Kernel::load(__DIR__ . '/../app.php')->run();`. Le projet de départ en donne le modèle.

### Corrigé
- Sessions : sous Windows, l'écriture d'une session échouait parfois (« Accès refusé ») quand un autre programme tenait le fichier ouvert un instant.

### Sécurité
- Base SQLite : un chemin contenant « .. » ne peut plus contourner le refus d'un fichier situé dans le dossier public.

## 0.3.0 « Ça s'affiche » — 2026-10-04

### Ajouté
- **Kioo**, les templates : affichage échappé par défaut, `k:if`, `k:for`, mises en page, morceaux inclus, filtres.
- **Configuration** par fichier `.env` et variables d'environnement du serveur.
- **Sessions** : messages flash, verrou, « se souvenir de moi ».
- **Protection CSRF** par cookie, active sur toutes les routes, même sans session.
- **Proxies de confiance** : les en-têtes `X-Forwarded-*` ne sont crus que venant d'un proxy déclaré.
- Jeton CSP posé automatiquement sur les scripts écrits dans un template.
- Application de démonstration, guide en douze pages, projet de départ `wazi/skeleton`.

## 0.2.0 « Ça s'organise » — 2026-10-03

### Ajouté
- **Conteneur** de services, qui fabrique seul les classes et leurs dépendances.
- **Contrôleurs en classes**, routes déclarées par attributs (`#[Get]`, `#[Post]`...).
- **Middlewares**, globaux ou par route ; en-têtes de sécurité actifs par défaut.

## 0.1.0 « Ça répond » — 2026-10-03

### Ajouté
- **HTTP** : requêtes, réponses, flux, adresses et fichiers envoyés, conformes aux standards PSR-7 et PSR-17.
- **Routeur**, avec paramètres et contraintes.
- **Erreurs pédagogiques** : chaque erreur dit ce qui s'est passé, pourquoi, et comment corriger.
- **Noyau**, qui assemble le tout : une application tient dans un seul fichier.
