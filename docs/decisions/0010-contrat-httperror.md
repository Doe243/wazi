# ADR-010 : le contrat HttpError relie les exceptions à leur réponse HTTP

**Statut :** acceptée

## Contexte
Certaines exceptions ne sont pas des pannes mais des réponses : page introuvable (404), méthode non permise (405), requête refusée (400, 413). Elles naissent dans `Routing` et dans `Http`. Le composant `Errors` devra les transformer en réponses, mais la règle des couches interdit à `Errors` de dépendre de `Routing` : deux composants de la couche Fonctionnalités ne se connaissent pas.

## Options envisagées
- Autoriser `Errors` à dépendre de `Routing` : casse la règle des couches, et il faudrait recommencer pour chaque nouveau composant.
- Faire attraper ces exceptions par le `Kernel`, classe par classe : le `Kernel` grossirait à chaque nouvelle exception.
- Un contrat commun dans `Contracts`.

## Décision
`Wazi\Contracts\HttpError` est une interface qui étend `\Throwable` et déclare `getStatusCode(): int` et `getResponseHeaders(): array`. `RequestRejectedException`, `RouteNotFoundException` et `MethodNotAllowedException` l'implémentent. `Errors` ne connaîtra que cette interface.

## Pourquoi
C'est l'usage prévu de la couche `Contracts` : quand deux composants doivent se rejoindre sans se connaître, ils se rejoignent sur une interface qui ne dépend de rien. Une méthode se suit d'un clic dans l'IDE (principe 1).

## Conséquences
`Contracts` reçoit sa première interface. Toute future exception qui correspond à une réponse HTTP (accès interdit, trop de requêtes...) l'implémentera. Le message d'une `HttpError` s'adresse au développeur ; ce que voit le visiteur reste décidé par `Errors` (ADR-006).
