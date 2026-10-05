# 16. Les zones mises à jour

Ajouter une note, filtrer une liste, supprimer une ligne : à chaque fois, le navigateur recharge toute la page. Les **zones** permettent de n'en remplacer que des morceaux, sans rien changer à vos contrôleurs.

```html
<section k:zone="ajout">
    <form method="post" action="/notes" k:update="ajout, liste, compteur">
        <input name="texte">
        <button>Ajouter</button>
    </form>
</section>

<p k:zone="compteur">{notes | length} note(s)</p>

<ul k:zone="liste">
    <li k:for="note in notes">{note.texte}</li>
</ul>
```

- `k:zone="liste"` marque un morceau de page et lui donne un nom ;
- `k:update="ajout, liste, compteur"`, sur un formulaire ou un lien, dit quelles zones il met à jour. Ici, le formulaire est lui-même dans une zone : après l'ajout, il revient vide.

## Comment ça marche

Rien de caché, trois étapes :

1. le script `wazi.js` fait **la même requête** que le navigateur aurait faite (même adresse, mêmes champs, même jeton de protection) ;
2. votre contrôleur répond **la même page**, entière, comme d'habitude ;
3. le script ne garde de cette page que les zones nommées, et les met à la place des anciennes.

Votre contrôleur ne sait donc pas que les zones existent. Et si JavaScript est coupé, ou si le script n'est pas installé, le formulaire recharge simplement la page : tout marche quand même.

> Écrivez d'abord la page ordinaire, vérifiez qu'elle fonctionne, puis ajoutez `k:zone` et `k:update`. Vous n'aurez rien à réécrire.

Dans le code de la page, les deux attributs deviennent des attributs HTML ordinaires, que vous pouvez voir avec l'inspecteur du navigateur : `data-k-zone="liste"` et `data-k-update="liste compteur"`.

## Installer le script

Une fois par projet :

```bash
wazi zones:install
```

La commande crée `public/wazi.js`. C'est un fichier de votre projet, court et commenté : ouvrez-le. Ajoutez ensuite une ligne à votre mise en page, avant `</head>` :

```html
<script src="/wazi.js" defer></script>
```

Après une mise à jour de Wazi, relancez `wazi zones:install` : la commande remplace le fichier par sa nouvelle version. Ne le modifiez donc pas. Si vous aviez écrit vous-même un fichier `wazi.js`, la commande le reconnaît et n'y touche pas.

Dans le fichier `wazi` de votre projet, la commande se déclare comme les autres :

```php
$console->add(new ZonesInstallCommand(__DIR__));
```

## Un formulaire

```html
<section k:zone="redaction">
    <p k:if="erreur != null" role="alert">{erreur}</p>

    <form method="post" action="/notes" k:update="redaction, liste">
        <textarea name="texte">{saisie}</textarea>
        <button>Ajouter</button>
    </form>
</section>
```

Le contrôleur est celui de la page [Les formulaires](09-formulaires.md), sans une ligne de plus :

- la note est acceptée : il redirige vers `/notes` (303). Le script suit la redirection, reçoit la page des notes, et remplace les zones : la liste a une note de plus, le formulaire est revenu vide ;
- la note est refusée : il répond la page avec le message d'erreur (422). Le script remplace les zones de la même façon : le message apparaît, la saisie est restée.

Le jeton de protection est dans le formulaire, comme toujours : un envoi fait par le script est vérifié comme un envoi ordinaire.

## Un lien, une recherche

```html
<form method="get" action="/notes" k:update="liste">
    <input name="q" type="search" value="{recherche}">
</form>

<nav k:zone="filtres">
    <a href="/notes" k:update="filtres, liste">Toutes</a>
    <a href="/notes?filtre=importantes" k:update="filtres, liste">Épinglées</a>
</nav>
```

Après un lien ou un formulaire `GET`, **l'adresse change** dans la barre du navigateur (`/notes?q=pain`) : elle peut être gardée ou partagée, et elle affiche la même chose si on la recharge. Le bouton « Précédent » fonctionne.

## Quand le script laisse faire le navigateur

Il ne remplace des zones que s'il peut le faire proprement. Sinon :

| Situation | Ce qui se passe |
| --- | --- |
| Clic avec `Ctrl`, clic du milieu, lien avec `target` ou `download` | Le navigateur ouvre le lien comme d'habitude |
| L'adresse est celle d'un autre site | Le navigateur y va comme d'habitude |
| Une zone demandée n'est pas dans la page reçue (le visiteur a été redirigé vers la connexion, une erreur est survenue) | La page reçue s'affiche en entier |
| La réponse n'est pas une page HTML, ou le réseau est coupé | Le navigateur fait l'envoi lui-même |

## Pendant l'attente, et après

Pendant que la page est demandée, chaque zone concernée porte l'attribut `aria-busy="true"`. Une règle de style suffit pour le montrer :

```css
[data-k-zone][aria-busy="true"] {
    opacity: .55;
}
```

Après la mise à jour, le document reçoit l'événement `wazi:updated`. C'est là que votre propre script se rebranche sur ce qui vient d'arriver :

```js
document.addEventListener('wazi:updated', (evenement) => {
    console.log(evenement.detail.zones);   // ['liste', 'compteur']
});
```

Le plus simple est souvent de ne rien avoir à rebrancher : écoutez les clics sur `document` plutôt que sur chaque bouton. Un bouton arrivé après coup est alors entendu comme les autres.

```js
document.addEventListener('click', (evenement) => {
    const bouton = evenement.target.closest('.epingle');

    if (bouton) {
        // ...
    }
});
```

## Les règles

Kioo les vérifie à la lecture du template, et explique ce qui ne va pas.

- Un nom de zone s'écrit **en dur** : minuscules, chiffres, tirets (`liste`, `panier-total`), quarante caractères au plus. Jamais d'accolades : une valeur ne choisit pas quel morceau de page est remplacé.
- `k:zone` se met sur une balise qui a un début et une fin : `<div>`, `<section>`, `<ul>`, `<p>`... Pas sur `<body>`, ni sur une balise qui porte `k:for` : mettez-le sur la balise qui entoure la boucle.
- Un nom de zone ne s'utilise qu'une fois par page.
- `k:update` se met sur un `<form>` ou un `<a>`.

## À savoir

- **Tout le contenu d'une zone est remplacé.** Un champ en cours de saisie y perd ce qui était tapé, sauf si le serveur le réécrit (c'est ce que fait un formulaire refusé). Un champ de recherche que l'on veut garder intact se place hors de la zone, ou porte un `id` : le script y remet le curseur.
- **Un `<script>` écrit dans une zone ne s'exécute pas** quand la zone est remplacée : c'est une protection du navigateur. Utilisez `wazi:updated`.
- **Le serveur calcule la page entière** même pour une petite zone. Ce que vous gagnez : la page n'est pas redessinée, ni ses styles ni ses scripts ne sont rechargés, et la position dans la page est gardée.
- **La barre de débogage suit** : après une mise à jour, elle décrit la dernière requête.
- Les zones ne gardent aucun état dans le navigateur et n'ajoutent aucune adresse à votre site : il n'y a rien de plus à protéger. La décision complète est dans [l'ADR-036](../decisions/0036-zones-mises-a-jour.md).

## Voir un exemple complet

La page des notes de [la démonstration](../../examples/demo/README.md) utilise six zones : ajout, refus, recherche, filtres, suppression avec confirmation. Ouvrez `examples/demo/views/notes/liste.kioo`, puis l'onglet « Réseau » de votre navigateur pendant que vous ajoutez une note.
