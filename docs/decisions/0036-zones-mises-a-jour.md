# ADR-036 : les zones mises à jour

**Statut :** acceptée

## Contexte
L'utilisateur veut que Kioo permette des pages vivantes, « comme Vue ou React » : ajouter une note sans voir toute la page se recharger, filtrer une liste pendant qu'on la regarde. Le 2026-10-03, le choix s'est porté sur des pages produites par le serveur, dont un petit script remplace des morceaux. Le 2026-10-05, entre deux modèles, il a retenu le plus simple : **des zones mises à jour**, plutôt que des composants qui gardent un état (façon Livewire).

Ce qui compte pour Wazi : un débutant doit pouvoir suivre ce qui se passe, la page doit marcher sans JavaScript, et rien de nouveau ne doit pouvoir se retourner contre lui.

### Analyse de menaces
- **Un état confié au navigateur** : dans le modèle des composants avec état, les propriétés du composant voyagent dans la page et reviennent à chaque clic. Il faut les signer, et la moindre erreur laisse un visiteur les modifier (prix, identifiant, droits). Ici, **aucun état ne quitte le serveur**.
- **Des actions appelables par leur nom** : un composant avec état expose des méthodes que le navigateur demande par leur nom. Ici, il n'y a que les routes de l'application, avec leurs protections habituelles.
- **Une réponse qui change selon un en-tête** : si le serveur ne rendait qu'un morceau de page quand on le lui demande, un cache ou le bouton « Précédent » pourrait afficher ce morceau à la place de la page.
- **Du HTML reçu et inséré dans la page** : le script insère ce que le serveur a répondu. Si la réponse venait d'un autre site, ce serait une porte ouverte.
- **Un nom de zone fabriqué à partir d'une valeur** : un nom venu d'un visiteur pourrait désigner un morceau de page qu'on ne voulait pas remplacer.
- **Le jeton de protection des formulaires** : un envoi fait par le script doit rester protégé comme un envoi ordinaire.

## Options envisagées
- **Composants avec état** (Livewire) : proches de Vue ou React, mais beaucoup de mécanique cachée, un état à signer, des actions appelables depuis le navigateur. Écarté par l'utilisateur pour la 0.6.
- **Zones, le serveur ne rend que les zones demandées** : moins d'octets, mais la même adresse répond alors deux choses différentes selon un en-tête, et Kioo doit connaître la requête.
- **Zones, le serveur répond la page entière et le script n'en garde que les zones.** Retenu.

## Décision
1. **Deux attributs dans les templates**, traduits dès la lecture en attributs HTML ordinaires :
   - `k:zone="liste"` marque un morceau de page ; il devient `data-k-zone="liste"` ;
   - `k:update="liste, compteur"`, sur un `<form>` ou un `<a>`, dit quelles zones ce formulaire ou ce lien met à jour ; il devient `data-k-update="liste compteur"`.
2. **Le contrôleur ne change pas.** Il répond la même page qu'avant, entière. La page marche donc sans JavaScript, exactement comme avant.
3. **Le script `wazi.js`** (un fichier, sans dépendance, commenté) intercepte l'envoi d'un formulaire ou le clic sur un lien marqué, fait la même requête que le navigateur aurait faite, lit la page reçue, et remplace dans la page affichée les seules zones nommées.
4. **Le serveur répond toujours la page entière** : une adresse a une seule réponse. Pas d'en-tête particulier, rien à mettre en cache différemment, rien de nouveau dans le noyau, le routeur ou `Renderer`. Si une mesure montre un jour que c'est trop lourd, une nouvelle décision pourra l'optimiser.
5. **Quand le script ne peut pas faire proprement, il laisse faire le navigateur**, ou affiche la page reçue en entier :
   - adresse d'un autre site, clic avec une touche (nouvel onglet), attribut `target` ou `download` : il n'intervient pas ;
   - une zone demandée manque dans la page reçue (redirection vers la page de connexion, page d'erreur) : la page reçue s'affiche en entier ;
   - la réponse n'est pas du HTML, ou le réseau échoue : le navigateur fait l'envoi lui-même.
6. **Noms de zones : une liste fermée de caractères, écrits en dur.** Minuscules, chiffres et tirets, quarante caractères au plus. Jamais d'expression : `k:zone="{nom}"` est refusé, comme pour `file` et `name`.
7. **Refusés dès la lecture du template**, avec une explication :
   - `k:zone` sur une balise qui porte `k:for` (la même zone existerait plusieurs fois), sur `<html>`, `<head>`, `<body>`, sur une balise `<k:…>`, sur une balise jamais fermée ;
   - deux zones de même nom dans un même fichier ;
   - `k:update` ailleurs que sur `<form>` ou `<a>`, ou sans aucun nom.
8. **L'adresse suit.** Après une mise à jour par un lien, un formulaire `GET`, ou un envoi suivi d'une redirection, l'adresse de la page reçue s'affiche dans la barre du navigateur ; le bouton « Précédent » recharge la page.
9. **Pendant l'attente**, les zones concernées portent `aria-busy="true"` : une feuille de style peut les estomper. Après la mise à jour, le document reçoit l'événement `wazi:updated`.
10. **Le script est un fichier du projet**, `public/wazi.js`, installé par la commande **`wazi zones:install`** et appelé par une ligne écrite dans la mise en page : `<script src="/wazi.js" defer></script>`. Wazi n'ajoute aucune route et n'écrit rien dans vos pages de lui-même. La commande remplace le fichier seulement s'il porte encore la première ligne d'un `wazi.js` de Wazi (mise à jour après un changement de version) ; un autre fichier de ce nom n'est jamais touché. C'est une exception voulue à la règle des générateurs (ADR-028) : ce fichier appartient à Wazi, pas au projet.
11. **La barre de débogage suit** : quand la page reçue la contient, le script la remplace aussi, pour qu'elle décrive la dernière requête.

## Pourquoi
- **Transparence** : `k:zone` et `k:update` se lisent dans le template, et se retrouvent tels quels dans le code de la page (`data-k-zone`). Le script tient en un fichier lisible, dans le projet.
- **Complexité progressive** : on écrit d'abord la page ordinaire ; on ajoute deux attributs le jour où l'on veut éviter le rechargement. Rien n'est réécrit.
- **Sécurité** : aucun état chez le visiteur, aucune action nouvelle, aucune adresse nouvelle. Le script n'insère que du HTML venu du site lui-même, déjà échappé par Kioo, et un script contenu dans une zone reçue n'est pas exécuté par le navigateur. La politique de sécurité du contenu reste celle de la page : `wazi.js` vient du site, aucun script n'est écrit dans la page.
- **Une seule réponse par adresse** : pas de cache trompé, pas de morceau de page affiché seul après « Précédent ».
- **Le jeton de protection** voyage dans le formulaire, comme toujours : le script envoie les champs du formulaire, sans rien ajouter ni retirer.

## Conséquences
- Le serveur calcule et envoie la page entière même pour une petite zone. C'est le prix de la simplicité ; la page n'est pas redessinée, et ni les styles ni les scripts ne sont rechargés.
- Un script écrit dans une zone ne s'exécute pas à la mise à jour. Le code qui doit agir sur une zone remplacée écoute `wazi:updated`.
- Ce qui se trouve dans une zone est remplacé : un champ en cours de saisie y perd son contenu. On place le champ hors de la zone, ou on accepte que le formulaire revienne tel que le serveur l'a écrit (avec ses erreurs).
- Deux zones de même nom dans deux fichiers différents d'une même page ne sont pas détectées : le script prend la première.
- Pas d'état local, pas de liaison de données, pas de mise à jour en continu depuis le serveur : ce n'est pas Vue. Les composants avec état restent une question ouverte, à étudier dans une version à part si le besoin se confirme.
- Non vérifié par les tests automatiques : le script lui-même. Il est vérifié dans un vrai navigateur, sur la démonstration.
