# ADR-030 : les templates préparés au déploiement

**Statut :** acceptée — remplace le point 11 de l'ADR-019 (« aucun fichier PHP n'est généré »)

## Contexte
L'ADR-025 a mesuré que l'analyse des templates, refaite à chaque requête, est le coût principal de Kioo : environ 5 ms par page sur la machine de développement, contre 0,1 à 2,5 ms pour l'affichage. Un cache de données en JSON a été essayé et écarté (gain d'un tiers).

La voie efficace est de garder le résultat de l'analyse dans un fichier PHP, que le serveur conserve en mémoire (OPcache). L'ADR-019 l'avait interdit : un fichier PHP dans un dossier où le serveur web peut écrire est une porte. Si une faille de l'application permet à un visiteur d'écrire un fichier sur le serveur, il y dépose du code, et ce code s'exécute.

L'utilisateur a choisi le 2026-10-04 : préparer les templates **au déploiement**, par une commande, dans un dossier que le serveur web ne peut pas modifier.

## Options envisagées
- **Traduire chaque template en code PHP qui écrit la page** (comme Twig ou Blade) : le plus rapide, car l'affichage aussi est accéléré. Mais il faut réécrire en PHP généré tout ce que fait le `Renderer` (échappement selon l'endroit, règles strictes des expressions, jetons). Deux façons de produire une page, c'est deux endroits où une faille d'échappement peut se glisser, et un risque qu'elles divergent.
- **Écrire dans un fichier PHP l'arbre déjà analysé**, et garder un seul code d'affichage.

## Décision
1. **`wazi views:compile`** analyse tous les templates du dossier des vues, et écrit pour chacun un fichier PHP qui reconstruit son arbre : `return ['stamp' => …, 'nodes' => [new StaticElement(…), new Element(…), …]];`.
2. **L'affichage ne change pas** : le même `Renderer` parcourt l'arbre. Préparé ou non, un template donne exactement la même page ; des tests le vérifient.
3. **Rien n'est jamais écrit pendant une requête.** Seule la commande écrit, lancée par le développeur.
4. **À la lecture, le dossier est refusé si PHP peut y écrire** (`is_writable`), ou s'il se trouve dans le dossier public. Les templates sont alors analysés comme d'habitude. Pour accepter un dossier inscriptible, il faut l'écrire : `unsafeAllowWritableCompiledViews: true`.
5. **Une page n'est jamais périmée** : chaque fichier préparé note la date et la taille de son template. S'ils ont changé, le fichier est ignoré et le template est analysé.
6. **Le code écrit ne contient que des `new` de seize classes connues et des valeurs écrites par `var_export()`.** Aucun texte de template ne devient du code.
7. Le nom d'un fichier préparé est une empreinte du chemin du template. Le ménage ne supprime que des fichiers de cette forme.
8. Réglage : `new Kernel(views: …, compiledViews: __DIR__ . '/build/views')`. Sans lui, rien ne change.

## Pourquoi
- Un seul code d'affichage garde l'échappement à un seul endroit, déjà testé : la sécurité de Kioo ne dépend pas de la préparation.
- Refuser un dossier inscriptible transforme une consigne de déploiement (« ne rendez pas ce dossier inscriptible ») en garantie vérifiée à chaque requête. Le pire qui arrive à qui l'oublie est un site plus lent, pas un site ouvert.
- La préparation vérifie tous les templates d'un coup : une faute arrête le déploiement au lieu d'apparaître devant un visiteur.

## Mesures
Démonstration, PHP 8.5 avec OPcache, machine de développement (Windows). Requête complète : chargement des templates, puis affichage.

| Page | Analysé à chaque requête | Préparé à l'avance |
| --- | --- | --- |
| Accueil | 4,8 ms | 0,9 ms |
| Carnet, 30 notes | 8,6 ms | 3,5 ms |

Sur le carnet, ce qui reste est l'affichage lui-même (2,5 ms), que cette décision ne change pas.

## Conséquences
- **Le dossier des templates préparés n'est pas `var/`.** `var/` doit être inscriptible par PHP (sessions) ; celui-ci ne doit pas l'être. Le projet de départ utilise `build/views`.
- Sur un poste de développement, et sur un hébergement où PHP tourne sous le compte qui dépose les fichiers, le dossier est inscriptible : les templates préparés ne servent pas, sauf geste explicite. C'est voulu.
- Un fichier écrit depuis moins de deux secondes n'est pas gardé en mémoire par OPcache (`opcache.file_update_protection`) : le gain n'apparaît qu'après. Si le serveur ne revérifie pas les fichiers (`opcache.validate_timestamps=0`), il faut vider OPcache après la commande, comme pour tout déploiement de code.
- Qui peut écrire dans le dossier des templates préparés peut exécuter du code : il se protège comme le code lui-même.
- L'affichage reste interprété. Le traduire en PHP reste possible plus tard, sur cette base, si une mesure le justifie.
