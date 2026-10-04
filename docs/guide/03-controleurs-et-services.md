# 3. Contrôleurs et services

## Deux rôles, deux sortes de classes

- Un **service** fait un travail : ranger des articles, envoyer un courriel, calculer un prix. Il ne sait rien du web.
- Un **contrôleur** traduit une requête en réponse. Il lit ce que le visiteur demande, confie le travail à des services, et retourne une page.

Les séparer rend chaque classe courte et lisible, et le service réutilisable ailleurs (dans un test, une commande).

```php
// Le service : il ne sait rien du web.
final class Catalogue
{
    /** @return list<string> */
    public function titres(): array
    {
        return ['Premier article', 'Deuxième article'];
    }
}

// Le contrôleur : il traduit une requête en réponse.
final class ArticleController
{
    public function __construct(private readonly Catalogue $catalogue) {}

    #[Get('/articles')]
    public function liste(): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], implode("\n", $this->catalogue->titres()));
    }
}
```

```php
$app->router->addController(ArticleController::class);
```

Personne n'a écrit `new ArticleController(new Catalogue())`. C'est le travail du conteneur.

## Le conteneur

Pour fabriquer un contrôleur, il faut d'abord fabriquer ce dont il a besoin, et ce dont ses besoins ont besoin. Le **conteneur** le fait à votre place : il lit le constructeur de la classe demandée, fabrique chaque objet attendu, et recommence pour chacun.

Règle à retenir : **ce dont une classe a besoin se demande dans son constructeur.**

```php
public function __construct(
    private readonly Catalogue $catalogue,
    private readonly Kioo $kioo,          // le moteur de templates
    private readonly Session $session,    // la session du visiteur
) {}
```

Chaque service n'est fabriqué **qu'une fois** par requête : deux classes qui demandent un `Catalogue` reçoivent le même objet.

Le conteneur du noyau est accessible par `$app->container`.

### Quand le conteneur ne peut pas deviner

Il ne sait fabriquer seul que des **objets**. Deux cas demandent une explication.

**La classe a besoin d'un texte ou d'un nombre** (un chemin, une adresse, un secret). On donne une recette :

```php
$app->container->set(Catalogue::class, fn () => new Catalogue(__DIR__ . '/../var/articles.json'));
```

La recette n'est exécutée qu'à la première demande. Elle reçoit le conteneur, pour aller y chercher d'autres services :

```php
use Wazi\Container\Container;

$app->container->set(Lettre::class, fn (Container $c) => new Lettre($c->get(Catalogue::class), 'contact@exemple.com'));
```

**La classe demande une interface.** On dit quelle classe fournir :

```php
$app->container->bind(Messagerie::class, MessagerieSmtp::class);
```

### Ce que le noyau y range déjà

| Classe | Disponible |
| --- | --- |
| `Wazi\View\Kioo` | si vous avez donné `views:` au noyau ; se règle avec `addFilter()` et `share()` |
| `Wazi\Http\Session` | si vous avez donné `sessions:` au noyau |
| `Wazi\Http\CsrfToken` | toujours |
| `Wazi\Http\CspNonce` | toujours |

### Les erreurs que vous rencontrerez

Le conteneur explique ce qui lui manque. Par exemple, si un constructeur demande un `string $chemin` sans recette, le message nomme la classe, l'argument, et montre la ligne `set()` à écrire. Une dépendance circulaire (A demande B, qui demande A) est détectée et décrite.

### Une règle de sécurité

Ne passez **jamais** à `$container->get()` un texte venu d'une requête : le visiteur choisirait quelle classe votre application fabrique. Les noms de classes s'écrivent dans votre code.

## Les méthodes d'un contrôleur

Une méthode de contrôleur reçoit la requête et les paramètres de la route, comme une fonction (voir [Les routes](02-routes.md)) :

```php
#[Get('/articles/{id:int}')]
public function voir(ServerRequestInterface $request, int $id): ResponseInterface
```

Les **services**, eux, ne se demandent pas ici mais dans le constructeur. C'est voulu : en lisant le constructeur, on voit d'un coup tout ce dont la classe dépend.

Une méthode de route doit être **publique** et retourner une **réponse**. Sinon, Wazi le dit au démarrage ou à l'appel, avec la correction à faire.

## Sans attributs

Les attributs sont une commodité. La même route s'écrit aussi :

```php
$app->router->get('/articles/{id:int}', [ArticleController::class, 'voir']);
```

Le contrôleur n'est fabriqué que si la route est appelée.

Suite : [Requêtes et réponses](04-requetes-et-reponses.md).
