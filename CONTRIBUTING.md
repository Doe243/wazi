# Contribuer à Wazi

Merci de vouloir aider. Ce document dit comment le projet travaille, pour qu'une contribution arrive vite au bout.

En participant, vous acceptez le [code de conduite](CODE_OF_CONDUCT.md) du projet.

## Avant de commencer

- **Un bogue ?** Ouvrez une issue avec de quoi le reproduire : la version de Wazi, celle de PHP, le code le plus court qui montre le problème.
- **Une faille de sécurité ?** Jamais dans une issue publique. Voir [SECURITY.md](SECURITY.md).
- **Une idée ?** Ouvrez une issue avant d'écrire le code. Wazi refuse volontairement beaucoup de choses : mieux vaut en parler d'abord.

### Ce que Wazi accepte, et ce qu'il refuse

Wazi existe pour qu'un débutant **comprenne** ce qu'il utilise. Huit principes tranchent chaque décision (ils sont dans le [README](README.md#principes)). Une contribution qui en viole un est refusée ou repensée, même si elle est utile. En particulier :

- pas de magie : ni façade statique, ni `__call` dans l'API publique ;
- pas d'interrupteur global pour désactiver une protection : une sortie de sécurité est explicite, nommée et locale ;
- pas de nouvelle dépendance d'exécution au-delà des interfaces PSR.

## Installer le projet

Il faut PHP 8.5 et Composer.

```bash
git clone https://github.com/wazi-php/wazi.git
cd wazi
composer install
composer check
```

`composer check` lance tout ce que lance l'intégration continue :

| Commande | Rôle |
| --- | --- |
| `composer check` | Tout : audit des dépendances, style, analyse statique, couches, tests |
| `composer test` | Les tests (PHPUnit) |
| `composer stan` | L'analyse statique (PHPStan, niveau maximal, aucune erreur ignorée) |
| `composer cs` / `composer cs:fix` | Vérifier / corriger le style |
| `composer deptrac` | Les règles de dépendance entre couches |

Les tests de MySQL et de PostgreSQL ne s'exécutent que si un serveur de test est indiqué ; sinon ils sont ignorés, et l'intégration continue les joue :

```bash
WAZI_TEST_MYSQL_URL=mysql://root:wazi@127.0.0.1:3306/wazi_test
WAZI_TEST_POSTGRES_URL=postgres://wazi:wazi@127.0.0.1:5432/wazi_test
```

Ces bases sont vidées par les tests : ne donnez jamais l'adresse d'une base qui contient quelque chose.

## Le chemin d'un changement

On ne commite jamais sur `main` ([décision 034](docs/decisions/0034-branches.md)).

1. **Créez une branche** depuis `main`, pour un seul sujet :

   | Préfixe | Pour | Exemple |
   | --- | --- | --- |
   | `fonction/` | une fonctionnalité | `fonction/barre-de-debogage` |
   | `correctif/` | une correction | `correctif/session-windows` |
   | `docs/` | la documentation seule | `docs/guide-sessions` |
   | `publication/` | préparer une version | `publication/0.5.0` |

2. **Écrivez le changement**, dans cet ordre pour un composant : analyse de menaces, tests, code.
3. **Mettez à jour ce qui l'accompagne** :
   - `CHANGELOG.md`, section « À venir » ;
   - le guide (`docs/guide/`), si l'usage change ;
   - une décision d'architecture (`docs/decisions/`), si le choix est structurant. On ne modifie pas une décision existante : on en écrit une nouvelle qui la remplace. Le modèle est `docs/decisions/TEMPLATE.md`.
4. **Vérifiez** : `composer check` doit passer.
5. **Ouvrez une demande de fusion** vers `main`. Elle est fusionnée quand l'intégration continue est verte.

## Les conventions de code

Le code source de Wazi fait partie de l'apprentissage : il est écrit pour être lu par un débutant.

- **Langue :** commentaires, messages d'erreur et documentation en français ; noms de classes, de méthodes et de tests en anglais.
- **Commentaires :** ils expliquent le *pourquoi*. Chaque classe commence par un commentaire qui dit son rôle.
- **Classes :** `final` par défaut ; `final readonly` pour les objets valeur. `declare(strict_types=1);` dans chaque fichier.
- **Exceptions :** dans `Wazi\{Composant}\Exception\`, avec des constructeurs statiques nommés. Chaque message dit ce qui s'est passé, pourquoi, et comment corriger.
- **Couches :** une couche n'utilise que celles du dessous (`deptrac.yaml`). Deux composants de la même couche ne se connaissent pas.
- **Tests :** un fichier de test par classe, en miroir de `src/`. Les tests de sécurité vivent à côté des tests fonctionnels.
- **Style :** PER Coding Style, appliqué par `composer cs:fix`.

### La sécurité, à chaque composant

Avant d'écrire un composant, posez-vous deux questions : qu'est-ce qu'un attaquant peut envoyer ici ? Que cherche-t-il à obtenir ? Puis :

- le défaut est le choix sûr ;
- un message d'erreur ne recopie pas une valeur reçue de l'extérieur sans la nettoyer et la borner, et ne contient jamais un secret ;
- toute fonction de fichier de PHP ne reçoit qu'un chemin ordinaire, jamais une adresse à protocole (`php://`, `phar://`).

## Les versions

Wazi suit le versionnage sémantique ([décision 033](docs/decisions/0033-versions-et-publications.md)). Le contenu d'une version est fixé avant de commencer : une idée nouvelle va dans la version suivante.

## Licence

En contribuant, vous acceptez que votre contribution soit publiée sous la [licence MIT](LICENSE) du projet.
