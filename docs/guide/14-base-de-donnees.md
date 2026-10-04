# 14. La base de données

Une base de données garde ce qui doit durer : des comptes, des articles, des commandes. On lui parle en **SQL**, un langage fait pour cela, que toutes les bases comprennent.

Wazi ne cache pas le SQL derrière des objets : vous l'écrivez en clair, et ce que vous écrivez est ce qui s'exécute. Wazi s'occupe du reste : se connecter, transmettre les valeurs sans danger, transformer les erreurs en messages utiles.

## Se connecter

Dans `app.php`, on explique au conteneur comment fabriquer la base :

```php
use Wazi\Database\Database;

$app->container->set(Database::class, static fn(): Database => Database::fromUrl(
    $config->string('DATABASE_URL', 'sqlite:var/app.sqlite'),
    __DIR__,
)->withTracer($app->tracer));
```

`withTracer($app->tracer)` est facultatif : en mode développement, il fait apparaître les requêtes de chaque page dans la [barre de débogage](15-barre-de-debogage.md). En production, `$app->tracer` vaut `null`, et rien n'est signalé.

Sans réglage, c'est une base **SQLite** : un simple fichier, `var/app.sqlite`. Il n'y a rien à installer, c'est la base idéale pour commencer.

Pour une autre base, un seul réglage dans `.env` :

```ini
DATABASE_URL=mysql://utilisateur:motdepasse@localhost:3306/ma_base
DATABASE_URL=postgres://utilisateur:motdepasse@localhost:5432/ma_base
```

Dans le nom d'utilisateur et le mot de passe, les caractères `@ : / ? # %` s'écrivent encodés : `%40` pour `@`. Pour une base PostgreSQL hébergée qui exige une connexion chiffrée, ajoutez `?sslmode=require`.

Chaque base a besoin de son extension PHP : `pdo_sqlite`, `pdo_mysql` ou `pdo_pgsql`. S'il en manque une, le message d'erreur dit laquelle activer.

Un service qui a besoin de la base la demande dans son constructeur, comme n'importe quel autre service :

```php
final readonly class Carnet
{
    public function __construct(private Database $db) {}
}
```

**La connexion ne s'ouvre qu'à la première requête.** Une page qui ne lit rien en base ne coûte rien de plus.

## LA règle : une valeur ne s'écrit jamais dans le SQL

```php
// DANGER : ne faites jamais cela.
$db->select("SELECT * FROM notes WHERE auteur = '$auteur'");

// Toujours ainsi.
$db->select('SELECT * FROM notes WHERE auteur = ?', [$auteur]);
```

Dans la première écriture, la valeur est collée dans la requête. Si un visiteur envoie `x' OR '1'='1`, la requête devient `... WHERE auteur = 'x' OR '1'='1'` : elle rend les notes de tout le monde. C'est une **injection SQL**, la faille la plus connue du web.

Dans la seconde, la requête contient un **marqueur**, `?`, et la valeur est donnée à part. La base reçoit les deux séparément : la valeur, quoi qu'elle contienne, reste une valeur. L'injection est impossible.

Wazi n'a aucune méthode qui colle une valeur dans une requête. Tant que vous n'assemblez pas vous-même du SQL avec des variables, vous êtes à l'abri.

Les marqueurs s'écrivent de deux façons, à ne pas mélanger dans une même requête :

```php
$db->select('SELECT * FROM notes WHERE auteur = ? AND couleur = ?', [$auteur, $couleur]);
$db->select('SELECT * FROM notes WHERE auteur = :auteur AND couleur = :couleur', ['auteur' => $auteur, 'couleur' => $couleur]);
```

## Lire

```php
// Toutes les lignes : une liste de tableaux « colonne => valeur ».
$notes = $db->select('SELECT id, texte FROM notes WHERE auteur = ? ORDER BY id DESC', [$auteur]);
// [['id' => 2, 'texte' => 'Lait'], ['id' => 1, 'texte' => 'Pain']]

// Une ligne, ou null si rien ne correspond.
$note = $db->selectOne('SELECT * FROM notes WHERE id = ?', [$id]);

// Une seule valeur.
$total = $db->selectValue('SELECT COUNT(*) FROM notes WHERE auteur = ?', [$auteur]);
```

Un nombre rangé en base revient comme un nombre, pas comme un texte. Mais pour PHP, le contenu d'une ligne reste de type inconnu : vérifiez-le (`is_string()`, `is_int()`) avant de vous en servir, comme ce qui vient d'une session.

## Écrire

Trois raccourcis pour les écritures les plus courantes :

```php
$id = $db->insert('notes', ['auteur' => $auteur, 'texte' => $texte]);     // rend l'identifiant créé par la base

$db->update('notes', ['texte' => $texte], ['id' => $id, 'auteur' => $auteur]);
// UPDATE notes SET texte = ? WHERE id = ? AND auteur = ?

$db->delete('notes', ['id' => $id, 'auteur' => $auteur]);
```

Pour tout le reste, le SQL s'écrit en clair :

```php
$changees = $db->execute('UPDATE notes SET vues = vues + 1 WHERE id = ?', [$id]);     // rend le nombre de lignes touchées
```

Deux protections :

- **Une condition vide est refusée.** `$db->delete('notes', [])` ne vide pas la table : c'est une erreur. Pour toucher toutes les lignes, écrivez-le en SQL avec `execute()`.
- **Une seule requête par appel.** `$db->execute('DELETE ...; DELETE ...')` est refusé.

### Les valeurs acceptées

Texte, nombre entier ou à virgule, booléen, `null`, date (`DateTimeInterface`, écrite `2026-10-04 15:30:00`) et énumération à valeur. Un tableau ou un autre objet est refusé : pour garder un tableau, passez-le par `json_encode()`.

### Sécurité : les noms ne viennent jamais d'un visiteur

Un marqueur protège une **valeur**. Il ne peut pas remplacer un **nom** de table ou de colonne.

```php
// DANGER : le visiteur choisirait les colonnes.
$db->insert('comptes', $request->getParsedBody());

// Les valeurs vérifiées, une par une.
$db->insert('comptes', ['nom' => $nom, 'email' => $email]);
```

Pour trier selon un choix du visiteur, comparez ce choix à une liste, puis écrivez le nom dans le SQL :

```php
$v = new Validator($request->getQueryParams());
$tri = $v->choice('tri', ['creee_le', 'texte'], required: false) ?: 'creee_le';

$notes = $db->select('SELECT * FROM notes WHERE auteur = ? ORDER BY ' . $tri, [$auteur]);
```

C'est le seul cas où une variable entre dans du SQL : elle ne peut valoir que ce que **vous** avez écrit dans la liste.

Les raccourcis `insert()`, `update()` et `delete()` refusent d'eux-mêmes un nom qui n'a pas la forme d'un nom (lettres sans accent, chiffres, `_`).

## Chercher un texte

Dans un `LIKE`, `%` veut dire « n'importe quoi » et `_` « n'importe quel caractère ». Un visiteur qui cherche `100%` ne veut pas dire cela :

```php
$notes = $db->select(
    "SELECT * FROM notes WHERE texte LIKE ? ESCAPE '!'",
    ['%' . Database::likeEscape($recherche) . '%'],
);
```

`likeEscape()` neutralise ces caractères avec un `!`. Le `ESCAPE '!'` de la requête est indispensable : il dit à la base ce que ce `!` signifie.

## Tout ou rien : la transaction

Quand plusieurs écritures n'ont de sens qu'ensemble :

```php
$db->transaction(function (Database $db) use ($de, $vers, $somme): void {
    $db->execute('UPDATE comptes SET solde = solde - ? WHERE id = ?', [$somme, $de]);
    $db->execute('UPDATE comptes SET solde = solde + ? WHERE id = ?', [$somme, $vers]);
});
```

Si la fonction lève une exception, tout ce qu'elle a écrit est défait, et l'exception continue son chemin. Sinon, tout est gardé. `transaction()` rend ce que rend la fonction.

## Les migrations : construire la base pas à pas

Une **migration** est un fichier SQL qui change la structure de la base : créer une table, ajouter une colonne.

```bash
wazi make:migration creer_notes
```

```text
OK  Créé : migrations/20261004_153000_creer_notes.sql
```

Le fichier contient un exemple en commentaire. Écrivez-y votre SQL :

```sql
CREATE TABLE notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    auteur VARCHAR(80) NOT NULL,
    texte TEXT NOT NULL,
    creee_le VARCHAR(19) NOT NULL
);

CREATE INDEX notes_auteur ON notes (auteur);
```

Puis appliquez-le :

```bash
wazi db:migrate
```

```text
OK  20261004_153000_creer_notes.sql

1 migration(s) appliquée(s).
```

L'idée à retenir : **la base est le résultat de ces fichiers, dans l'ordre.** Le nom de chaque fichier commence par sa date, ce qui fixe cet ordre. Chaque fichier est appliqué une seule fois ; Wazi note ceux qui sont faits dans la table `wazi_migrations`. Sur votre ordinateur, sur celui d'un collègue, sur le serveur : la même commande mène à la même base.

Pour voir où vous en êtes :

```bash
wazi db:status
```

```text
20261004_153000_creer_notes.sql                  faite      2026-10-04 15:31:02
20261012_091500_ajouter_couleur_aux_notes.sql    à faire

1 migration(s) à faire : wazi db:migrate
```

Deux règles :

- **On avance seulement.** Pour défaire un changement, on écrit une nouvelle migration qui le défait.
- **On ne modifie pas une migration déjà appliquée.** Elle ne serait pas rejouée : votre changement ne serait pas dans la base. `wazi db:migrate` vous prévient quand il voit un fichier modifié après coup.

Une migration ne se lance que depuis le terminal. Aucune adresse du site ne modifie la structure de la base.

### La colonne `id` change d'une base à l'autre

| Base | Un numéro donné par la base à chaque ligne |
| --- | --- |
| SQLite | `id INTEGER PRIMARY KEY AUTOINCREMENT` |
| MySQL | `id INT AUTO_INCREMENT PRIMARY KEY` |
| PostgreSQL | `id SERIAL PRIMARY KEY` |

Un projet choisit sa base, et ses migrations sont écrites pour elle. Avec PostgreSQL, `insert()` rend la valeur de la colonne nommée `id`.

## Quand la base refuse

Une erreur de la base devient une `DatabaseException`, qui cite votre requête et la réponse de la base, et dit quoi vérifier :

```text
La base de données a refusé cette requête : SELECT * FROM articles — Réponse de la base :
« SQLSTATE[HY000]: General error: 1 no such table: articles ». Cette table n'existe pas (encore).
Avez-vous lancé « wazi db:migrate » ? Sinon, vérifiez l'orthographe du nom de la table.
```

Comme toute erreur, le visiteur n'en voit rien en production : il reçoit la page d'erreur générique.

### Une valeur déjà prise

Une colonne déclarée `UNIQUE` refuse une valeur qui existe déjà. C'est la bonne façon de garantir qu'une adresse e-mail ne sert qu'à un compte : la base le vérifie au moment d'écrire, même si deux inscriptions arrivent en même temps.

```php
use Wazi\Database\Exception\DatabaseException;

try {
    $db->insert('comptes', ['email' => $email, 'nom' => $nom]);
} catch (DatabaseException $erreur) {
    if (!$erreur->isDuplicate()) {
        throw $erreur;
    }

    $v->check('email', false, 'Cette adresse est déjà utilisée.');
}
```

## Ce que Wazi règle pour vous

- Les requêtes sont **réellement préparées par la base**, qui reçoit les valeurs à part. PHP sait aussi « simuler » cette préparation en collant lui-même les valeurs dans le SQL : Wazi ne le laisse pas faire.
- Une erreur de la base devient toujours une exception : elle ne passe jamais inaperçue.
- SQLite vérifie les liens entre tables (clés étrangères), ce qu'il ne fait pas de lui-même.
- MySQL parle `utf8mb4` : tous les caractères, émojis compris.
- Le mot de passe de la base n'apparaît dans aucun message d'erreur, aucune trace, aucun `var_dump()`.
- Un fichier SQLite placé dans le dossier public du site est refusé : il serait téléchargeable.

## Les différences entre les bases

- **Les booléens** reviennent tels que la base les garde : `0` et `1` pour SQLite et MySQL, `true` et `false` pour PostgreSQL.
- **Les migrations ratées** : SQLite et PostgreSQL défont entièrement une migration qui échoue. MySQL valide chaque changement de structure aussitôt : une migration qui échoue au milieu y reste à moitié appliquée, et le message le dit.
- **SQLite** écrit dans un fichier : il convient très bien à un site de taille modeste sur un seul serveur. Au-delà, passez à MySQL ou PostgreSQL.

## Les limites actuelles

- Pas de classes d'entités ni de constructeur de requêtes : le SQL s'écrit à la main. C'est un choix.
- Un fichier de migration est découpé en requêtes sur les `;`. Un déclencheur ou une procédure, dont le corps contient des `;`, ne passe pas par un fichier de migration.
- Pas de lecture ligne par ligne des très grands résultats : `select()` rend tout d'un coup. Limitez vos requêtes avec `LIMIT`.
- Une seule base par application.

Suite : [La barre de débogage](15-barre-de-debogage.md).
