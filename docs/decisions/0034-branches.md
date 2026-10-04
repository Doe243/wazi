# ADR-034 : `main` et les branches de travail

**Statut :** acceptée (remplace le point « une seule branche » des conséquences de l'ADR-033)

## Contexte
Depuis le début, chaque changement est commité directement sur `main`. C'est une mauvaise pratique (demande de l'utilisateur, 2026-10-04) : un commit cassé y arrive avant que la CI l'ait vu, rien n'est relu avant d'entrer, et `main` ne dit jamais « ceci fonctionne ». La CI de la base de données l'a montré : une faille que seul Linux révélait est restée un moment sur `main`.

## Options envisagées
- Continuer sur `main` : rapide, mais `main` peut être cassée à tout moment.
- Git Flow (`main`, `develop`, branches de version, de correctif...) : pensé pour de grandes équipes, trop de branches à comprendre.
- **`main` + des branches de travail courtes, fusionnées par une demande de fusion** (ce que GitHub appelle « GitHub Flow »).

## Décision
1. **`main` fonctionne toujours.** On n'y commite plus directement. Elle ne reçoit que des fusions de branches dont la CI est verte.
2. **Tout travail se fait sur une branche**, partie de `main`, qui porte un nom parlant :
   - `fonction/…` pour une fonctionnalité : `fonction/barre-de-debogage` ;
   - `correctif/…` pour une correction : `correctif/session-windows` ;
   - `docs/…` pour la documentation seule ;
   - `publication/…` pour préparer une version : `publication/0.4.0`.
3. **Une branche = un sujet.** Elle reste courte : quelques jours au plus, puis elle est fusionnée et supprimée.
4. **Une branche entre dans `main` par une demande de fusion** (*pull request*) sur GitHub. La CI s'exécute sur la demande ; on ne fusionne que si elle est verte.
5. **La fusion écrase la branche en un commit** (« Squash and merge ») : `main` garde un commit par sujet, lisible comme un journal.
6. **Les tags se posent sur `main`**, après la fusion de la branche de publication (ADR-033).
7. **Une version corrective sur une version ancienne** part d'une branche de maintenance nommée d'après la version mineure (`0.4`), créée depuis le tag, seulement le jour où il le faut.
8. Les deux dépôts, `wazi/framework` et `wazi/skeleton`, suivent ces règles.

## Pourquoi
- Avec une branche, la CI juge un changement **avant** qu'il n'entre : `main` reste une base sûre pour tout le monde, à tout moment.
- Une demande de fusion est le moment de relire : c'est là qu'un changement s'explique et se discute (principe 1, la transparence, appliqué au travail lui-même).
- Deux sortes de branches seulement (`main`, et celles de travail) : le modèle tient en une phrase.

## Conséquences
- Un peu plus de gestes pour chaque changement : créer la branche, ouvrir la demande, fusionner.
- La CI ne s'exécute plus à chaque envoi d'une branche, mais sur les demandes de fusion et sur `main`.
- Sur GitHub, la protection de `main` (refuser un envoi direct, exiger une CI verte) rend ces règles automatiques ; elle demande un dépôt public ou une offre payante. Sans elle, la règle tient par discipline.
