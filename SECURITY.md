# Politique de sécurité

## Signaler une faille

**Ne publiez jamais une faille dans une issue publique.**

Utilisez le signalement privé de GitHub : onglet **Security** du dépôt, puis **Report a vulnerability**. Décrivez la faille, la version concernée et, si possible, un moyen de la reproduire.

Nous nous engageons à :

- accuser réception sous 72 heures ;
- vous tenir informé de l'analyse et du correctif ;
- publier un avis de sécurité une fois le correctif disponible, en vous créditant si vous le souhaitez.

## Versions prises en charge

Tant que la version 1.0 n'est pas publiée, seule la dernière version mineure reçoit des correctifs de sécurité. Un correctif sort sous la forme d'une version corrective (`0.6.1`), toujours sans risque à installer : voir le [journal des modifications](CHANGELOG.md).

| Version | Correctifs de sécurité |
| --- | --- |
| 0.6.x | Oui |
| 0.5.x et antérieures | Non |

## Nos engagements de conception

Wazi applique une sécurité stricte par défaut (voir `docs/decisions/0006-securite-par-defaut.md`) :

- toute protection est active sans configuration ;
- la désactiver demande un geste explicite et local ;
- aucun outil de débogage n'a d'adresse ni n'exécute quoi que ce soit ; la barre de débogage n'existe qu'en mode développement, pour une requête venue de la machine elle-même et sans proxy, et ne montre aucun secret (`docs/decisions/0035-barre-de-debogage.md`) ;
- le mode production est la valeur par défaut.
