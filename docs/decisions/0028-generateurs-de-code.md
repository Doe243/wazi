# ADR-028 : les générateurs de code (`wazi make:…`)

**Statut :** acceptée — complète l'ADR-026

## Contexte
Créer un contrôleur demande d'écrire une classe, un attribut de route, un template, et une ligne dans `app.php`. Un débutant recopie ces lignes sans les comprendre, ou en oublie une. Une commande peut les écrire pour lui ; encore faut-il qu'elle l'aide à comprendre, et qu'elle ne puisse rien abîmer.

### Analyse de menaces
- **Le nom tapé devient un chemin de fichier et un nom de classe.** `../../public/porte` écrirait un fichier PHP dans le dossier public ; un nom contenant du code l'injecterait dans le fichier créé.
- **Écraser un fichier existant** ferait perdre du travail.
- **Modifier `app.php` automatiquement** peut casser un fichier que le développeur a organisé à sa façon.

## Décision
1. **Le code créé est commenté** : chaque ligne dit ce qu'elle fait. L'option `--no-comments` écrit le même code sans les explications.
2. **Le nom n'est accepté que sous la forme d'un nom de classe** : une majuscule, puis des lettres sans accent et des chiffres. Aucun autre caractère n'entre dans un chemin ni dans le code créé.
3. **Un fichier existant n'est jamais remplacé.** Tout est vérifié avant la première écriture : ou tous les fichiers sont créés, ou aucun. Il n'y a pas d'option pour forcer.
4. **Une commande ne modifie pas `app.php`.** Elle affiche la ligne à y ajouter.
5. L'espace de noms et le dossier des classes sont lus dans `composer.json` (`autoload`, `psr-4`), et vérifiés ; à défaut : `App` et `src`.
6. **Première commande : `wazi make:controller Nom`**, qui crée `src/NomController.php` (une route `GET /nom`) et `views/nom.kioo`. Si `views/base.kioo` existe, la page s'y place.

## Pourquoi
- Des commentaires par défaut servent la promesse de Wazi : comprendre ce qu'on utilise. Les retirer est un choix, fait quand on n'en a plus besoin.
- Une forme stricte du nom rend l'injection impossible plutôt que filtrée : il n'y a rien à échapper.
- Faire écrire la ligne d'`app.php` par le développeur garde vrai le principe de transparence : aucune route n'apparaît sans qu'il l'ait déclarée.

## Conséquences
- L'adresse créée est le nom en minuscules, avec des tirets (`BlogPost` → `/blog-post`), sans pluriel : la commande ne devine pas la grammaire.
- Les générateurs n'ont pas besoin de l'application du projet : ils vivent dans le composant `Console`.
- D'autres générateurs (middleware, commande) suivront les mêmes règles.
