# ADR-011 : le noyau, le mode développement et les erreurs de PHP

**Statut :** acceptée

## Contexte
Le `Kernel` assemble les composants : il crée la requête, la confie au routeur, transforme une exception en page d'erreur et envoie la réponse. Trois points demandaient une décision : comment une application passe en mode développement (ADR-006 : le mode production est le défaut), que faire des erreurs que PHP traite lui-même (avertissements, erreurs fatales), et comment l'utilisateur déclare ses routes.

## Options envisagées

**Mode développement**
- Le déduire de l'environnement (variable `APP_ENV`, adresse `localhost`) : pratique, mais une variable oubliée ou un proxy mal réglé suffit à l'activer en production.
- Un argument explicite du constructeur, faux par défaut.

**Erreurs de PHP**
- Laisser le `php.ini` décider : avec `display_errors` activé (réglage de développement courant), PHP écrit ses avertissements dans la page, avec le chemin des fichiers du serveur, hors du contrôle d'`ErrorHandler`.
- Que `run()` les prenne en main.

**Déclaration des routes**
- Des méthodes `get()`, `post()`... sur le `Kernel`, qui délèguent au routeur : plus court à écrire, mais cinq méthodes en double et un intermédiaire à traverser.
- Le routeur exposé tel quel : `$app->router->get(...)`.

## Décision
1. Le mode développement se demande par `new Kernel(development: true)`. Il n'est jamais déduit.
2. `run()` désactive `display_errors`, active `log_errors`, transforme les avertissements et notices de PHP en `ErrorException`, et installe une fonction de fin de script qui envoie une page 500 après une erreur fatale. Les dépréciations vont au journal sans interrompre la page ; une erreur tue par `@` reste tue.
3. Ces réglages globaux ne sont posés que par `run()`. `handle()` ne lit et ne modifie rien de global : c'est la méthode à utiliser dans un test.
4. Le routeur est une propriété publique en lecture seule : `$app->router`.
5. Si quelque chose a été affiché avant la réponse et que rien n'est encore parti, cet affichage est jeté et remplacé par la page d'erreur qui explique le problème.
6. L'en-tête `X-Powered-By`, qui annonce la version de PHP, est retiré de chaque réponse.

## Pourquoi
- Un mode dangereux doit demander un geste explicite et local (principe 8). Le mot `development: true` se voit dans le fichier d'entrée et se cherche dans un dépôt.
- Aucune erreur ne doit atteindre le navigateur sans passer par `ErrorHandler`, seul juge de ce que voit le visiteur (ADR-006). Un avertissement transformé en exception est aussi une erreur que le débutant ne peut plus ignorer.
- `$app->router->get(...)` montre que les routes appartiennent au routeur : un clic y mène (principe 1).

## Conséquences
- Rien n'empêche d'écrire `development: true` sur un serveur en ligne. L'exposition reste bornée par l'ADR-006 (message, fichier et ligne ; jamais de trace ni de valeur). Un garde-fou lié à la configuration pourra s'ajouter avec `Config` (0.3).
- Du code qui comptait sur des avertissements ignorés échouera en erreur 500 : c'est voulu.
- Une application lancée par `run()` modifie des réglages de PHP pour toute la durée du script.
- Les en-têtes de sécurité des réponses ordinaires (hors pages d'erreur) relèveront d'un middleware, en 0.2.
