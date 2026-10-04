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
use Wazi\Validation\Validator;

#[Post('/notes')]
public function ajouter(ServerRequestInterface $request): ResponseInterface
{
    $v = new Validator($request->getParsedBody());
    $texte = $v->longText('texte', max: 280);

    if ($v->fails()) {
        // On réaffiche le formulaire, avec ce qui a été saisi et ce qui ne va pas.
        return $this->kioo->page('notes/liste', ['saisie' => $v->input(), 'erreurs' => $v->errors()], 422);
    }

    $this->carnet->ajouter($texte);
    $this->session->flash('succes', 'Note ajoutée.');

    return new Response(303, ['Location' => '/notes']);
}
```

Le `Validator` reçoit les champs du formulaire. Chaque méthode fait trois choses : elle **lit** un champ, le **vérifie**, et **retourne sa valeur dans le bon type**. Si le champ ne convient pas, elle note un message d'erreur pour lui et retourne une valeur vide.

### Seule la vérification du serveur compte

Les attributs `required` et `maxlength` aident le visiteur, mais ne protègent rien : on peut envoyer un formulaire sans navigateur, avec n'importe quel contenu. **Tout ce qui est reçu est vérifié par votre code** : présence, type, longueur, valeur permise.

### Une méthode par sorte de champ

| Méthode | Pour | Retourne |
| --- | --- | --- |
| `text('nom', max: 80)` | un texte court, sur une ligne | `string` |
| `longText('message', max: 2000)` | un texte de plusieurs lignes | `string` |
| `integer('age', min: 18, max: 120)` | un nombre entier | `int`, ou `null` |
| `decimal('prix', min: 0)` | un nombre à virgule (`12,5` ou `12.5`) | `float`, ou `null` |
| `email('email')` | une adresse e-mail | `string` |
| `choice('sujet', ['devis', 'question'])` | une valeur d'une liste (`<select>`, boutons radio) | `string` |
| `checkbox('conditions')` | une case à cocher | `bool` |
| `date('naissance', max: new DateTimeImmutable())` | une date (`<input type="date">`) | `DateTimeImmutable`, ou `null` |
| `password('mot_de_passe')` | un mot de passe (8 caractères au moins) | `string` |

Un champ refusé ou laissé vide retourne `''` pour un texte, `null` pour un nombre ou une date.

### Les défauts sont stricts

Sans rien écrire de plus :

- un champ est **obligatoire** ;
- un texte fait **255 caractères au plus** (5 000 pour `longText()`) ;
- un texte court tient sur **une seule ligne** ;
- un texte est débarrassé des espaces qui l'entourent.

Ce qui est plus souple s'écrit, et se voit donc à la lecture :

```php
$telephone = $v->text('telephone', required: false);
$article = $v->longText('article', max: 20000);
```

Oublier une règle donne un formulaire trop sévère, jamais un formulaire trop ouvert.

### Ce qui est refusé d'office

- **Un champ qui n'est pas un texte.** Un champ nommé `nom[]` arrive comme un tableau : il est refusé, au lieu de faire échouer votre code.
- **Les caractères de contrôle et les textes mal encodés.** Ils abîment les pages, les journaux et les fichiers.
- **Les nombres mal formés.** PHP lit `12abc` comme 12 et `1e3` comme 1000. Ici, seuls des chiffres font un nombre.
- **Les dates qui n'existent pas.** PHP reporte le 31 février au mois de mars. Ici, il est refusé.
- **Une valeur hors de la liste.** La liste affichée par un `<select>` ne protège rien : `choice()` compare la valeur reçue à la liste que vous lui donnez.

### Afficher les erreurs

`errors()` donne un message par champ refusé ; `input()` donne ce que le visiteur a saisi, pour qu'il n'ait pas tout à retaper.

```html
<label for="nom">Votre nom</label>
<input id="nom" name="nom" value="{saisie.nom ?? ''}">
<p k:if="(erreurs.nom ?? null) != null" class="erreur" role="alert">{erreurs.nom}</p>
```

Les messages sont courts et s'adressent au visiteur : « Ce champ est obligatoire. », « Écrivez un nombre entre 18 et 120. ». **Ils ne recopient jamais la valeur reçue.** Pour écrire le vôtre :

```php
$age = $v->integer('age', min: 18, message: 'Vous devez être majeur pour vous inscrire.');
```

### Ce qu'on ne renvoie jamais

En réaffichant un formulaire, on remet ce qui a été saisi, **sauf un mot de passe**. `input()` ne contient jamais un champ lu par `password()` : un champ de mot de passe revient toujours vide.

### Vos propres règles

Une règle propre à votre application s'écrit en PHP ordinaire, avec `check()` :

```php
$email = $v->email('email');
$v->check('email', !$this->comptes->existe($email), 'Cette adresse est déjà utilisée.');

$debut = $v->date('debut');
$fin = $v->date('fin');
$v->check('fin', $debut === null || $fin === null || $fin >= $debut, 'La fin vient après le début.');
```

Si la condition est fausse, le message devient l'erreur du champ. La première erreur d'un champ est celle qu'on affiche.

Aucune règle ne prend d'expression régulière : une expression mal écrite peut bloquer un serveur avec un seul texte bien choisi.

### N'enregistrer que ce qui a été vérifié

`values()` donne toutes les valeurs vérifiées, dans leur type :

```php
$v->text('nom', max: 80);
$v->email('email');

if (!$v->fails()) {
    $this->comptes->creer($v->values());   // ['nom' => '...', 'email' => '...']
}
```

Seuls les champs que **vous** avez demandés y figurent. Un visiteur qui ajoute à la main un champ `role=admin` à son formulaire ne le retrouvera jamais dans `values()`. N'enregistrez jamais directement `getParsedBody()`.

### Cases à cocher

Une case non cochée n'est pas envoyée du tout : ce n'est jamais une erreur, `checkbox()` retourne simplement `false`.

```php
$importante = $v->checkbox('importante');
```

```html
<input type="checkbox" name="importante" value="1" checked="{note.importante}">
```

### Les paramètres d'une adresse

Le validateur ne connaît pas la requête : on lui donne des champs. Il vérifie donc aussi bien ce qui suit le « ? » d'une adresse :

```php
$v = new Validator($request->getQueryParams());
$page = $v->integer('page', required: false, min: 1) ?? 1;
```

### Ce qu'il ne fait pas encore

Les fichiers envoyés, les champs imbriqués (`adresse[ville]`) et les listes (`tags[]`) ne sont pas vérifiés par le validateur.

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
