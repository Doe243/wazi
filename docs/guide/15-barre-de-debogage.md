# 15. La barre de débogage

Pendant que vous développez, Wazi ajoute une barre en bas de vos pages. Elle répond à la question que tout débutant se pose devant une page : **qu'est-ce qui vient de se passer ?**

```text
 W │ REQUÊTE GET /notes/3 · 200 · 12 ms │ ROUTE NoteController::voir │ MIDDLEWARES 3 │ TEMPLATES 1 · 7,0 ms │ SESSION 1 clé(s) │ WAZI PHP 8.5
```

Cliquez sur une rubrique : elle se déplie au-dessus de la barre.

## Ce qu'elle montre

| Rubrique | Ce que vous y lisez |
| --- | --- |
| **Requête** | La méthode, le chemin, le code de la réponse, la durée, la mémoire ; le **nom** des champs reçus |
| **Route** | Le motif de la route choisie, le code exécuté, ses paramètres, son rang, ses middlewares ; les routes qu'elle masque |
| **Middlewares** | Ceux que traverse chaque requête, dans l'ordre |
| **Templates** | Les templates écrits, et le temps de chacun |
| **Session** | Le **nom** des clés de la session |
| **Wazi** | Les versions de PHP et de Wazi |

Une réponse en erreur (404, 422...) est mise en avant.

C'est la même information que `wazi explain`, mais pour la requête qui vient d'avoir lieu, sous vos yeux.

## L'activer

Il n'y a rien à faire : la barre apparaît dès que le noyau est en mode développement.

```php
$app = new Kernel(development: $config->bool('APP_DEBUG', false));
```

Pour développer sans elle :

```php
$app = new Kernel(development: true, debugBar: false);
```

## Quand elle n'apparaît pas

La barre n'est écrite que si **toutes** ces conditions sont réunies :

1. le noyau est en **mode développement** ;
2. la requête vient de **votre ordinateur** (`127.0.0.1` ou `::1`) ;
3. la requête n'est **pas passée par un proxy** ;
4. le site est ouvert sous un **nom local** : `localhost`, `quelquechose.localhost`, `127.0.0.1`.

Et elle ne s'ajoute qu'à une **page HTML complète**. Une réponse JSON, une redirection, un fichier ou une page d'erreur n'en ont pas.

Si vous développez dans un conteneur, derrière un tunnel ou sur une machine distante, la barre ne s'affichera donc pas. C'est voulu : dans ces cas, tapez `wazi explain` dans le terminal.

## Pourquoi tant de précautions

Une barre de débogage montrée à un visiteur lui livre le plan du site : ses routes, ses fichiers, ce qu'il garde en session. D'autres frameworks l'ont appris à leurs dépens, et c'est pourquoi Wazi l'a longtemps refusée.

Elle existe aujourd'hui avec ces garde-fous :

- **Elle n'a pas d'adresse.** Elle est écrite dans la page que vous receviez de toute façon. Il n'y a ni page de profileur, ni historique des requêtes à protéger.
- **Elle ne fait que montrer.** Aucun bouton n'agit sur votre application, rien ne s'exécute à la demande. Elle n'a même pas de JavaScript.
- **Elle se méfie des proxies.** Derrière un proxy installé sur la même machine, toutes les requêtes semblent venir de `127.0.0.1` : c'est la fuite classique des outils « réservés à localhost ». Au moindre en-tête de proxy, la barre s'efface.
- **Elle ne collecte aucune valeur sensible.** D'un formulaire ou d'une session, elle ne lit que le **nom** des champs et des clés. Jamais une valeur, un cookie, un en-tête, une variable d'environnement ou un réglage. Ce qui n'est pas collecté ne peut pas fuiter.
- **En production, son code n'est pas chargé.** Le mode production est le défaut, et il ne crée aucun objet de la barre.

Malgré tout cela, la règle de base ne change pas : **un site en ligne ne tourne jamais en mode développement.**

## Les limites

- Une requête envoyée par JavaScript, ou suivie d'une redirection, n'a pas de barre : il n'y a pas d'historique pour la retrouver.
- Les requêtes SQL n'y figurent pas encore.
- Si votre application réécrit la politique de sécurité de contenu pour interdire les styles écrits dans la page, la barre s'affiche sans mise en forme.

Retour au [sommaire](../README.md).
