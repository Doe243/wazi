# 9. Les formulaires

## Le parcours d'un formulaire

Il est toujours le même :

```text
GET  /notes        afficher le formulaire
POST /notes        recevoir  →  vérifier  →  enregistrer  →  noter un message  →  rediriger (303)
GET  /notes        afficher le message, une fois
```

**Pourquoi rediriger ?** Si la réponse au `POST` était directement une page, recharger cette page renverrait le formulaire : la note serait créée deux fois. Après une redirection, recharger ne fait que relire.

## Afficher

```html
<form method="post" action="/notes">
    <label for="texte">Nouvelle note</label>
    <textarea id="texte" name="texte" maxlength="280" required>{saisie}</textarea>
    <button>Ajouter</button>
</form>
```

Regardez le code source de la page dans votre navigateur : Kioo a ajouté un champ caché `_csrf`. C'est le jeton de protection, expliqué plus bas.

## Recevoir et vérifier

```php
#[Post('/notes')]
public function ajouter(ServerRequestInterface $request): ResponseInterface
{
    $formulaire = (array) $request->getParsedBody();
    $texte = is_string($formulaire['texte'] ?? null) ? trim($formulaire['texte']) : '';

    $erreur = match (true) {
        $texte === '' => 'Écrivez quelque chose : une note vide n\'est pas enregistrée.',
        mb_strlen($texte) > 280 => 'Cette note est trop longue : 280 caractères au maximum.',
        default => null,
    };

    if ($erreur !== null) {
        // On réaffiche le formulaire, avec ce qui a été saisi et ce qui ne va pas.
        return $this->kioo->page('notes/liste', ['saisie' => $texte, 'erreur' => $erreur], 422);
    }

    $this->carnet->ajouter($texte);
    $this->session->flash('succes', 'Note ajoutée.');

    return new Response(303, ['Location' => '/notes']);
}
```

### Seule la vérification du serveur compte

Les attributs `required` et `maxlength` aident le visiteur, mais ne protègent rien : on peut envoyer un formulaire sans navigateur, avec n'importe quel contenu. **Tout ce qui est reçu est vérifié par votre code** : présence, type, longueur, valeur permise.

Quand une valeur doit faire partie d'une liste (une catégorie, un rôle), comparez-la à cette liste :

```php
$categorie = in_array($formulaire['categorie'] ?? null, ['travail', 'maison'], true) ? $formulaire['categorie'] : 'maison';
```

Wazi n'a pas encore de composant de validation : il est prévu pour la version 0.4.

### Ce qu'on ne renvoie jamais

En réaffichant un formulaire, on remet ce qui a été saisi, **sauf un mot de passe**. Un champ de mot de passe revient toujours vide.

### Cases à cocher

Une case non cochée n'est pas envoyée du tout :

```php
$importante = ($formulaire['importante'] ?? null) === '1';
```

```html
<input type="checkbox" name="importante" value="1" checked="{note.importante}">
```

## La protection contre la falsification de requête (CSRF)

### L'attaque

Un visiteur est connecté à votre site. Il ouvre, dans un autre onglet, une page piégée. Cette page contient un formulaire caché qui s'envoie tout seul **vers votre site** : « supprimer mon compte », « changer mon adresse ». Le navigateur y joint les cookies du visiteur. Pour votre site, c'est lui qui agit.

### La parade

1. Votre site donne au navigateur un **jeton** tiré au hasard, dans un cookie.
2. Chaque formulaire de **vos** pages répète ce jeton dans un champ caché.
3. À la réception, le champ doit être égal au cookie.

La page piégée peut faire partir le cookie, mais elle ne peut pas le lire : elle ne sait pas quoi écrire dans le champ.

### Ce que vous avez à faire : rien

- Kioo ajoute le champ à chaque `<form method="post">` envoyé à votre site.
- Wazi vérifie le jeton pour **toute requête qui modifie** (tout sauf `GET`, `HEAD` et `OPTIONS`), sur toutes les routes, avec ou sans sessions.
- Une requête sans le bon jeton reçoit une réponse 403, et votre code n'est pas appelé.

Kioo n'ajoute jamais le jeton à un formulaire envoyé vers un autre site, ni à un formulaire `GET` : le jeton se retrouverait dans une adresse.

### Une requête envoyée par JavaScript

Il n'y a pas de formulaire, donc pas de champ. Le jeton voyage dans l'en-tête `X-CSRF-Token`. Le contrôleur le donne à la page :

```php
use Wazi\Http\CsrfToken;

public function __construct(private readonly Kioo $kioo, private readonly CsrfToken $jeton) {}

// ...
return $this->kioo->page('notes/liste', ['jeton' => $this->jeton->value()]);
```

```html
<meta name="jeton" content="{jeton}">
```

```js
const jeton = document.querySelector('meta[name="jeton"]').content;

await fetch('/notes/3/importante', {
    method: 'PATCH',
    headers: { 'X-CSRF-Token': jeton },
});
```

### Une route appelée par un autre programme

Un prestataire de paiement qui prévient votre site n'a ni cookie ni jeton. On dispense **cette route** de la vérification :

```php
use Wazi\Middleware\WithoutCsrf;

#[Post('/webhooks/paiement', [WithoutCsrf::class])]
public function paiementRecu(ServerRequestInterface $request): ResponseInterface
```

Il n'existe aucun interrupteur global : la dispense se pose route par route, là où on la voit.

Dispenser une route ne veut pas dire l'ouvrir à tous. Vérifiez autrement d'où vient la requête, en général par une **signature** : l'expéditeur signe son message avec un secret que vous partagez.

```php
$attendue = hash_hmac('sha256', (string) $request->getBody(), $this->secret);

if (!hash_equals($attendue, $request->getHeaderLine('X-Signature'))) {
    return new Response(403, ['Content-Type' => 'text/plain; charset=utf-8'], 'Signature incorrecte.');
}
```

`hash_equals()` compare en un temps constant : la durée de la comparaison ne révèle rien de la signature attendue. Utilisez-le pour comparer tout secret.

### Si un formulaire est refusé

Une réponse 403 sur un formulaire qui vous semble correct a presque toujours une de ces causes :

- le formulaire est écrit dans une chaîne PHP, pas dans un template Kioo : ajoutez le champ vous-même, `<input type="hidden" name="_csrf" value="...">` avec la valeur de `CsrfToken::value()` ;
- les cookies sont bloqués dans le navigateur ;
- la page du formulaire a été mise en cache et servie à un autre visiteur : une page qui contient un formulaire `POST` ne se met pas dans un cache partagé.

En mode développement, la page d'erreur dit laquelle des protections a refusé la requête.

Suite : [Les erreurs](10-erreurs.md).
