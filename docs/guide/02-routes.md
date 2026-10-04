# 2. Les routes

Une route relie **une méthode et une adresse** à **du code**.

```php
$app->router->get('/articles', function () {
    return new Response(200, [], 'La liste des articles');
});
```

## Les méthodes

La méthode dit ce que le visiteur veut faire. Chacune a sa fonction :

```php
$app->router->get('/articles', ...);            // lire
$app->router->post('/articles', ...);           // créer, ou envoyer un formulaire
$app->router->put('/articles/{id:int}', ...);   // remplacer
$app->router->patch('/articles/{id:int}', ...); // modifier en partie
$app->router->delete('/articles/{id:int}', ...);// supprimer
```

Pour une route qui répond à plusieurs méthodes :

```php
$app->router->add(['GET', 'POST'], '/contact', ...);
```

Deux choses à savoir :

- une route `GET` répond aussi aux requêtes `HEAD` (les mêmes en-têtes, sans le contenu) ;
- un formulaire HTML ne sait envoyer que `GET` et `POST`. `PUT`, `PATCH` et `DELETE` servent aux requêtes envoyées par JavaScript ou par un autre programme.

**Une lecture ne modifie rien.** Tout ce qui change quelque chose (créer, supprimer, se déconnecter) passe par `POST` ou une autre méthode d'écriture, jamais par `GET` : un lien peut être suivi par un robot, préchargé par le navigateur, ou placé sur un autre site.

## Les paramètres

Une partie de l'adresse entre accolades est un paramètre :

```php
use Psr\Http\Message\ResponseInterface;

$app->router->get('/articles/{id:int}', function (int $id): ResponseInterface {
    return new Response(200, [], "Article n° $id");
});
```

`{id:int}` dans l'adresse, `int $id` dans la fonction : **le même nom**, donc la valeur arrive dans l'argument. `/articles/42` appelle la fonction avec `$id = 42`.

Après les deux-points vient la **contrainte** : ce que le paramètre a le droit de contenir.

| Contrainte | Accepte | Votre code reçoit |
| --- | --- | --- |
| `{nom}` ou `{nom:any}` | n'importe quel texte, sans `/` | un texte |
| `{id:int}` | un nombre entier positif : `0`, `7`, `42` | un `int` |
| `{slug:slug}` | minuscules, chiffres et tirets : `mon-premier-article` | un texte |
| `{id:uuid}` | un identifiant universel : `123e4567-e89b-12d3-a456-426614174000` | un texte |

Une adresse qui ne respecte pas la contrainte ne correspond pas à la route : `/articles/abc` donne une erreur 404, et votre code n'est pas appelé. Vous n'avez donc pas à vérifier que `$id` est bien un nombre.

Pourquoi une liste fermée, et pas des expressions régulières ? Une expression mal écrite peut mettre plusieurs secondes à répondre sur une adresse piégée. Celles de Wazi sont écrites une fois et bornées en longueur.

Les paramètres sont aussi posés sur la requête : `$request->getAttribute('id')`.

## Quelle route est choisie

Le routeur parcourt les routes **dans l'ordre où vous les avez déclarées** et prend la première qui correspond. L'ordre compte donc :

```php
$app->router->get('/articles/nouveau', ...);   // d'abord l'adresse précise
$app->router->get('/articles/{slug}', ...);    // ensuite l'adresse à paramètre
```

Dans l'autre sens, « nouveau » serait pris pour un slug.

À savoir aussi :

- `/articles` et `/articles/` sont **deux adresses différentes** ;
- déclarer deux fois la même méthode pour la même adresse est une erreur, signalée au démarrage.

## Quand rien ne correspond

| Situation | Réponse |
| --- | --- |
| Aucune route pour cette adresse | 404, page introuvable |
| L'adresse existe, mais pas pour cette méthode | 405, avec l'en-tête `Allow` qui liste les méthodes acceptées |

Vous n'avez rien à écrire pour cela.

## Ce que reçoit le code d'une route

Wazi remplit chaque argument de votre fonction ainsi, dans cet ordre :

1. un argument dont le type est `ServerRequestInterface` reçoit **la requête** ;
2. un argument qui porte le nom d'un paramètre de la route reçoit **sa valeur** ;
3. sinon, sa valeur par défaut, s'il en a une ;
4. sinon, c'est une erreur : Wazi ne devine pas.

```php
use Psr\Http\Message\ServerRequestInterface;

$app->router->get('/articles/{id:int}', function (ServerRequestInterface $request, int $id) {
    $page = $request->getQueryParams()['page'] ?? '1';   // ce qui suit le « ? » dans l'adresse
    // ...
});
```

Ce qui suit le `?` dans l'adresse (`?page=2`) ne fait pas partie de la route : on le lit sur la requête. Voir [Requêtes et réponses](04-requetes-et-reponses.md).

## Les routes d'un contrôleur

Dès qu'il y a plus de quelques routes, on les range dans des classes, et on écrit chaque route au-dessus de sa méthode :

```php
use Wazi\Routing\Attribute\Get;
use Wazi\Routing\Attribute\Post;

final class ArticleController
{
    #[Get('/articles')]
    public function liste(): ResponseInterface { /* ... */ }

    #[Get('/articles/{id:int}')]
    public function voir(int $id): ResponseInterface { /* ... */ }

    #[Post('/articles')]
    public function creer(ServerRequestInterface $request): ResponseInterface { /* ... */ }
}
```

Puis, dans `app.php`, une ligne par contrôleur :

```php
$app->router->addController(ArticleController::class);
```

Il existe un attribut par méthode : `#[Get]`, `#[Post]`, `#[Put]`, `#[Patch]`, `#[Delete]`.

Wazi ne parcourt aucun dossier à la recherche de contrôleurs : vous les déclarez un par un. Vous savez ainsi toujours d'où vient une route. Une méthode publique sans attribut n'est pas une route.

Pour voir toutes les routes de votre application, dans l'ordre où le routeur les essaie :

```bash
wazi routes
```

La page suivante explique les contrôleurs : [Contrôleurs et services](03-controleurs-et-services.md).

## Les middlewares d'une route

Le dernier argument d'une route est la liste des middlewares qui la gardent :

```php
$app->router->get('/admin', [AdminController::class, 'accueil'], [ConnexionRequise::class]);

#[Get('/admin', [ConnexionRequise::class])]
public function accueil(): ResponseInterface { /* ... */ }
```

Voir [Les middlewares](05-middlewares.md).
