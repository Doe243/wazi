# 6. Les templates Kioo

Kioo (« vitre » en swahili) est le moteur de templates de Wazi. Un template Kioo est **une page HTML ordinaire**, rangée dans un fichier `.kioo`. On y affiche une valeur entre accolades, et quelques attributs `k:` décident de ce qui est écrit.

```html
<h1>{titre}</h1>

<ul>
    <li k:for="note in notes" class="{note.importante ? 'importante' : ''}">
        <a href="/notes/{note.id}">{note.texte}</a>
    </li>
    <li k:else>Aucune note pour l'instant.</li>
</ul>
```

## Brancher Kioo

Indiquez au noyau le dossier de vos templates :

```php
$app = new Kernel(views: __DIR__ . '/../views');
```

Dans un contrôleur, demandez `Kioo` dans le constructeur, et retournez une page :

```php
use Wazi\View\Kioo;

final class NoteController
{
    public function __construct(private readonly Kioo $kioo) {}

    #[Get('/notes')]
    public function liste(): ResponseInterface
    {
        return $this->kioo->page('notes/liste', ['titre' => 'Mes notes', 'notes' => $notes]);
    }
}
```

`page('notes/liste', ...)` lit le fichier `views/notes/liste.kioo`. Le deuxième argument donne les **variables** du template. Un troisième choisit le code de statut : `page('introuvable', [], 404)`.

## Afficher une valeur

```html
<p>Bonjour {nom} !</p>
```

Tout ce qui est affiché est **échappé** : si `nom` vaut `René <b>`, la page contient `René &lt;b&gt;`. Le navigateur affiche le texte tel qu'il a été saisi, il ne l'interprète pas. C'est la protection contre l'injection de code dans une page (la faille « XSS »), et vous n'avez rien à faire pour l'obtenir.

Kioo affiche un **texte** ou un **nombre**. `null` n'affiche rien. Pour le reste, il demande de préciser :

| Valeur | Ce qu'il faut écrire |
| --- | --- |
| vrai / faux | `{actif ? 'oui' : 'non'}` |
| une liste | `k:for`, ou `{notes \| join(', ')}` |
| un objet | une de ses propriétés : `{article.titre}` |
| une date | `{date \| date('d/m/Y')}` |

Pour écrire une vraie accolade, faites-la précéder d'une barre inversée : `\{`.

## Les expressions

Entre les accolades, on écrit une expression. Ce n'est pas du PHP : c'est un petit langage, volontairement limité.

| Écriture | Sens |
| --- | --- |
| `note.texte` | la clé d'un tableau, ou la propriété publique d'un objet |
| `notes[0]`, `couleurs['fond']` | un élément, par sa position ou sa clé |
| `article.resume(80)` | une méthode publique d'un objet |
| `'texte'`, `42`, `1.5`, `true`, `false`, `null` | des valeurs écrites en dur |
| `+ - * / %` | calculer (avec des nombres seulement) |
| `== != < > <= >=` | comparer |
| `and`, `or`, `not` | combiner des conditions |
| `condition ? a : b` | l'un ou l'autre |
| `valeur ?? 'défaut'` | la valeur, ou le défaut si elle est absente ou `null` |

### Kioo est strict, et c'est voulu

Une variable, une clé ou une propriété **inconnue est une erreur**, pas un vide :

```text
La variable « nomm » n'existe pas dans ce template. Vouliez-vous écrire « nom » ?
```

Une faute de frappe se voit donc tout de suite, au lieu de donner une page silencieusement incomplète. Quand l'absence est prévue, dites-le avec `??` :

```html
<p k:if="(erreur ?? null) != null" class="erreur">{erreur}</p>
```

De même, Kioo ne transforme jamais un texte en nombre en silence : `{quantite + '3'}` est une erreur, et `3 == '3'` est faux.

## Les filtres

Un filtre transforme une valeur avant de l'afficher. Il s'écrit après une barre verticale :

| Filtre | Exemple | Résultat |
| --- | --- | --- |
| `upper` | `{'bonjour' \| upper}` | `BONJOUR` |
| `lower` | `{'BONJOUR' \| lower}` | `bonjour` |
| `capitalize` | `{'bonjour le monde' \| capitalize}` | `Bonjour le monde` |
| `trim` | `{'  bonjour  ' \| trim}` | `bonjour` |
| `length` | `{notes \| length}` | le nombre d'éléments d'une liste, ou de caractères d'un texte |
| `number` | `{1234.5 \| number(2)}` | `1 234,50` |
| `date` | `{date \| date('d/m/Y à H:i')}` | `03/10/2026 à 17:30` |
| `join` | `{notes \| join(' et ')}` | `Pain et Lait` |
| `first`, `last` | `{notes \| first}` | le premier, le dernier élément |
| `json` | `data-points="{points \| json}"` | la valeur écrite en JSON, pour un attribut |
| `url` | `href="/recherche?q={mots \| url}"` | le texte préparé pour entrer dans une adresse |

Le filtre `date` attend un objet `DateTime` ou `DateTimeImmutable`. Son format est celui de PHP : `d` (jour), `m` (mois), `Y` (année), `H` (heures), `i` (minutes).

Les filtres s'enchaînent : `{titre | trim | upper}`.

### Un filtre s'applique à tout ce qui est à sa gauche

`{prix * 2 | number}` formate le produit, pas seulement `2`. En contrepartie, **rien ne suit un filtre** sans parenthèses :

```html
{(notes | length) > 1 ? 'plusieurs notes' : 'une note'}
```

### Vos propres filtres

Un filtre est une fonction. Ajoutez-la dans `app.php`, après avoir créé le noyau :

```php
use Wazi\View\Kioo;

$kioo = $app->container->get(Kioo::class);

$kioo->addFilter('euros', fn (mixed $prix): string => number_format((float) $prix, 2, ',', ' ') . ' €');
```

```html
{article.prix | euros}
```

La fonction reçoit la valeur écrite à gauche de la barre, puis les arguments écrits entre parenthèses. Ce qu'elle retourne est échappé comme toute valeur affichée.

Un filtre ne peut pas en remplacer un autre : son nom doit être libre.

Un template ne peut appeler **que** les filtres de cette liste et les vôtres. Aucune fonction de PHP n'est accessible depuis un template.

## Les structures

### `k:if` et `k:else`

```html
<p k:if="(notes | length) > 5">Vous avez beaucoup de notes.</p>
<p k:else>Vous avez peu de notes.</p>
```

La balise est écrite si la condition est vraie. Sont « faux » : `false`, `null`, le texte vide, le nombre zéro et la liste vide. `<p k:if="notes">` suffit donc pour « s'il y a des notes ».

`k:else` se pose sur la balise **qui suit** celle du `k:if`. Entre les deux, seuls des espaces et des commentaires sont permis.

### `k:for`

```html
<li k:for="note in notes">{note.texte}</li>
<li k:else>Aucune note.</li>
```

La balise est écrite une fois par élément. Avec un `k:else` juste après, c'est lui qui est écrit quand la liste est vide.

Pour avoir aussi la clé, ou la position :

```html
<li k:for="position, note in notes">{position + 1}. {note.texte}</li>
<li k:for="nom, valeur in reglages">{nom} : {valeur}</li>
```

### Les règles

- La valeur de `k:if` et `k:for` s'écrit **sans accolades**.
- Une balise ne porte qu'**un seul** attribut de structure. Pour combiner, on en met un sur une balise qui entoure l'autre.
- Une balise qui porte une structure doit être fermée (`<li k:for="...">...</li>`).

## Les attributs

Une valeur s'affiche aussi dans un attribut :

```html
<a href="/notes/{note.id}" class="note {note.importante ? 'importante' : ''}">
```

**Attributs vrai / faux.** Quand un attribut est fait d'un seul affichage, il est écrit si la valeur est vraie, retiré si elle est fausse ou `null` :

```html
<input type="checkbox" checked="{note.importante}">
<!-- donne <input type="checkbox" checked>  ou  <input type="checkbox"> -->
```

**Adresses.** Dans `href`, `src` et les attributs du même genre, Kioo vérifie le protocole. Une adresse dangereuse (`javascript:...`) devient `#`.

Kioo échappe une valeur pour le HTML, pas pour une adresse. Un texte libre placé dans une adresse passe par le filtre `url`, qui remplace les espaces, les `&`, les `#` :

```html
<a href="/recherche?q={mots | url}">Chercher « {mots} »</a>
```

**Attributs d'événement.** Afficher une valeur dans `onclick`, `onload`... est refusé : à cet endroit, aucun échappement ne protège la page. Mettez le script dans un fichier `.js` et passez la valeur par un attribut `data-` : `data-id="{note.id}"`.

## La mise en page

Toutes les pages d'un site partagent le même squelette. On l'écrit une fois, dans `views/base.kioo` :

```html
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title><k:block name="titre">Mon site</k:block></title>
</head>
<body>
    <main>
        <k:block name="content"></k:block>
    </main>
    <k:include file="partiels/pied" annee="{annee}">
</body>
</html>
```

Chaque `<k:block>` est un **emplacement**, avec un contenu par défaut. Une page dit dans quelle mise en page elle se place, et remplit les emplacements :

```html
<k:layout name="base">
<k:block name="titre">Mes notes</k:block>

<h1>Mes notes</h1>
<p>Tout ce qui n'est pas dans un bloc va dans l'emplacement « content ».</p>
```

Les règles :

- `<k:layout>` est la première chose du fichier ; une mise en page n'en utilise pas une autre ;
- un emplacement que la page ne remplit pas garde son contenu par défaut ;
- la mise en page reçoit les mêmes variables que la page.

### Les variables que toutes les pages affichent

La mise en page affiche souvent des valeurs communes : le nom du visiteur dans le bandeau, l'année dans le pied de page. Plutôt que de les passer depuis chaque contrôleur, partagez-les une fois, dans `app.php` :

```php
$kioo = $app->container->get(Kioo::class);

$kioo->share('annee', (int) date('Y'));
```

Tous les templates voient alors `{annee}` : pages, mises en page et morceaux inclus.

Quand la valeur n'est connue qu'au moment d'afficher la page (elle dépend de la session, par exemple), donnez une fonction. Elle est appelée une fois par page affichée :

```php
$session = $app->container->get(Session::class);

$kioo->share('utilisateur', fn () => $session->get('utilisateur'));
```

Une variable de même nom donnée à une page l'emporte sur la variable partagée. La démonstration s'en sert dans [`app.php`](../../examples/demo/app.php).

### Inclure un morceau

```html
<k:include file="partiels/pied" annee="{annee}">
```

Le template inclus **ne voit que ce qu'on lui passe** par les attributs (ici, `annee`), plus les variables partagées avec `share()`. On sait ainsi, en lisant la ligne, de quoi le morceau dépend.

Les noms de `file` et de `name` s'écrivent en dur : ils ne peuvent pas venir d'une valeur. Un template ne peut pas sortir du dossier des vues.

## Kioo et JavaScript

### Les scripts de vos templates

```html
<script src="/app.js" defer></script>

<script>
    document.getElementById('compteur').textContent = 'Chargé.';
</script>
```

Les deux fonctionnent. Kioo pose sur chaque balise `<script>` d'un template un jeton, renouvelé à chaque requête, que la politique de sécurité autorise. Un script injecté par un visiteur ne connaît pas ce jeton : le navigateur refuse de l'exécuter.

Le contenu d'une balise `<script>` ou `<style>` n'est **jamais interprété** par Kioo : les accolades y sont du JavaScript ou du CSS, pas des affichages.

### Passer une valeur à un script

On n'écrit donc pas une valeur dans du code JavaScript. On la dépose à côté :

```html
<k:json id="donnees" value="{notes}">

<script>
    const notes = JSON.parse(document.getElementById('donnees').textContent);
</script>
```

`<k:json>` produit une balise que le navigateur garde comme une donnée, sans l'exécuter. Pour une petite valeur, un attribut suffit : `<div data-id="{note.id}">`, ou `data-points="{points | json}"` pour une liste.

## Les formulaires

Kioo ajoute de lui-même, à chaque `<form method="post">` envoyé à votre site, le champ caché du jeton de protection. Vous n'écrivez rien. Voir [Les formulaires](09-formulaires.md).

## Écrire du HTML tel quel

Pour afficher du HTML que **vous** avez produit et que vous savez sûr :

```html
{article.contenuHtml | unsafe_raw}
```

Le nom est long et inquiétant exprès : tout ce qui passe par là est exécuté par le navigateur tel quel. N'y mettez jamais un texte saisi par un visiteur sans l'avoir nettoyé.

## Les commentaires

```html
<!-- À revoir : la liste devrait être triée par date. -->
<ul>
```

Un commentaire d'un template s'adresse à vous, pas au visiteur : **il n'est jamais écrit dans la page**. Vous pouvez donc expliquer un template autant que vous le voulez, sans alourdir la page ni renseigner quelqu'un qui lirait son code source.

## Quand un template est faux

L'erreur donne le nom du template, la ligne, ce qui ne va pas, et souvent la correction :

```text
Dans le template « notes/liste », ligne 12 : Le filtre « uper » n'existe pas.
Vouliez-vous écrire « upper » ? Filtres disponibles : capitalize, date, first, ...
```

## Ce que Kioo ne fait pas encore

Kioo est plus simple et plus sûr que les moteurs les plus connus, pas plus puissant. Il n'a pas encore : de composants réutilisables avec emplacements, de traduction, ni d'opérateur pour coller deux textes.

Côté vitesse : pendant que vous développez, un template est relu et analysé à chaque requête, ce qui prend quelques millisecondes par page. En ligne, la commande `wazi views:compile` fait cette analyse une fois pour toutes, au déploiement : voir [Mettre en ligne](12-deploiement.md).

Suite : [La configuration](07-configuration.md).
