# ADR-012 : le conteneur fabrique les objets par autowiring, avec deux gestes pour le reste

**Statut :** acceptée

## Contexte
La version 0.2 doit permettre d'écrire un contrôleur dont les dépendances sont injectées. Il faut un conteneur PSR-11. Deux tensions : l'autowiring repose sur la réflexion, qui peut passer pour de la magie (principe 1) ; et un conteneur qui fabrique n'importe quelle classe à partir de son nom devient dangereux si ce nom vient d'une requête (ADR-006).

## Options envisagées
- **Tout déclarer à la main** (une recette par service) : aucune magie, mais une configuration obligatoire avant la première page, contraire au principe 2.
- **Fichier de configuration des services** (YAML, tableau PHP) : c'est ce que le débutant retrouvera dans Symfony, mais c'est un second langage à apprendre.
- **Autowiring par défaut**, complété en PHP par deux méthodes.

## Décision
1. `get(Classe::class)` fabrique la classe en lisant son constructeur et en fabriquant chaque objet attendu. Chaque service n'est fabriqué qu'une fois.
2. Le conteneur ne devine que des **objets**. Pour un paramètre qu'il ne peut pas fournir, il prend la valeur par défaut, puis `null` si le paramètre l'accepte ; sinon il lève une erreur qui montre la ligne à écrire.
3. Deux gestes complètent l'autowiring : `set($id, fn (Container $c) => ...)` pour une recette, `bind(Interface::class, Classe::class)` pour une liaison. La liaison est vérifiée dès sa déclaration.
4. Un type union (`A|B`) n'est pas résolu : le conteneur ne choisit pas à la place du développeur.
5. Une dépendance circulaire est détectée et le message montre la chaîne.
6. Un service déjà fabriqué ne peut plus être redéfini.
7. **Garde-fous de sécurité :** le conteneur ne fabrique jamais automatiquement une classe interne de PHP ; un identifiant qui n'a pas la forme d'un nom de classe n'est pas transmis au chargeur de classes et n'est pas recopié dans le message d'erreur ; le conteneur ne s'enregistre pas lui-même comme service.

## Pourquoi
- Le cas courant (un contrôleur qui attend deux objets) ne demande aucune configuration.
- La réflexion reste lisible : `build()` et `argumentFor()` tiennent en deux courtes méthodes commentées, et le message d'erreur dit toujours quoi écrire.
- Tout se règle en PHP, dans un fichier que l'IDE sait suivre.
- Le point 7 limite les dégâts si la règle « jamais de nom de classe issu d'une requête » est un jour enfreinte. Ne pas enregistrer le conteneur comme service évite qu'un contrôleur le reçoive et y passe une valeur du visiteur.

## Conséquences
- Une classe qui attend un texte ou un nombre sans valeur par défaut demande une recette.
- Les recettes peuvent retourner n'importe quelle valeur ; les réglages auront leur propre composant (`Config`, 0.3).
- `has()` répond vrai pour toute classe existante, même si sa fabrication échouerait ensuite : PSR-11 le permet.
- La réflexion est relue à chaque requête ; un cache en PHP pur (`var_export`, jamais `unserialize`) pourra s'ajouter par un nouvel ADR si le besoin se présente.
- Les garde-fous du point 7 ne remplacent pas la règle : une classe de l'application reste fabricable par son nom.
