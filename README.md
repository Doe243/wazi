# Wazi

> *Wazi* : « clair, ouvert, évident » en swahili.

Wazi est un framework PHP pensé pour les développeurs qui veulent **comprendre** ce qu'ils utilisent tout en construisant de vraies applications. Pas de magie cachée : chaque comportement se suit dans l'IDE, chaque erreur explique sa cause et la solution.

> **Statut : en construction (avant 0.1).** Rien n'est encore utilisable.

## Principes

1. Transparence avant magie
2. Zéro configuration obligatoire
3. Complexité progressive : un fichier au départ, une application structurée ensuite
4. Erreurs pédagogiques
5. Code source lisible
6. PHP moderne uniquement (8.5+)
7. Standards PSR respectés (PSR-7, PSR-11, PSR-15, PSR-17)

## Architecture

Les composants sont rangés en quatre couches. Une couche peut utiliser celles du dessous, jamais celles du dessus (règle vérifiée par Deptrac).

| Couche | Composants |
| --- | --- |
| Assemblage | `Kernel` |
| Fonctionnalités | `Routing`, `Middleware`, `Errors`, `View`, `Console` |
| Fondations | `Http`, `Container`, `Config` |
| Contrats | `Contracts` |

Les décisions d'architecture sont consignées dans [`docs/decisions/`](docs/decisions/).

## Sécurité

Wazi applique une sécurité stricte par défaut. Pour signaler une faille, voir [`SECURITY.md`](SECURITY.md) : jamais dans une issue publique.

## Contribuer

```bash
composer install
composer check   # style, analyse statique, dépendances entre couches, tests
composer cs:fix  # corrige automatiquement le style
```

Une contribution n'est fusionnée que si `composer check` passe.

## Licence

MIT
