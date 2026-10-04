# ADR-032 : la base de données

**Statut :** acceptée

## Contexte
La version 0.4 doit permettre d'enregistrer un formulaire validé dans une base de données. PHP fournit PDO, qui parle à toutes les bases, mais dont les réglages par défaut sont dangereux ou surprenants : erreurs silencieuses selon les versions, requêtes préparées « simulées », nombres rendus en texte, clés étrangères ignorées par SQLite.

Choix de l'utilisateur (2026-10-04) : une surcouche légère au-dessus de PDO, pas un ORM ; SQLite, MySQL et PostgreSQL pris en charge et testés dès la 0.4.

### Analyse de menaces
- **Injection SQL par une valeur** : une valeur reçue d'un visiteur collée dans le texte d'une requête. C'est la faille la plus connue et la plus grave.
- **Injection SQL par un nom** : un nom de table ou de colonne venu d'une requête (`?tri=nom`). Une requête préparée ne protège pas les noms.
- **Plusieurs requêtes en une** : `1; DROP TABLE notes`.
- **Fuite des identifiants** : le mot de passe de la base dans un message d'erreur, une trace, un `var_dump`.
- **Fuite de données par les messages d'erreur** : la base cite parfois la valeur fautive (« Duplicate entry 'alice@exemple.com' »).
- **Fichier SQLite téléchargeable** : rangé dans le dossier public, il serait servi à qui le demande.
- **Adresse à protocole** à la place d'un chemin de fichier (`php://`, `phar://`).
- **Jokers de `LIKE`** : un visiteur qui cherche `%` obtient tout, et peut faire travailler la base inutilement.
- **Migrations déclenchées à distance** : modifier la structure de la base ne doit jamais être possible par une requête HTTP.
- **Instanciation par la base** : les modes de PDO qui fabriquent des objets à partir d'un nom de classe lu dans le résultat.

## Options envisagées
- **Un ORM** (entités, dépôts) : confortable, mais beaucoup de code et de magie ; le débutant n'apprend pas SQL (écarté par l'utilisateur).
- **Un constructeur de requêtes** (`$db->table('notes')->where(...)->get()`) : un second langage à apprendre à la place de SQL, et des chaînes d'appels difficiles à suivre.
- **PDO seul**, sans rien : réglages dangereux à corriger dans chaque projet.
- **Une surcouche fine : SQL écrit en clair, requêtes préparées obligatoires, réglages sûrs.**

## Décision
1. Nouveau composant **`Database`**, de la couche des fonctionnalités. Classes : `Database`, `Migrator`, `Exception\DatabaseException`.
2. **Se connecter** : `Database::sqlite($fichier)`, `Database::mysql(...)`, `Database::postgres(...)`, ou `Database::fromUrl($url, $dossier)` pour un réglage unique `DATABASE_URL` (`sqlite:var/app.sqlite`, `mysql://nom:secret@hote/base`, `postgres://...`). La connexion ne s'ouvre qu'à la première requête.
3. **Interroger** : le SQL s'écrit en clair, les valeurs passent à part.
   `select()`, `selectOne()`, `selectValue()`, `execute()`. Les marqueurs sont `?` ou `:nom`.
4. **Trois raccourcis** pour les écritures les plus courantes : `insert($table, $valeurs)`, `update($table, $valeurs, $ou)`, `delete($table, $ou)`. Une condition vide est refusée : on ne vide pas une table par oubli.
5. **`transaction(fn)`** : tout réussit, ou rien n'est gardé. Pas de transaction dans une transaction.
6. **Réglages imposés** : erreurs en exceptions, requêtes réellement préparées (jamais simulées), une seule requête par appel, lignes rendues en tableaux associatifs uniquement, nombres rendus en nombres, `utf8mb4` pour MySQL, clés étrangères actives et attente de 5 s sur verrou pour SQLite, aucune connexion persistante.
7. **Les noms** de table et de colonne passés aux raccourcis ne contiennent que des lettres, des chiffres et « _ ». Ils sont refusés sinon, et toujours écrits entre guillemets.
8. **Les valeurs** sont liées avec leur type : entier, booléen, texte, `null`, nombre à virgule, date (`DateTimeInterface`, écrite `2026-10-04 12:30:00`). Un tableau ou un autre objet est refusé.
9. **`DatabaseException`** : dit ce qui s'est passé et quoi faire. Elle porte la requête (qui ne contient pas de valeurs) et le message de la base, nettoyé et borné. Jamais le mot de passe, jamais l'adresse de connexion. `isDuplicate()` reconnaît une valeur déjà prise (contrainte d'unicité), dans les trois bases.
10. **`Database::likeEscape($texte)`** neutralise `%` et `_` dans un texte cherché.
11. **Fichier SQLite** : chemin ordinaire uniquement ; refusé s'il est dans le dossier public du site.
12. **Migrations** : des fichiers `.sql` dans `migrations/`, appliqués dans l'ordre de leur nom, une seule fois, notés dans la table `wazi_migrations`. Commandes : `wazi db:migrate`, `wazi db:status`, `wazi make:migration`. On avance seulement : pour défaire, on écrit une nouvelle migration. Un fichier modifié après avoir été appliqué est signalé.
13. **Aucune dépendance ajoutée.** Les extensions PDO sont suggérées, pas exigées : une application sans base n'en a pas besoin.

## Pourquoi
- Le SQL visible respecte le principe 1 : ce qui s'exécute est ce qui est écrit. Le débutant apprend un langage qui lui servira partout.
- Les valeurs à part du SQL rendent l'injection impossible par construction : il n'existe aucune méthode qui colle une valeur dans une requête.
- Les requêtes réellement préparées ferment aussi la porte aux requêtes multiples.
- Les réglages imposés appliquent le principe 8 : il n'y a rien à savoir pour être en sécurité.
- Des migrations en SQL, sans retour arrière, tiennent en une idée : « la base est le résultat de ces fichiers, dans l'ordre ».

## Conséquences
- Le SQL diffère un peu d'une base à l'autre (`AUTOINCREMENT`, `SERIAL`, `AUTO_INCREMENT`) : un projet choisit sa base, les migrations sont écrites pour elle.
- `insert()` rend l'identifiant créé par la base : le dernier identifiant automatique pour SQLite et MySQL, la colonne `id` pour PostgreSQL. `0` s'il n'y en a pas.
- Les booléens reviennent tels que la base les garde : `0`/`1` pour SQLite et MySQL, `true`/`false` pour PostgreSQL.
- MySQL valide seul chaque changement de structure : une migration qui échoue au milieu y reste à moitié appliquée. Le message le dit.
- Un fichier de migration est découpé en requêtes sur les « ; ». Les déclencheurs et procédures, dont le corps contient des « ; », ne passent pas par un fichier de migration.
- Trier par une colonne choisie par le visiteur : la comparer à une liste (`Validator::choice()`), puis l'écrire dans le SQL. La documentation le montre.
- Pas de lecture ligne par ligne des très grands résultats, pas de plusieurs connexions nommées : à voir quand le besoin sera réel.
- Les sessions en base (question ouverte) deviennent possibles, mais ne sont pas faites ici.
