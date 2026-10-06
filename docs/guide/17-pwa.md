# 17. Une application installable

Un site peut s'installer comme une application : sur un téléphone, il prend place sur l'écran d'accueil ; sur un ordinateur, il s'ouvre dans sa propre fenêtre. On appelle cela une **PWA**. Il n'y a rien à publier dans une boutique d'applications : c'est toujours votre site.

Il lui faut trois choses :

- un **manifeste** : une fiche qui donne son nom, ses couleurs et son icône ;
- un **service worker** : un script que le navigateur garde après la visite, et qui répond quand le réseau manque ;
- une connexion **HTTPS** (ou `localhost`, pendant que vous développez).

## Créer les fichiers

```bash
wazi make:pwa "Mon carnet"
```

```text
OK  Créé : public/manifest.webmanifest
OK  Créé : public/service-worker.js
OK  Créé : public/pwa.js
OK  Créé : public/hors-ligne.html
OK  Créé : public/icone.svg

Il reste trois lignes à ajouter à votre mise en page, avant </head> :

    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0B6E70">
    <script src="/pwa.js" defer></script>

Puis rechargez le site : votre navigateur propose de l'installer.
```

Ajoutez ces trois lignes à `views/base.kioo`. C'est tout.

La commande se déclare dans le fichier `wazi` de votre projet, comme les autres :

```php
$console->add(new MakePwaCommand(__DIR__));
```

Comme tous les générateurs de Wazi, elle ne remplace jamais un fichier existant, et ne modifie pas votre mise en page. Sans nom, elle prend celui du dossier du projet. Avec `--no-comments`, les scripts sont créés sans leurs explications.

## Ce que fait chaque fichier

| Fichier | Rôle |
| --- | --- |
| `manifest.webmanifest` | La fiche de l'application : nom, nom court, couleurs, icône, page d'ouverture |
| `service-worker.js` | Affiche `hors-ligne.html` quand le réseau manque |
| `pwa.js` | Demande au navigateur d'enregistrer le service worker |
| `hors-ligne.html` | La page affichée sans réseau |
| `icone.svg` | Une icône provisoire : la première lettre du nom, sur un fond de couleur |

Ces fichiers sont **à vous**. Ouvrez-les : le service worker est commenté ligne par ligne.

## Vérifier dans le navigateur

Lancez le site (`wazi serve`), ouvrez-le, puis les outils du navigateur (touche F12), onglet **Application** :

- **Manifest** montre le nom, les couleurs et l'icône, et signale ce qui manque ;
- **Service workers** montre le script, et son état : « activated and is running » ;
- pour voir la page hors ligne, arrêtez le serveur (`Ctrl+C`), puis rechargez la page.

Quand tout est en place, le navigateur propose l'installation : dans Chrome et Edge, par une icône à droite de la barre d'adresse.

## Ce que fait le service worker, et ce qu'il ne fait pas

Il est prudent, exprès. Un service worker reste chez vos visiteurs après leur visite : une erreur y dure longtemps.

| Requête | Ce qu'il fait |
| --- | --- |
| Une page de votre site | Il la demande au réseau. Sans réseau : `hors-ligne.html` |
| Un fichier de sa liste `FICHIERS` | Il le demande au réseau, et garde la copie. Sans réseau : la copie |
| Un envoi de formulaire | Rien : le navigateur fait comme d'habitude |
| Un fichier d'un autre site | Rien |
| Vos styles, vos scripts, vos images | Rien, tant qu'ils ne sont pas dans la liste |

Trois choix à connaître :

- **Aucune page n'est gardée.** Une page gardée serait montrée au visiteur suivant sur le même appareil, même après une déconnexion. Vos pages ne se lisent donc pas hors ligne.
- **Le réseau passe toujours d'abord.** Vous modifiez un fichier, vous rechargez : c'est à jour. Une mise en ligne n'est jamais masquée par une vieille copie.
- **Rien n'est rejoué.** Un formulaire envoyé sans réseau échoue, comme sans service worker. Il n'est pas retenu pour plus tard.

Le site ne va donc pas plus vite : il devient installable, et il répond proprement quand le réseau manque.

## Une page hors ligne plus riche

`hors-ligne.html` ne charge aucun autre fichier : ils ne seraient pas disponibles. Pour lui donner votre feuille de styles ou votre logo, ajoutez-les à la liste du service worker, et changez le numéro de version :

```js
const VERSION = 'v2';

const FICHIERS = [HORS_LIGNE, '/icone.svg', '/app.css', '/logo.svg'];
```

Le changement de `VERSION` dit au navigateur de refaire sa réserve. N'y mettez que des fichiers **fixes et publics** : jamais une page, jamais une adresse qui dépend du visiteur.

## L'icône

L'icône créée est provisoire. Remplacez `public/icone.svg` par la vôtre, carrée.

Tous les appareils n'acceptent pas une icône SVG. Pour une application destinée aux téléphones, ajoutez deux images PNG, de 192 et de 512 pixels de côté, et déclarez-les dans le manifeste :

```text
"icons": [
    { "src": "/icone.svg", "sizes": "any", "type": "image/svg+xml", "purpose": "any" },
    { "src": "/icone-192.png", "sizes": "192x192", "type": "image/png" },
    { "src": "/icone-512.png", "sizes": "512x512", "type": "image/png" }
]
```

## Mettre en ligne

- Le site doit être en **HTTPS** : sans lui, le navigateur refuse d'enregistrer un service worker. Voir [Mettre en ligne](12-deploiement.md).
- Les cinq fichiers sont dans `public/` : ils partent avec le reste du site.
- Après une modification de `service-worker.js`, le navigateur de chaque visiteur prend la nouvelle version à sa visite suivante.

## Retirer le service worker

Supprimer le fichier ne suffit pas : le navigateur des visiteurs garde le script qu'il a déjà reçu. Pour le retirer partout, **remplacez le contenu** de `public/service-worker.js` par ces lignes, et laissez-les en ligne quelques semaines :

```js
self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((noms) => Promise.all(noms.map((nom) => caches.delete(nom))))
            .then(() => self.registration.unregister()),
    );
});
```

À sa visite suivante, chaque navigateur reçoit ce script, vide ses réserves et se désinscrit. Retirez aussi la ligne `<script src="/pwa.js" defer></script>` de votre mise en page, sinon il serait réenregistré.

Sur votre propre ordinateur, c'est plus rapide : outils du navigateur, onglet **Application**, **Service workers**, bouton **Unregister**.

## À savoir pendant que vous développez

Un service worker est attaché à une adresse, port compris. Si vous lancez un autre projet sur `localhost:8000`, le navigateur a encore celui du projet précédent : serveur arrêté, c'est **sa** page hors ligne qui s'affiche. Retirez-le par le bouton **Unregister**, ou lancez chaque projet sur son port : `wazi serve --port=8001`.

## Avec les zones mises à jour

Les deux s'entendent sans réglage. Une [zone mise à jour](16-zones.md) demande une page par une requête ordinaire, que le service worker laisse passer. Sans réseau, le script des zones laisse le navigateur faire, comme pour n'importe quelle page.

## Les limites

- Les pages ne se consultent pas hors ligne, et rien n'est envoyé plus tard quand le réseau revient.
- Pas de notifications.
- Les fichiers créés ne sont pas mis à jour par Wazi : ils appartiennent à votre projet. La décision complète est dans [l'ADR-037](../decisions/0037-aide-pwa.md).

Retour au [sommaire](../README.md).
