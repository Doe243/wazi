# La documentation de Wazi

Wazi est un framework PHP pensé pour **comprendre** ce qu'on construit. Cette documentation suit le même principe : chaque page dit ce que fait une pièce, comment s'en servir, et pourquoi elle est faite ainsi.

> Wazi est en construction (version 0.5). Ce qui est décrit ici fonctionne et est testé, mais l'API peut encore changer avant la version 1.0.

## Le guide

À lire dans l'ordre la première fois : chaque page s'appuie sur les précédentes.

| | Page | Vous y apprenez |
| --- | --- | --- |
| 1 | [Prise en main](guide/01-prise-en-main.md) | Écrire une première page, ranger un projet, suivre le chemin d'une requête |
| 2 | [Les routes](guide/02-routes.md) | Relier une adresse à du code, avec des paramètres |
| 3 | [Contrôleurs et services](guide/03-controleurs-et-services.md) | Organiser le code en classes, laisser le conteneur les fabriquer |
| 4 | [Requêtes et réponses](guide/04-requetes-et-reponses.md) | Lire ce que le visiteur envoie, construire ce qu'on lui répond |
| 5 | [Les middlewares](guide/05-middlewares.md) | Placer une étape devant une route, ou devant toutes |
| 6 | [Les templates Kioo](guide/06-kioo.md) | Écrire des pages HTML qui affichent vos données |
| 7 | [La configuration](guide/07-configuration.md) | Ranger les réglages et les secrets hors du code |
| 8 | [Les sessions](guide/08-sessions.md) | Reconnaître un visiteur d'une page à l'autre |
| 9 | [Les formulaires](guide/09-formulaires.md) | Recevoir, vérifier et protéger un formulaire |
| 10 | [Les erreurs](guide/10-erreurs.md) | Comprendre ce que Wazi affiche et consigne quand quelque chose échoue |
| 11 | [La sécurité](guide/11-securite.md) | Ce que Wazi fait pour vous, et ce qui reste à votre charge |
| 12 | [Mettre en ligne](guide/12-deploiement.md) | Préparer un serveur et régler l'application pour la production |
| 13 | [La console](guide/13-console.md) | Lancer le site depuis le terminal, écrire ses propres commandes |
| 14 | [La base de données](guide/14-base-de-donnees.md) | Lire et écrire en SQL sans risque d'injection, construire la base par des migrations |
| 15 | [La barre de débogage](guide/15-barre-de-debogage.md) | Voir, en bas de la page, quelle route a répondu et en combien de temps |

## Les exemples

Trois applications à lancer et à lire, de la plus simple à la plus complète. Depuis le dossier du framework :

```bash
php -S localhost:8000 examples/bonjour.php          # un seul fichier, des routes écrites comme des fonctions
php -S localhost:8000 examples/carnet/index.php     # un contrôleur, un service, des pages en Kioo
cd examples/demo && php wazi serve                  # une application complète, rangée comme un vrai projet
```

La troisième, [la démonstration](../examples/demo/README.md), utilise tout ce que décrit ce guide.

## Pour aller plus loin

- [Le journal des modifications](../CHANGELOG.md) : ce qui change d'une version à l'autre, et ce que promet un numéro de version.
- [Les décisions d'architecture](decisions/) : pourquoi chaque pièce de Wazi est faite comme elle l'est. Une décision, un court document.
- [L'identité visuelle](brand/README.md) : logos, couleurs, typographie.
- Le code source lui-même : chaque classe de `src/` commence par un commentaire qui explique son rôle. Il est écrit pour être lu.

## Ce que Wazi ne fait pas encore

Pour ne pas chercher ce qui n'existe pas :

- **pas d'authentification toute faite** : le guide montre comment construire une connexion avec les sessions ;
- **le projet de départ** (`wazi/skeleton`) existe, mais ni lui ni le framework ne sont encore publiés sur Packagist : `composer create-project wazi/skeleton` ne fonctionne donc pas encore depuis Internet.
