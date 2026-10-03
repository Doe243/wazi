# ADR-014 : une politique de sécurité de contenu stricte pour les scripts seulement

**Statut :** acceptée — remplace le point 4 de l'ADR-013

## Contexte
L'ADR-013 posait une politique de sécurité de contenu (CSP) qui n'autorisait que ce qui venait du site lui-même. À l'usage prévisible d'un débutant, elle bloquait le CSS d'un CDN, les polices de Google Fonts, les images d'un autre site et les vidéos intégrées, sans que Wazi puisse l'expliquer (c'est le navigateur qui refuse). Le principe 8 demande une sécurité stricte, mais jamais pénible.

## Options envisagées
- Garder la politique de l'ADR-013 : la plus sûre sur le papier, mais elle pousse à la désactiver en bloc, ce qui est pire.
- Autoriser les scripts écrits dans la page (`'unsafe-inline'`) : confortable, mais la politique ne protège alors presque plus contre le XSS.
- Ne plus envoyer de politique par défaut.
- N'être strict que sur le JavaScript, et ouvrir le reste.

## Décision
1. **Scripts :** uniquement ceux du site lui-même. Ni script écrit dans la page, ni `eval`.
2. **Tout le reste** (feuilles de style, polices, images, sons et vidéos, cadres, appels réseau) : autorisé depuis le site et depuis n'importe quel site en `https`. Les styles écrits dans la page et les images et polices `data:` sont permis.
3. Pour charger le JavaScript d'un autre site, on le déclare : `new SecurityHeaders(scripts: ['https://cdn.jsdelivr.net'])`. Seules des adresses de sites en `https` sont acceptées ; les mots-clés (`'unsafe-inline'`), les jokers et `http` sont refusés par cet argument.
4. Restent inchangés : `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'none'`.
5. Qui veut une autre politique l'écrit en entier : `new SecurityHeaders(contentSecurityPolicy: '...')`. N'en envoyer aucune demande `contentSecurityPolicy: SecurityHeaders::WITHOUT_POLICY`. Donner à la fois `scripts` et une politique complète est une erreur.

## Pourquoi
- Le danger d'une faille XSS, c'est l'exécution d'un script. Une image, une police ou une feuille de style venue d'ailleurs ne permet presque rien : les bloquer coûtait beaucoup pour un gain minime.
- Il ne reste qu'un seul cas à expliquer au débutant, et il se règle en une ligne sans apprendre la syntaxe d'une CSP.
- L'argument `scripts` ne peut pas servir à annuler la protection par mégarde ; s'en passer demande d'écrire la politique complète, un geste visible.

## Conséquences
- Les scripts écrits dans la page (`<script>...</script>`, `onclick="..."`) restent refusés. La docblock de `SecurityHeaders` l'explique et dit de les mettre dans un fichier `.js`.
- **À faire avec le composant `View` (0.3) :** un jeton à usage unique (« nonce ») généré à chaque requête et posé automatiquement par la vue sur les balises `<script>` du développeur, pour rendre ce cas indolore.
- Une page peut charger une feuille de style ou une image depuis n'importe quel site en `https`. Une faille d'injection HTML permettrait donc de modifier l'apparence d'une page ou d'y faire charger une image traceuse ; c'est accepté.
- `connect-src` ouvert en `https` : un script du site peut appeler n'importe quelle API. Cela ne protège plus contre l'envoi de données vers l'extérieur par un script déjà compromis, cas que `script-src` est censé empêcher en amont.
- Idée gardée pour plus tard : en mode développement, recevoir les signalements de blocage du navigateur et les expliquer dans le terminal.
