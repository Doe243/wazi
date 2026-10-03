# ADR-024 : sessions — messages flash, verrou, « se souvenir de moi »

**Statut :** acceptée — complète l'ADR-021

## Contexte
Les sessions de l'ADR-021 laissaient trois manques :
- **messages flash** : après un formulaire réussi, on redirige ; le message « Note ajoutée » doit survivre à la redirection, puis disparaître. Sans outil, chaque développeur l'écrit à la main, souvent mal (message qui reste, ou qui se perd) ;
- **requêtes simultanées** : deux requêtes du même visiteur (deux onglets, requêtes JavaScript) lisaient chacune la session, puis la réécrivaient en entier. La dernière effaçait ce que l'autre avait noté. Mesuré : 6 processus × 50 incréments d'un compteur donnaient 78 au lieu de 300, et des écritures échouaient sous Windows ;
- **durée** : toutes les sessions duraient deux heures et disparaissaient avec le navigateur. Pas de « se souvenir de moi ».

## Options envisagées
- Flash valable « une requête exactement » : une requête intermédiaire (image générée, autre onglet) consomme le message sans l'afficher.
- Flash « jusqu'à la lecture ».
- Verrou : ne rien verrouiller et fusionner les clés modifiées à l'écriture. Plus rapide, mais un « lire puis écrire » (compteur, jeton à usage unique) reste faux.
- Verrou exclusif pendant toute la requête, comme les sessions natives de PHP.
- « Se souvenir de moi » par un second cookie et une table de jetons : il faut une base de données, que Wazi n'a pas encore.
- « Se souvenir de moi » par une durée propre à la session.

## Décision
1. **Flash jusqu'à la lecture.** `flash($clé, $valeur)` range le message sous la clé réservée `_flash` ; `takeFlash($clé)` le rend et l'efface. Mêmes valeurs simples que `set()`. Si le message était tout le contenu de la session, elle disparaît avec lui (fichier et cookie).
2. **Verrou exclusif par session.** `SessionStore` gagne `lock()` et `unlock()`. Le middleware réserve la session avant de la lire et la libère dans un `finally`. `FileSessionStore` utilise `flock()` sur un fichier `<id>.json.lock`.
3. **Attente bornée** : 10 secondes par défaut, réglable. Au-delà, `SessionStoreException` explique qu'une requête lente retient la session. Jamais de traitement sans verrou « faute de mieux ».
4. **Pas de verrou pour une session qui n'existe pas** : un identifiant inventé ne crée aucun fichier. Un visiteur sans cookie de session n'attend jamais.
5. **Durée propre à la session.** `remember($jours)` (30 par défaut, de 1 à 365) range la durée sous la clé réservée `_remember`. Le cookie reçoit alors `Max-Age`, renvoyé à chaque visite ; le fichier expire à la même échéance. `clear()` y met fin.
6. **La date de modification d'un fichier de session est sa date d'expiration** (placée dans le futur à chaque écriture). C'est ce qui permet des durées différentes d'une session à l'autre sans ouvrir chaque fichier pour faire le ménage.
7. Une durée relue du fichier hors des bornes est ignorée : la session reprend la durée ordinaire.

## Pourquoi
- Le verrou complet est le seul qui rende juste un « lire, décider, écrire » : c'est ce qu'attend un débutant, et ce dont dépend un jeton à usage unique ou un compteur de tentatives.
- Une attente bornée avec un message clair vaut mieux qu'un site figé sans explication.
- Une seule session, un seul cookie : rien de nouveau à voler. Régénérer l'identifiant à la connexion (ADR-021) reste la règle.
- La durée maximale d'un an évite la session éternelle écrite par erreur.

## Conséquences
- Deux requêtes du même visiteur sont traitées l'une après l'autre : une page lente fait attendre ses autres requêtes. C'est le prix de la justesse, et le même que celui des sessions natives de PHP.
- Une session longue reste ouverte sur l'appareil où elle a été créée : `remember()` ne s'appelle que si le visiteur l'a demandé (case à cocher). La documentation le dira, et recommandera de redemander le mot de passe avant une action sensible.
- Les fichiers `.lock` sont vides ; ils sont supprimés avec leur session, et le ménage retire ceux qui restent.
- Un rangement autre que les fichiers (base de données) devra fournir `lock()` et `unlock()`.
- Sur un système de fichiers en réseau (NFS), `flock()` peut ne pas être fiable : à plusieurs serveurs, il faudra un autre rangement.
