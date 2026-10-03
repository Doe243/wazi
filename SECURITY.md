# Politique de sécurité

## Signaler une faille

**Ne publiez jamais une faille dans une issue publique.**

Utilisez le signalement privé de GitHub : onglet **Security** du dépôt, puis **Report a vulnerability**. Décrivez la faille, la version concernée et, si possible, un moyen de la reproduire.

Nous nous engageons à :

- accuser réception sous 72 heures ;
- vous tenir informé de l'analyse et du correctif ;
- publier un avis de sécurité une fois le correctif disponible, en vous créditant si vous le souhaitez.

## Versions prises en charge

Wazi est en cours de développement (avant 0.1). Tant que la version 1.0 n'est pas publiée, seule la dernière version reçoit des correctifs de sécurité.

| Version | Correctifs de sécurité |
| --- | --- |
| Dernière version publiée | Oui |
| Versions antérieures | Non |

## Nos engagements de conception

Wazi applique une sécurité stricte par défaut (voir `docs/decisions/0006-securite-par-defaut.md`) :

- toute protection est active sans configuration ;
- la désactiver demande un geste explicite et local ;
- aucun outil de débogage n'est accessible via HTTP, même en développement ;
- le mode production est la valeur par défaut.
