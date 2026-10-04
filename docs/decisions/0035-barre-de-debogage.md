# ADR-035 : la barre de débogage

**Statut :** acceptée (remplace le point 2 de la décision de l'ADR-006)

## Contexte
L'ADR-006 interdit tout outil de débogage accessible par HTTP, même en développement. La raison est solide : les pages de débogage d'autres frameworks ont servi à prendre le contrôle de serveurs laissés en mode débogage, et celles qui se limitaient aux « requêtes locales » ont été exposées par une simple erreur de proxy.

L'utilisateur veut pourtant une barre d'outils en bas de page, « comme Symfony, vraiment sympa », et a tranché le 2026-10-03 : on la fait, « la sécurité avant tout ». Garde-fous acceptés : mode développement seulement, `localhost` seulement, aucun secret affiché, code non chargé en production.

Pour un débutant, voir en bas de sa page quelle route a répondu, quel template a été écrit et en combien de temps est exactement ce que promet Wazi : comprendre ce qui se passe.

### Analyse de menaces
- **La barre servie à un visiteur**, sur un site laissé en mode développement : elle lui montre les routes, les fichiers, les noms des clés de session.
- **Le proxy sur la même machine** : derrière nginx ou Apache en proxy, toutes les requêtes semblent venir de `127.0.0.1`. C'est ainsi que les outils « réservés à localhost » fuient.
- **Un secret affiché** : mot de passe, clé, cookie, valeur de session, contenu de `.env`.
- **Une valeur piégée affichée** : une adresse ou un nom de champ contenant du HTML (XSS dans la barre).
- **Une adresse de plus** : un profileur a ses propres pages (`/_profiler`), qui gardent l'historique des requêtes. C'est une surface d'attaque en soi, et un stockage de données sensibles.
- **Une action déclenchée depuis la barre** : rejouer une requête, vider un cache, exécuter du code.

## Options envisagées
- Rester à l'ADR-006 : rien par HTTP. Le plus sûr, mais contraire à la décision de l'utilisateur.
- Un profileur complet, avec ses pages et son historique (Symfony) : puissant, mais il ajoute des routes, un stockage et beaucoup de code à protéger.
- **Une barre en lecture seule, écrite dans la page elle-même, sans aucune adresse à elle.**

## Décision
1. Nouveau composant **`Debug`**, de la couche des fonctionnalités : `DebugBar` (les garde-fous et le HTML), `Panel` (une rubrique), `Trace` (ce que les composants signalent). Un contrat `Contracts\Tracer` permet à un composant de signaler ce qu'il fait sans connaître la barre.
2. **La barre n'a aucune adresse.** Elle est ajoutée à la fin de la page HTML que le site allait répondre de toute façon. Rien n'est gardé d'une requête à l'autre : ni fichier, ni session, ni historique.
3. **Elle ne fait que montrer.** Aucun bouton n'agit sur l'application, aucun code n'est exécuté à la demande. Ses panneaux s'ouvrent avec `<details>`, sans JavaScript.
4. **Quatre conditions, toutes nécessaires**, vérifiées à chaque requête :
   - le noyau est en mode développement (`new Kernel(development: true)`), ce qui n'est jamais deviné ;
   - la connexion vient de la machine elle-même : l'adresse du pair (`REMOTE_ADDR`) est `127.0.0.1` ou `::1`. Jamais l'adresse annoncée par un en-tête ;
   - **la requête ne porte aucun en-tête de proxy** (`X-Forwarded-*`, `Forwarded`, `Via`, `X-Real-IP`...). S'il y en a un, c'est qu'un proxy se trouve devant, et que « local » ne veut plus rien dire ;
   - le site est demandé sous un nom local : `localhost`, `*.localhost`, `127.0.0.1` ou `[::1]`.
5. **Aucune valeur sensible n'est collectée**, donc aucune ne peut être affichée. La barre montre :
   - la requête : méthode, chemin, code de la réponse, durée, mémoire ;
   - la route : son motif, le code exécuté, ses paramètres (ils viennent de l'adresse, déjà visible) ;
   - les middlewares traversés ;
   - les templates écrits, et leur durée ;
   - la session : seulement **le nom** de ses clés ;
   - le formulaire reçu : seulement **le nom** de ses champs ;
   - les versions de PHP et de Wazi.

   Jamais : la valeur d'un cookie, d'un en-tête, d'un champ, d'une clé de session ; ni une variable d'environnement, ni un réglage.
6. **Tout ce qui est écrit dans la barre est échappé.**
7. **Les pages d'erreur ne portent pas la barre.** Leur politique de sécurité (`default-src 'none'`) reste intacte.
8. **En production, ce code n'est pas chargé** : le noyau ne crée aucun objet du composant `Debug` hors du mode développement.
9. La barre est affichée d'office en mode développement. Pour s'en passer : `new Kernel(development: true, debugBar: false)`.

## Pourquoi
- Sans adresse et sans stockage, la barre ne peut être atteinte que par quelqu'un qui reçoit déjà la page : la surface d'attaque d'un profileur disparaît.
- Refuser dès qu'un en-tête de proxy est présent ferme la fuite classique des outils « réservés à localhost ».
- Ne collecter que des noms rend la fuite d'un secret impossible par construction, plutôt que par un filtre qu'on pourrait oublier.
- La lecture seule écarte toute exécution à distance : il n'y a rien à déclencher.

## Conséquences
- Derrière un proxy de développement (Docker avec un proxy, tunnel, certains environnements en ligne), la barre ne s'affiche pas. C'est voulu ; `wazi explain` donne les mêmes informations dans le terminal.
- Une requête faite par JavaScript, une redirection ou une réponse qui n'est pas du HTML n'a pas de barre. Il n'y a pas d'historique pour les retrouver.
- Avec une politique de sécurité de contenu réécrite par l'application pour interdire les styles écrits dans la page, la barre s'affiche sans mise en forme.
- La base de données signalera ses requêtes à la barre par le même contrat `Tracer`, dans une étape suivante : le texte SQL et sa durée, jamais les valeurs.
- Les règles 1, 3, 4 et 5 de l'ADR-006 restent en vigueur.
