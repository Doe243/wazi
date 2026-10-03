# 5. Les middlewares

Un middleware est **une étape placée autour de votre code**. Il voit passer la requête à l'aller et la réponse au retour, comme les couches d'un oignon :

```text
requête ─►  [ A  ─►  [ B  ─►  [ votre code ]  ─►  B ]  ─►  A ]  ─► réponse
```

On s'en sert pour ce qui concerne plusieurs routes à la fois : vérifier qu'un visiteur est connecté, ajouter un en-tête, mesurer un temps de réponse.

## Écrire un middleware

Un middleware est une classe qui implémente `MiddlewareInterface` (standard PSR-15). Elle reçoit la requête et « la suite » :

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Http\Response;
use Wazi\Http\Session;

final readonly class ConnexionRequise implements MiddlewareInterface
{
    public function __construct(private Session $session) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!is_string($this->session->get('utilisateur'))) {
            return new Response(303, ['Location' => '/connexion']);
        }

        return $handler->handle($request);
    }
}
```

Un middleware peut faire trois choses :

- **ne pas appeler la suite** et répondre lui-même, comme ci-dessus. Le contrôleur n'est alors même pas fabriqué ;
- **modifier la requête** avant d'appeler la suite : `$handler->handle($request->withAttribute('utilisateur', $nom))` ;
- **modifier la réponse** au retour :

```php
$response = $handler->handle($request);

return $response->withHeader('Cache-Control', 'no-store');
```

Comme tout service, un middleware demande ce dont il a besoin dans son constructeur.

## Devant une route

```php
$app->router->get('/notes', [NoteController::class, 'liste'], [ConnexionRequise::class]);

#[Get('/notes', [ConnexionRequise::class])]
public function liste(): ResponseInterface { /* ... */ }
```

On donne le **nom de la classe** : le conteneur la fabrique seulement si la route est appelée. On peut aussi donner un objet déjà construit.

Avec plusieurs middlewares, le premier de la liste est le plus à l'extérieur : il agit en premier sur la requête, et en dernier sur la réponse.

## Devant toutes les routes

```php
$app = new Kernel(middlewares: [MesureDuTemps::class]);
```

Ces middlewares s'exécutent **avant le routeur** : ils voient passer toutes les requêtes, y compris celles qui ne correspondent à aucune route.

Une précision : une page d'erreur (404, 500...) naît d'une exception, et elle est fabriquée **à l'extérieur** des middlewares. Un middleware qui modifie la réponse au retour ne verra donc pas passer une page d'erreur : l'exception le traverse. Pour agir quand même, entourez l'appel d'un `try` / `finally`.

## Ceux que Wazi place lui-même

Dans cet ordre, du plus extérieur au plus intérieur :

| Middleware | Rôle | Présent |
| --- | --- | --- |
| `SecurityHeaders` | Ajoute les en-têtes de sécurité à chaque réponse | toujours, sauf `securityHeaders: null` |
| `CsrfCookie` | Transporte le jeton des formulaires dans un cookie | toujours |
| `SessionMiddleware` | Lit et enregistre la session | si vous avez donné `sessions:` |
| *les vôtres* | | |
| *le routeur* | | |
| `CsrfProtection` | Refuse un formulaire sans le bon jeton | toujours, sur chaque route |
| *ceux de la route* | | |

### Les en-têtes de sécurité

`SecurityHeaders` ajoute à chaque réponse quatre en-têtes qui demandent au navigateur de protéger vos visiteurs. Le plus important est la **politique de sécurité du contenu** (CSP) : la liste de ce que la page a le droit de charger.

Par défaut, elle n'est stricte que sur ce qui est dangereux, le JavaScript :

- feuilles de style, polices, images, vidéos, cadres : autorisés depuis votre site et depuis tout site en `https` ;
- **scripts** : uniquement les fichiers `.js` de votre propre site, et les balises `<script>` écrites dans vos templates Kioo.

Pour charger le JavaScript d'un autre site :

```php
use Wazi\Middleware\SecurityHeaders;

$app = new Kernel(securityHeaders: new SecurityHeaders(scripts: ['https://cdn.jsdelivr.net']));
```

Restent refusés : les attributs d'événement (`onclick="..."`), et un `<script>` écrit dans une chaîne PHP sans passer par un template. Si un script ne s'exécute pas, la console du navigateur (touche F12) dit ce qui a été bloqué.

Un en-tête déjà posé par votre contrôleur n'est jamais remplacé : c'est la façon de faire une exception pour une seule page.

Pour écrire toute la politique vous-même : `new SecurityHeaders(contentSecurityPolicy: "default-src 'self'")`.

Suite : [Les templates Kioo](06-kioo.md).
