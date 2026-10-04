# 8. Les sessions

Le web n'a pas de mémoire : chaque requête arrive seule. Pour reconnaître un visiteur d'une page à l'autre, on lui donne un **identifiant** tiré au hasard, rangé dans un cookie. Il le renvoie à chaque requête, et le serveur retrouve ce qu'il avait noté pour lui : c'est la **session**.

## Activer les sessions

Indiquez au noyau où les ranger, **hors du dossier public** :

```php
$app = new Kernel(sessions: __DIR__ . '/../var/sessions');
```

Le dossier est créé s'il n'existe pas. Un dossier placé dans `public/` est refusé : les sessions y seraient téléchargeables.

## Noter et relire

Demandez la session dans le constructeur :

```php
use Wazi\Http\Session;

final class PanierController
{
    public function __construct(private readonly Session $session) {}

    #[Post('/panier/{id:int}')]
    public function ajouter(int $id): ResponseInterface
    {
        $panier = (array) $this->session->get('panier', []);
        $panier[] = $id;
        $this->session->set('panier', $panier);

        return new Response(303, ['Location' => '/panier']);
    }
}
```

| Méthode | Rôle |
| --- | --- |
| `get('cle', $defaut)` | Relire une valeur |
| `has('cle')` | Savoir si elle existe |
| `set('cle', $valeur)` | Noter une valeur |
| `remove('cle')` | L'oublier |
| `clear()` | Tout oublier : c'est la déconnexion |

### Ce qu'une session peut garder

Des valeurs **simples** : textes, nombres, vrai/faux, `null`, et des tableaux qui n'en contiennent pas d'autres. Pour retenir un objet, gardez son identifiant :

```php
$this->session->set('utilisateur_id', $utilisateur->id);
```

Les clés qui commencent par `_` sont réservées à Wazi.

Ce que vous relisez d'une session est de type inconnu pour PHP : vérifiez-le (`is_string()`, `is_int()`) avant de vous en servir.

### Lire ne crée rien

Un visiteur qui ne fait que lire le site ne reçoit **aucun cookie** et ne crée **aucun fichier**. La session ne commence à exister que lorsque votre code y note quelque chose.

Gardez cela en tête : chaque écriture pour un visiteur inconnu crée un fichier. N'écrivez pas dans la session d'un visiteur anonyme sans raison, surtout sur une page qu'un robot peut appeler mille fois.

## Connecter un visiteur

Wazi ne fournit pas encore d'authentification toute faite. Une connexion se construit ainsi :

```php
#[Post('/connexion')]
public function connecter(ServerRequestInterface $request): ResponseInterface
{
    $v = new Validator($request->getParsedBody());
    $nom = $v->text('nom', max: 80);
    // min: 1 : à la connexion, on compare le mot de passe, on ne juge pas sa solidité.
    $motDePasse = $v->password('mot_de_passe', min: 1);

    if ($v->fails() || !$this->comptes->verifier($nom, $motDePasse)) {
        return $this->kioo->page('connexion', ['erreur' => 'Nom ou mot de passe incorrect.'], 422);
    }

    $this->session->regenerate();
    $this->session->set('utilisateur', $nom);

    return new Response(303, ['Location' => '/']);
}
```

Trois points de sécurité, tous dans ces quelques lignes :

- **`regenerate()` juste après une connexion réussie.** Il change l'identifiant de la session en gardant son contenu. Si quelqu'un avait réussi à imposer son identifiant au navigateur du visiteur avant qu'il se connecte, cet identifiant ne vaut plus rien.
- **Le message ne dit pas** si c'est le nom ou le mot de passe qui est faux : ce serait révéler quels comptes existent.
- **Les mots de passe ne sont jamais gardés tels quels.** On garde leur empreinte, calculée par `password_hash()`, et on compare avec `password_verify()`.

Pour protéger des pages, un middleware vérifie la session : voir [Les middlewares](05-middlewares.md). La démonstration montre l'ensemble dans [`ConnexionController.php`](../../examples/demo/src/ConnexionController.php).

Se déconnecter :

```php
#[Post('/deconnexion')]
public function deconnecter(): ResponseInterface
{
    $this->session->clear();

    return new Response(303, ['Location' => '/']);
}
```

La déconnexion est un formulaire `POST`, pas un lien : elle change quelque chose.

## Un message pour la page suivante

Après un formulaire réussi, on redirige le visiteur. Le message « Note ajoutée » doit survivre à la redirection, puis disparaître :

```php
$this->session->flash('succes', 'Note ajoutée.');

return new Response(303, ['Location' => '/notes']);
```

Sur la page suivante :

```php
$message = $this->session->takeFlash('succes');   // « Note ajoutée. », puis null aux lectures suivantes
```

`takeFlash()` rend le message **et l'efface** : il ne s'affiche qu'une fois. Tant qu'aucune page ne le lit, il attend.

## « Se souvenir de moi »

Par défaut, une session disparaît quand le navigateur se ferme, ou après deux heures sans visite. Pour qu'elle dure :

```php
if (($formulaire['se_souvenir'] ?? null) === '1') {
    $this->session->remember(30);   // 30 jours après la dernière visite
}
```

La durée va de 1 à 365 jours. Chaque visite repousse l'échéance ; `clear()` y met fin.

N'appelez `remember()` que si le visiteur l'a demandé, par une case à cocher : une session longue reste ouverte sur l'appareil où elle a été créée, y compris un ordinateur partagé. Avant une action sensible (changer de mot de passe, payer), redemandez le mot de passe.

## Deux requêtes en même temps

Un visiteur peut envoyer deux requêtes à la fois : deux onglets, une page qui envoie des requêtes JavaScript. Wazi les traite **l'une après l'autre** : la seconde attend que la première ait enregistré la session. Sans cela, la dernière effacerait ce que l'autre a noté.

Conséquence : une page lente fait attendre les autres requêtes du même visiteur. Si l'attente dépasse dix secondes, une erreur l'explique.

## Ce que Wazi fait pour vous

- L'identifiant fait 256 bits de hasard : impossible à deviner.
- Le cookie est `HttpOnly` (le JavaScript de la page ne peut pas le lire), `SameSite=Lax` (il n'accompagne pas un formulaire envoyé depuis un autre site) et `Secure` quand le site est en HTTPS.
- Un identifiant que le serveur n'a pas créé n'est jamais adopté.
- Le contenu est écrit en JSON : aucun objet n'est jamais reconstruit à partir d'un fichier de session.
- Les fichiers ne sont lisibles que par le compte qui fait tourner PHP ; les sessions expirées sont supprimées.

## Les limites

- Les sessions sont rangées dans des **fichiers**, sur un seul serveur. Un site réparti sur plusieurs serveurs aura besoin d'un autre rangement (prévu avec la base de données).
- Le nombre d'essais de connexion n'est pas limité par Wazi : c'est à votre application de ralentir quelqu'un qui essaie des milliers de mots de passe.

Suite : [Les formulaires](09-formulaires.md).
