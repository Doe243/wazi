# ADR-022 : proxies de confiance

**Statut :** acceptée — complète l'ADR-008 (point 3)

## Contexte
L'ADR-008 a décidé de ne jamais utiliser les en-têtes `X-Forwarded-*`, et renvoyait à plus tard la déclaration de proxies de confiance. Or presque tous les hébergements modernes placent un proxy devant PHP (répartiteur de charge, Cloudflare, nginx ou Traefik dans un conteneur) : c'est lui qui reçoit la connexion HTTPS du visiteur, et il parle ensuite en HTTP à PHP. Sans proxy de confiance, Wazi croit le site en HTTP : le cookie de session part sans l'attribut `Secure` (ADR-021), les adresses absolues sont fausses, et l'adresse IP vue est celle du proxy.

## Options envisagées
- Croire les en-têtes dès qu'ils sont présents : n'importe quel client peut les écrire.
- Un interrupteur « je suis derrière un proxy » : il ferait croire aussi un client qui contacterait PHP directement.
- Une liste d'adresses de proxies, déclarée par le développeur.

## Décision
1. `new ServerRequestCreator(trustedProxies: ['10.0.0.5', '10.0.0.0/8'])` déclare les proxies : des adresses IP ou des plages, en IPv4 ou IPv6. Une valeur qui n'en est pas une est refusée à la déclaration.
2. Les en-têtes `X-Forwarded-*` ne sont lus que si `REMOTE_ADDR`, l'adresse de la machine réellement connectée à PHP, appartient à cette liste. Sinon ils sont ignorés, comme avant.
3. Derrière un proxy de confiance :
   - `X-Forwarded-Proto` donne le schéma, s'il vaut `http` ou `https` ;
   - `X-Forwarded-Host` donne l'hôte, soumis aux mêmes contrôles que `Host` (forme valide, hôtes de confiance) ;
   - `X-Forwarded-For` donne l'adresse du visiteur : on lit la liste de droite à gauche et on s'arrête à la première adresse qui n'est pas un proxy de confiance.
4. L'adresse du visiteur est posée sur la requête dans l'attribut `client_ip`. Sans proxy, c'est `REMOTE_ADDR`.
5. L'en-tête `Forwarded` (RFC 7239) n'est pas lu.

## Pourquoi
- `REMOTE_ADDR` ne se falsifie pas par un en-tête : c'est la seule base sûre pour décider de croire ou non le reste.
- Lire `X-Forwarded-For` par la droite ne retient que ce que les proxies de confiance ont écrit ; ce qu'un client a mis à gauche pour se faire passer pour une autre adresse est ignoré.
- Une liste d'adresses est un geste explicite, qui se lit dans le fichier d'entrée de l'application (principe 8).

## Conséquences
- Un proxy oublié dans la liste donne un site vu en `http` : c'est le comportement sûr par défaut, mais il faut le savoir. La documentation du déploiement devra l'expliquer.
- Une plage trop large (`0.0.0.0/0`) revient à croire tout le monde. Elle est acceptée, car certaines plateformes ne publient pas l'adresse de leurs proxies, mais elle ne doit servir que si PHP n'est joignable que par eux.
- L'attribut `client_ip` existe désormais sur toute requête créée par `ServerRequestCreator` : une route ne peut pas avoir de paramètre de ce nom.
- Le projet de départ lira la liste dans le `.env` (`APP_TRUSTED_PROXIES`).
