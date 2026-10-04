# 4. Requêtes et réponses

Le web tient en un échange : le navigateur envoie une **requête**, le serveur retourne une **réponse**. Dans Wazi, ce sont deux objets, conformes au standard PSR-7 : ce que vous apprenez ici vaut dans les autres frameworks PHP.

## La requête

Pour la recevoir, demandez-la en argument :

```php
use Psr\Http\Message\ServerRequestInterface;

public function creer(ServerRequestInterface $request): ResponseInterface
```

| Ce que vous cherchez | Comment le lire |
| --- | --- |
| Ce qui suit le `?` dans l'adresse | `$request->getQueryParams()['page'] ?? null` |
| Les champs d'un formulaire envoyé en POST | `$request->getParsedBody()` |
| Un paramètre de la route | l'argument de même nom, ou `$request->getAttribute('id')` |
| Un en-tête | `$request->getHeaderLine('Accept')` |
| Un cookie | `$request->getCookieParams()['theme'] ?? null` |
| Un fichier envoyé | `$request->getUploadedFiles()['avatar'] ?? null` |
| Le contenu brut (du JSON, par exemple) | `(string) $request->getBody()` |
| La méthode, l'adresse | `$request->getMethod()`, `$request->getUri()->getPath()` |
| L'adresse IP du visiteur | `$request->getAttribute('client_ip')` |

### Tout ce qui vient de la requête est à vérifier

Un visiteur peut envoyer n'importe quoi : un champ en moins, un tableau à la place d'un texte, un texte de dix mille caractères. Avant d'utiliser une valeur, vérifiez **sa présence et son type** :

```php
$formulaire = (array) $request->getParsedBody();
$titre = is_string($formulaire['titre'] ?? null) ? trim($formulaire['titre']) : '';
```

Un champ nommé `titre[]` dans un formulaire arrive comme un tableau : sans `is_string()`, `trim()` échouerait.

Pour un formulaire, le `Validator` fait ces vérifications pour vous, champ par champ : voir [Les formulaires](09-formulaires.md).

### Du JSON

`getParsedBody()` ne contient que les champs d'un formulaire HTML. Pour une requête JSON, décodez le contenu vous-même :

```php
$donnees = json_decode((string) $request->getBody(), true);

if (!is_array($donnees)) {
    return new Response(400, ['Content-Type' => 'text/plain; charset=utf-8'], 'JSON attendu.');
}
```

### Un fichier envoyé

```php
$avatar = $request->getUploadedFiles()['avatar'] ?? null;

if ($avatar instanceof UploadedFileInterface && $avatar->getError() === UPLOAD_ERR_OK) {
    $avatar->moveTo(__DIR__ . '/../var/avatars/' . $utilisateurId . '.png');
}
```

Deux règles de sécurité :

- `getClientFilename()` et `getClientMediaType()` retournent ce que le navigateur **prétend**. Un attaquant y écrit ce qu'il veut. **Ne construisez jamais le chemin de destination avec le nom donné par le visiteur** : choisissez vous-même le nom et l'extension ;
- ne rangez pas les fichiers reçus dans le dossier `public/` sans contrôle : un fichier `.php` déposé là serait exécuté par le serveur.

### Ce que Wazi refuse avant vous

Certaines requêtes n'atteignent jamais votre code :

| Requête | Réponse |
| --- | --- |
| Un contenu de plus de 8 Mo | 413 |
| Un nom d'hôte mal formé, ou absent de vos `trustedHosts` | 400 |
| Une requête mal formée | 400 |

La limite de taille se règle : `new ServerRequestCreator(maxBodySize: 2 * 1024 * 1024)`. Les hôtes de confiance sont expliqués dans [Mettre en ligne](12-deploiement.md).

## La réponse

```php
use Wazi\Http\Response;

return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], '<h1>Bonjour</h1>');
```

Trois arguments : le **code de statut**, les **en-têtes**, le **contenu**.

### Les codes de statut utiles

| Code | Sens | Quand |
| --- | --- | --- |
| 200 | Tout va bien | Une page affichée |
| 303 | Allez voir ailleurs, avec un GET | Après un formulaire réussi |
| 400 | La requête est incorrecte | Du JSON illisible |
| 403 | Accès refusé | Le visiteur n'a pas le droit |
| 404 | Introuvable | L'article demandé n'existe pas |
| 422 | Le contenu ne convient pas | Un formulaire mal rempli, réaffiché avec ses erreurs |
| 500 | Panne | Une exception non prévue (Wazi répond pour vous) |

### Les réponses courantes

Une page, avec un template (voir [Kioo](06-kioo.md)) :

```php
return $this->kioo->page('articles/liste', ['articles' => $articles]);
return $this->kioo->page('articles/introuvable', ['id' => $id], 404);
```

Une redirection :

```php
return new Response(303, ['Location' => '/articles']);
```

Du JSON :

```php
return new Response(200, ['Content-Type' => 'application/json'], json_encode($donnees, JSON_THROW_ON_ERROR));
```

Du texte :

```php
return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], 'Message reçu.');
```

Précisez toujours `Content-Type` : c'est lui qui dit au navigateur comment lire le contenu.

### Une redirection ne suit jamais une adresse reçue

```php
// ⚠ Dangereux : le visiteur choisit où il est envoyé.
return new Response(303, ['Location' => $request->getQueryParams()['retour']]);
```

Quelqu'un peut fabriquer un lien vers votre site qui renvoie vers le sien, en profitant de la confiance qu'inspire votre adresse. Redirigez vers une adresse écrite dans votre code, ou choisie dans une liste.

## Des objets qui ne changent pas

Une requête et une réponse sont **immuables** : les méthodes `with...()` ne modifient pas l'objet, elles en retournent **un nouveau**.

```php
$response->withHeader('Cache-Control', 'no-store');              // ⚠ ne fait rien : le résultat est perdu
$response = $response->withHeader('Cache-Control', 'no-store');  // correct
```

PHP et votre éditeur vous préviennent quand le résultat d'un `with...()` est ignoré.

Pourquoi ? Un objet qui ne change pas ne peut pas être modifié dans votre dos par un autre morceau de code. Ce que vous tenez reste ce que vous avez lu.

## N'écrivez pas avec `echo`

Une application Wazi ne fait jamais `echo` ni `header()` : elle **retourne** une réponse, et le noyau l'envoie. Un `echo` oublié est détecté et signalé par une erreur.

Suite : [Les middlewares](05-middlewares.md).
