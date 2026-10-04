# ADR-019 : Kioo, le langage de templates de Wazi

**Statut :** acceptée — complétée par l'ADR-020 et l'ADR-025 ; son point 11 (aucun fichier PHP généré) est remplacé par l'ADR-030

## Contexte
La version 0.3 doit afficher des pages. La question « templates PHP natifs ou syntaxe maison » a été tranchée le 3 octobre 2026 : un langage maison, nommé **Kioo** (« vitre, miroir » en swahili), avec l'ambition d'être plus facile que Twig. Une première syntaxe proposée, à délimiteurs `{{ }}` et `{% %}`, a été refusée parce qu'elle ressemblait trop à Twig. Cet ADR fixe la syntaxe, la façon d'exécuter un template, et le périmètre de la 0.3.

## Options envisagées

**Syntaxe**
- Délimiteurs à la Twig (`{{ }}`, `{% %}`) : familiers, mais sans identité propre. Refusée.
- Balises dédiées pour tout (`<k:for>`, `<k:if>`) : lisibles, mais verbeuses.
- Blocs à accolades à la Svelte (`{#for}`, `{/for}`) : compacts, mais c'est remplacer une ressemblance par une autre.
- **Attributs sur les balises HTML** (`<li k:for="note in notes">`) et affichage par une seule accolade (`{note.texte}`).

**Exécution**
- Compiler chaque template en fichier PHP gardé en cache : rapide, mais demande un dossier inscriptible, et exécuter des fichiers PHP générés est une surface d'attaque (qui peut écrire dans le cache peut exécuter du code).
- **Interpréter** : lire le template, construire un arbre, le parcourir. Plus lent, mais sans configuration, sans fichier généré, et une erreur désigne exactement sa ligne.

## Décision

### Syntaxe
1. Un template est une page HTML ordinaire, d'extension `.kioo`.
2. **Affichage :** `{expression}`, dans le texte ou dans la valeur d'un attribut.
3. **Structures :** des attributs sur les balises : `k:if`, `k:else`, `k:for="élément in liste"`. La structure vit et meurt avec sa balise ; une balise qui en porte une doit être fermée explicitement.
4. **Balises `<k:…>`** pour ce qui n'a pas de balise naturelle : `<k:layout>`, `<k:block>`, `<k:include>`.
5. **Expressions** (ce n'est pas du PHP) : variables, `a.b` (clé d'un tableau ou propriété publique), `a[b]`, `a.methode(x)`, nombres, textes, `true`/`false`/`null`, `+ - * / %`, `== != < > <= >=`, `and`/`or`/`not`, `? :`, `??`, parenthèses.
6. **Filtres :** `{valeur | filtre(argument)}`. Le filtre s'applique à **toute** l'expression à sa gauche : `{prix * 2 | number}` formate le produit. Pour filtrer une partie, on met des parenthèses. Liste fermée, complétée par ceux que l'application enregistre.
7. Les mots du langage sont en anglais (`k:for`, `in`, `and`, `upper`), comme ceux de HTML et de PHP qui l'entourent.

### Règles du langage
8. Une variable, une clé ou une propriété inconnue est **toujours** une erreur, avec une suggestion ; `??` est la façon de prévoir l'absence.
9. `==` ne compare que des valeurs de même type (un nombre entier et un nombre à virgule se comparent entre eux). `<` et `>` ne comparent que deux nombres ou deux textes. Le calcul n'accepte que des nombres.
10. Sont « faux » : `false`, `null`, le texte vide, le nombre zéro et la liste vide. Tout le reste est « vrai ».

### Exécution et sécurité
11. Kioo est **interprété** en 0.3. Aucun fichier PHP n'est généré, aucun `eval`.
12. Un template ne peut appeler aucune fonction PHP. Il peut appeler les méthodes publiques des objets qu'on lui donne (sauf les méthodes « magiques », dont le nom commence par `__`).
13. L'affichage est échappé selon l'endroit (texte, attribut, adresse). La sortie brute se demande par `| unsafe_raw`. L'affichage est refusé là où l'échappement n'est pas sûr (`<script>`, `<style>`, attributs d'événement).
14. Le nom d'un template est borné au dossier des vues.
15. Les balises `<script>` du développeur reçoivent le jeton CSP de l'ADR-014.

### Périmètre de la 0.3
Dans la 0.3 : les points ci-dessus. Hors 0.3 : composants avec emplacements, macros, traduction, listes et dictionnaires littéraux, opérateur de concaténation, compilation et cache.

### Dépendance
16. Le composant `View` demande l'extension PHP **mbstring** (`ext-mbstring`), pour que les filtres traitent correctement les lettres accentuées. C'est une extension de PHP, pas un paquet ; elle s'ajoute aux exigences de `composer.json`.

## Pourquoi
- Les attributs gardent le template lisible par un navigateur et par un éditeur HTML, et suppriment les fermetures à retenir (`endif`, `endfor`). C'est aussi le sens du nom : une vitre posée sur du HTML.
- Pour échapper selon l'endroit, Kioo doit de toute façon reconnaître balises et attributs : la syntaxe réutilise ce travail.
- Le filtre appliqué à toute l'expression évite un piège connu de Twig, où `a ~ b|upper` ne met en majuscules que `b`.
- L'erreur systématique sur une variable inconnue transforme une page silencieusement vide en message qui dit quoi corriger (principe 4).
- L'interprétation tient le code court et lisible (principe 5) et retire toute une famille de risques.

## Conséquences
- En 0.3, Kioo est plus simple et plus sûr que Twig, pas plus puissant : il a moins de fonctionnalités. La puissance (composants, macros) viendra sur cette base.
- Un template est relu et analysé à chaque requête. Si la vitesse devient un besoin, un cache de l'arbre ou une compilation s'ajoutera par un nouvel ADR, sans changer la syntaxe.
- Kioo doit analyser du HTML : c'est le composant le plus volumineux du framework. Il est construit en étapes, chacune testée : expressions, puis HTML et échappement, puis structures et mise en page, puis branchement au `Kernel`.
- Une accolade ouvrante dans le texte d'une page est le début d'un affichage : pour écrire une accolade telle quelle, il faudra une écriture d'échappement, à définir avec l'étape HTML.
- Les comparaisons strictes surprendront qui vient de PHP : `{id == '3'}` est faux si `id` est le nombre 3. Le message d'erreur ou la documentation devront l'expliquer.
