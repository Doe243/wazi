# ADR-025 : Kioo plus simple à utiliser, et plus rapide à l'affichage

**Statut :** acceptée — complète l'ADR-019

## Contexte
L'application de démonstration a montré quatre irritants de Kioo, et une lenteur :
- un commentaire HTML d'un template était recopié dans la page envoyée au visiteur, et répété à chaque tour de boucle ;
- pour ajouter un filtre, il fallait redéfinir Kioo dans le conteneur avec ses quatre arguments ;
- Kioo étant strict, toute variable affichée par la mise en page devait être passée par chaque contrôleur, ce qui obligeait à écrire un service intermédiaire ;
- une valeur placée dans une adresse était échappée pour le HTML, pas pour l'adresse ;
- la page des notes de la démonstration (30 notes) demandait environ 12 ms, dont 7,5 pour analyser les templates et 4,5 pour les afficher.

L'utilisateur a demandé le 2026-10-03 : « l'idée n'est pas d'avoir un outil compliqué à utiliser et pas performant ».

## Décision

### Simplicité
1. **Les commentaires `<!-- … -->` d'un template ne sont jamais écrits dans la page.** Un commentaire seul sur sa ligne emporte sa ligne. Le contenu de `<script>` et de `<style>` n'est pas concerné. La déclaration `<!DOCTYPE>` reste.
2. **`Kioo::addFilter($nom, $fonction)`** ajoute un filtre. Un filtre ne peut pas en remplacer un autre : le nom doit être libre.
3. **`Kioo::share($nom, $valeur)`** partage une variable avec tous les templates, mises en page et morceaux inclus compris. Si la valeur est une fonction, elle est appelée une fois par page affichée. Une variable de même nom donnée à une page, ou à un `<k:include>`, l'emporte.
4. **Filtre `url`** : prépare un texte pour entrer dans une adresse (`rawurlencode`).
5. `Kioo` n'est plus `readonly` : ces deux réglages se font après sa création, au démarrage de l'application.

### Vitesse
6. **Ce qui ne dépend d'aucune valeur est écrit dès la lecture du template.** Une balise dont ni les attributs ni le contenu ne contiennent d'affichage ou de structure devient un `StaticElement` : son HTML est prêt, il n'y a plus qu'à le recopier. Font exception `<script>` et `<form>`, qui reçoivent un jeton propre à la requête, et les balises `<k:…>`.
7. L'analyseur compte les lignes au fur et à mesure, ajoute les balises sans recopier les listes, et avance par blocs plutôt que caractère par caractère. Les expressions sont découpées par une seule recherche.
8. **Kioo reste interprété** (ADR-019) : aucun fichier PHP n'est généré, rien n'est gardé sur le disque.

## Pourquoi
- **Commentaires :** un commentaire s'adresse à qui lit le template. Laissé dans la page, il renseigne le visiteur sur la façon dont le site est fait. C'est une fuite connue ; la retirer par défaut est le réglage sûr (ADR-006).
- **`share()` et les morceaux inclus :** la règle « un morceau inclus ne voit que ce qu'on lui passe » protège contre une fuite involontaire d'une variable de la page. Une variable partagée l'est par un geste explicite de l'application : la règle tient toujours pour tout le reste.
- **Pas de remplacement de filtre :** sinon un filtre ajouté par mégarde changerait le sens de tous les templates, ou prendrait la place de `unsafe_raw`.
- **Balises fixes :** dans une liste de cent éléments, les icônes et les balises sans valeur étaient recalculées cent fois.

## Mesures
Page des notes de la démonstration (30 notes) et page d'accueil, PHP 8.5 avec OPcache, sur la machine de développement. Les chiffres varient d'un lancement à l'autre d'environ 15 %.

| | Avant | Après |
| --- | --- | --- |
| Notes : affichage seul | 4,5 ms | 2,5 ms |
| Notes : analyse des templates | 7,5 ms | 6 ms |
| Notes : taille de la page | 42 Ko | 40 Ko |
| Accueil : affichage seul | 0,7 ms | 0,1 ms |
| Accueil : analyse des templates | — | 5,5 ms |

L'affichage a nettement gagné. **L'analyse, refaite à chaque requête, reste le coût principal**, et les optimisations de l'analyseur n'y ont presque rien changé.

## Ce qui a été essayé, puis écarté
Un cache sur le disque de l'arbre analysé, écrit en JSON (des données, donc sans fichier PHP généré ni `unserialize()`, conformément aux ADR-006 et ADR-019). Mesuré : relire et reconstruire l'arbre coûte environ 3 ms contre 5,5 ms pour l'analyser. Le gain (un tiers environ) ne justifiait ni un réglage de plus (un dossier de cache), ni 250 lignes à maintenir.

## Conséquences
- Un template qui comptait sur un commentaire dans la page produite (un commentaire conditionnel pour un ancien navigateur, par exemple) ne l'obtient plus. Aucun moyen de le garder n'est fourni ; il s'ajoutera si le besoin se présente.
- Les filtres donnés au constructeur de `Kioo` continuent de fonctionner.
- **Question laissée ouverte :** pour ne plus analyser à chaque requête, la voie efficace est de traduire les templates en PHP, gardé par OPcache, comme le font Twig et Blade. L'ADR-019 l'a écarté pour une raison de sécurité : qui peut écrire dans le dossier du cache peut alors exécuter du code. Une piste concilie les deux : traduire au déploiement, par une commande, dans un dossier que le serveur web ne peut pas modifier. Elle demande la console (0.4) et une décision de l'utilisateur.
