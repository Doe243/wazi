# ADR-020 : Kioo dans le noyau, et un jeton pour les scripts des templates

**Statut :** acceptée — complète les ADR-014 et ADR-019

## Contexte
Kioo sait produire une page, mais rien ne le relie encore à une application : un contrôleur doit pouvoir retourner une page en une ligne. Par ailleurs, l'ADR-014 bloque tout script écrit dans une page, y compris ceux du développeur, et renvoyait la solution à ce moment-ci. Enfin, l'ADR-019 annonçait un moyen de passer une valeur à du JavaScript.

## Options envisagées

**Scripts du développeur**
- Autoriser tous les scripts écrits dans la page (`'unsafe-inline'`) : la politique ne protège plus contre le XSS.
- Demander de mettre tout script dans un fichier `.js` : sûr, mais pénible pour trois lignes de code.
- Un jeton à usage unique (« nonce ») : la politique n'autorise que les scripts qui le portent, et Kioo le pose sur ceux des templates.

**Passer une valeur au JavaScript**
- Autoriser `{valeur}` dans une balise `<script>` : échapper correctement une valeur dans du code JavaScript est un terrain à failles.
- Une balise de données, que le navigateur n'exécute pas, et que le script lit.

**Relier Kioo à l'application**
- Laisser le développeur enregistrer lui-même Kioo dans le conteneur : explicite, mais il faudrait aussi lui faire passer le jeton à la main.
- Un argument du noyau.

## Décision
1. `Wazi\Http\CspNonce` porte un jeton tiré au hasard (128 bits). Le noyau en crée un par requête.
2. Le noyau le donne à `SecurityHeaders`, qui ajoute `'nonce-…'` à la directive `script-src`, et à Kioo, qui écrit `nonce="…"` sur chaque balise `<script>` **écrite dans un template**. Le développeur n'a rien à faire.
3. Une politique écrite en entier par le développeur (`contentSecurityPolicy:`) n'est pas modifiée.
4. `<k:json id="…" value="{…}">` écrit une valeur dans une balise `<script type="application/json">`, que le script lit avec `JSON.parse`. Le filtre `json` fait de même pour un attribut `data-`. Les caractères `< > & ' "` y sont écrits en codes Unicode.
5. L'affichage `{…}` reste sans effet dans une balise `<script>` : son contenu est recopié tel quel.
6. `new Kernel(views: $dossier)` rend `Kioo` disponible aux contrôleurs par leur constructeur. `Kioo::page($nom, $variables, $statut)` retourne une réponse HTML.
7. `CspNonce` vit dans `Http`, la couche du bas, car `Middleware` et `View` s'en servent tous deux (même raison que pour `Pipeline`, ADR-015).

## Pourquoi
- Le jeton rend la protection invisible pour le développeur honnête et intacte contre l'attaquant : un script injecté ne peut pas deviner une valeur qui change à chaque requête.
- Le jeton n'est posé que sur ce qui est écrit dans un fichier de template. Une valeur ne peut pas créer de balise (elle est échappée), et un script arrivé par `unsafe_raw` ne reçoit pas le jeton : le navigateur le refuse.
- Une donnée lue par `JSON.parse` n'est jamais interprétée comme du code, quel que soit son contenu.

## Conséquences
- Les attributs d'événement (`onclick="…"`) restent bloqués : un jeton ne s'applique qu'à une balise `<script>`.
- Un `<script>` écrit dans une chaîne PHP, sans passer par un template, reste bloqué.
- Avec PHP, chaque requête lance un programme neuf, donc un jeton neuf. Sur un serveur qui garderait le programme en vie entre deux requêtes, il faudrait créer le jeton par requête : `CspNonce` le signale dans son commentaire.
- Pour ajouter ses propres filtres, le développeur enregistre lui-même Kioo dans le conteneur, en lui passant le jeton : `$app->container->set(Kioo::class, fn (Container $c) => new Kioo($dossier, $filtres, $c->get(CspNonce::class)))`.
- Les pages d'erreur ne reçoivent pas de jeton : elles ne contiennent aucun script.
