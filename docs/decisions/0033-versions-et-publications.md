# ADR-033 : versions et publications

**Statut :** acceptée

## Contexte
Jusqu'ici, le travail avançait commit après commit sur `main`, avec un tag posé de temps en temps (`0.1.0`, `0.2.0`, `0.3.0`). Rien ne disait ce qu'un numéro de version promet, ni quand une version est terminée. Résultat : le contenu d'une version grossit au fil des idées, un correctif part sans numéro, et le projet de départ dépend d'un code qui n'a pas encore de version (demande de l'utilisateur, 2026-10-04 : « sinon on va je ne sais où »).

## Options envisagées
- Continuer sans règle : simple, mais personne ne sait où en est le projet.
- Une version par date (`2026.10`) : dit quand, pas ce qui change pour celui qui met à jour.
- **Le versionnage sémantique (SemVer)**, que Composer comprend et que tout l'écosystème PHP utilise.

## Décision
1. **Un numéro a trois parties : `MAJEUR.MINEUR.CORRECTIF`.**
   - **Correctif** (`0.4.0` → `0.4.1`) : uniquement des corrections de bogues et de sécurité. Rien de nouveau, rien de cassé. Mettre à jour est toujours sans risque.
   - **Mineur** (`0.4` → `0.5`) : des fonctionnalités nouvelles.
   - **Majeur** (`1` → `2`) : un changement qui oblige à modifier le code des projets.
2. **Avant la 1.0, une version mineure peut encore changer l'API.** C'est la règle de SemVer pour les versions `0.x`. Chaque changement de ce genre est écrit dans le journal des modifications, sous « À modifier dans votre projet ».
3. **À partir de la 1.0, l'API publique est une promesse** : elle ne change qu'avec une version majeure. L'API publique est ce que décrit le guide ; ce qui est marqué `@internal` n'en fait pas partie.
4. **Une version mineure a un contenu fermé**, écrit dans la feuille de route avant de commencer. Une idée qui arrive en cours de route va dans la version suivante, sauf décision explicite.
5. **Une version est publiée quand :**
   - son contenu est fait et son critère de sortie est atteint ;
   - `composer check` passe, et la CI est verte sur les deux dépôts ;
   - le journal des modifications (`CHANGELOG.md`) est à jour ;
   - le guide décrit ce qui a changé.
6. **Publier, c'est :** dater la version dans `CHANGELOG.md`, poser le tag (`0.4.0`, sans « v ») sur `main`, le pousser, puis aligner le projet de départ.
7. **Un bogue trouvé après un tag** donne une version corrective (`0.4.1`), pas un commit anonyme. Tant que `main` ne contient que des corrections depuis le tag, on tague `main`. Sinon, on corrige sur une branche `0.4` partie du tag.
8. **Le projet de départ suit le framework** : il porte le même numéro mineur, et demande `wazi/framework: ^0.4`. Avant la 1.0, `^0.4` veut dire « 0.4.x seulement » : un projet ne passe pas à la 0.5 sans le décider.
9. **`CHANGELOG.md`**, à la racine, suit le format « Keep a Changelog » : pour chaque version, ce qui est ajouté, modifié, corrigé, et ce qui touche à la sécurité. Il se remplit au fil du travail, dans la section « À venir ».

## Pourquoi
- Un numéro qui dit ce qu'il promet permet de mettre à jour sans crainte : c'est une forme de transparence (principe 1).
- Un contenu fermé par version donne une fin à chaque étape.
- Composer applique déjà ces règles : `^0.4` et `^1.2` se comportent exactement comme décrit ici.

## Conséquences
- Une seule branche, `main`, tant qu'aucune version corrective n'est nécessaire sur une version ancienne.
- Seule la dernière version mineure reçoit des corrections avant la 1.0.
- Le correctif des sessions sous Windows et celui du chemin SQLite, faits après la 0.3.0, sortent avec la 0.4.0 : la règle 7 s'applique à partir de maintenant.
- Une version publiée ne se modifie plus : un tag n'est jamais déplacé.
