# ADR-037 : l'aide pour une application installable (PWA)

**Statut :** acceptée

## Contexte
L'utilisateur veut que Wazi permette des applications installables (« PWA ») : un site que l'on ajoute à l'écran d'accueil d'un téléphone ou au bureau d'un ordinateur, qui s'ouvre dans sa propre fenêtre, et qui répond quelque chose quand le réseau manque.

Trois pièces font une application installable : un **manifeste** (son nom, ses couleurs, son icône), un **service worker** (un script que le navigateur garde et place entre la page et le réseau), et une connexion HTTPS.

Le service worker est la pièce délicate. Il intercepte toutes les requêtes du site, et reste installé chez le visiteur après sa visite. Mal écrit, il fait plus de mal que de bien.

### Analyse de menaces
- **Une page personnelle gardée en cache** : un service worker qui garde les pages servirait, sur un appareil partagé, les notes ou le compte du visiteur précédent, même après sa déconnexion.
- **Une page périmée servie pour toujours** : un cache « d'abord » continue d'afficher l'ancienne version du site après une mise en ligne. C'est le piège classique : le développeur modifie un fichier, et rien ne change.
- **Un formulaire rejoué** : un service worker qui retient les envois pour les refaire plus tard enverrait deux fois une commande, ou l'enverrait avec un jeton de protection périmé.
- **Un service worker impossible à retirer** : une fois installé, il ne disparaît pas en supprimant le fichier. Il faut prévoir la sortie.
- **Un script étranger** : un service worker chargé depuis un autre site, ou qui en importe un, donnerait à ce site le contrôle de toutes les pages.
- **Le nom de l'application** : il est tapé dans la console et écrit dans des fichiers.

## Options envisagées
- **Ne rien fournir**, et documenter comment écrire son manifeste et son service worker. Le débutant copierait alors un exemple trouvé ailleurs, souvent trop ambitieux.
- **Un composant** qui fabrique le manifeste et le service worker à chaque requête, avec ses routes. Plus souple, mais ce sont des routes cachées, et du code à protéger.
- **Un générateur** qui écrit des fichiers ordinaires dans `public/`, commentés, une fois. Retenu.

## Décision
1. **Une commande, `wazi make:pwa [nom]`**, crée cinq fichiers dans `public/` : `manifest.webmanifest`, `service-worker.js`, `pwa.js` (qui l'enregistre), `hors-ligne.html` et `icone.svg`. Ce sont des fichiers du projet, que le développeur lit et modifie. Wazi n'ajoute aucune route et ne fabrique rien pendant une requête.
2. **Les règles des générateurs s'appliquent** (ADR-028) : aucun fichier existant n'est remplacé, tout est vérifié avant la première écriture, la mise en page n'est pas modifiée. La commande affiche les trois lignes à y ajouter.
3. **Le service worker ne garde aucune page.** Une page est toujours demandée au réseau. Si le réseau manque, il affiche `hors-ligne.html`, une page fixe qui ne contient rien de personnel.
4. **Il ne garde que les fichiers d'une liste écrite en dur** dans le script (`FICHIERS`), que le développeur complète s'il le veut. Pour eux aussi, **le réseau passe d'abord** : la copie gardée ne sert que hors ligne. Une mise en ligne n'est donc jamais masquée par un cache.
5. **Il ne touche qu'aux lectures de son propre site** : requêtes `GET`, même origine. Tout le reste (envoi de formulaire, autre site) passe sans qu'il intervienne. Rien n'est retenu pour être rejoué.
6. **Il n'importe aucun script** et n'est servi que par le site lui-même. La politique de sécurité du contenu de Wazi (ADR-014) le permet déjà : les scripts du site sont autorisés, les autres non.
7. **La sortie est prévue et documentée** : pour retirer un service worker chez les visiteurs, on remplace son contenu par quelques lignes qui le désinstallent ; le guide les donne.
8. **Le nom de l'application** est vérifié (soixante caractères au plus, sans retour à la ligne ni caractère de contrôle), écrit dans le manifeste par `json_encode()` et échappé dans la page hors ligne. Sans nom, c'est celui du dossier du projet qui sert, s'il convient.
9. **L'icône créée est un dessin provisoire** (la première lettre du nom sur un fond de couleur), en SVG. Le guide dit de la remplacer, et d'ajouter des icônes PNG de 192 et 512 pixels pour les appareils qui n'acceptent pas le SVG.

## Pourquoi
- **Transparence** : cinq fichiers lisibles, dans le projet, plutôt qu'un mécanisme. Le service worker tient en une page et dit ce qu'il fait.
- **Sécurité** : ce qui n'est pas gardé ne peut ni fuiter ni se périmer. Le choix « réseau d'abord » renonce à un gain de vitesse pour éviter les deux pannes les plus courantes.
- **Complexité progressive** : le site fonctionne sans ; on lance la commande le jour où l'on veut une application installable ; on complète la liste des fichiers le jour où l'on veut une page hors ligne plus riche.

## Conséquences
- Le site ne va pas plus vite avec ce service worker, et ses pages ne se lisent pas hors ligne : il devient installable, et il a une réponse propre quand le réseau manque. Une consultation hors ligne des pages demanderait de décider lesquelles sont publiques : c'est une autre décision.
- Pas de notifications, pas de synchronisation en arrière-plan.
- Un service worker ne s'installe qu'en HTTPS, ou sur `localhost`.
- Sur un ordinateur de développement, le service worker d'un projet reste associé à `localhost:8000` : en lançant un autre projet sur le même port, serveur arrêté, c'est sa page hors ligne qui s'affiche. Le guide dit comment le retirer dans le navigateur.
- Les fichiers créés ne sont pas mis à jour par Wazi : ils appartiennent au projet.
