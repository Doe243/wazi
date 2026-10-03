# ADR-006 : sécurité stricte par défaut

**Statut :** acceptée

## Contexte
Les failles se multiplient, le code est de plus en plus généré par des IA, et les fonctionnalités pédagogiques de Wazi (erreurs détaillées, outils d'inspection) sont précisément celles qui exposent le plus d'informations. Précédent connu : les pages de débogage de Laravel (Ignition) ont permis l'exécution de code à distance sur des serveurs laissés en mode débogage (CVE-2021-3129).

## Options envisagées
- Protections optionnelles, activées par configuration : plus souple, mais un débutant ou une IA ne les activera pas.
- Page de débogage web limitée aux requêtes locales : confortable, mais une erreur de configuration ou de proxy suffit à l'exposer.
- Protections actives par défaut, aucun outil de débogage via HTTP.

## Décision
1. Toute protection est active par défaut. La désactiver demande un geste explicite, nommé et local (`unsafe_raw()`, `#[WithoutCsrf]` sur une route), jamais un interrupteur global.
2. Aucun outil de débogage n'est accessible via HTTP, même en développement : ni profileur, ni inspecteur, ni exécution de code.
3. En développement, le navigateur affiche au plus un message pédagogique (ce qui s'est passé, fichier et ligne, solution), sans variables d'environnement ni valeurs de variables. Le détail complet est dans le terminal, le journal et la commande `wazi errors:last`.
4. Le mode production est la valeur par défaut.
5. Chaque refus explique ce qui a été bloqué, le risque évité et la bonne façon de faire.

## Pourquoi
Un défaut sûr protège ceux qui ne lisent pas la documentation ; une sortie explicite évite que la sécurité devienne un obstacle qu'on contourne globalement. Interdire le débogage HTTP supprime toute une famille de failles.

## Conséquences
Moins de confort visuel que les pages d'erreur interactives d'autres frameworks. Chaque nouveau composant passe par une courte analyse de menaces. La section « Sécurité par défaut » du document d'architecture sert de référence.
