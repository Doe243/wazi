# ADR-004 : PHP 8.5 minimum

**Statut :** acceptée — remplace l'ADR-002

## Contexte
PHP 8.5 (novembre 2025) apporte une extension URI native, l'attribut `#[\NoDiscard]` et `clone()` avec modification de propriétés. Wazi n'est pas encore publié et sa version 1.0 est à plusieurs mois.

## Options envisagées
- Rester sur PHP 8.3 : compatible avec plus d'hébergeurs aujourd'hui, mais sans ces outils.
- Passer à PHP 8.5 : moins d'hébergeurs au départ, mais une API plus sûre et un code plus lisible.

## Décision
Wazi exige PHP 8.5. L'intégration continue teste PHP 8.5, et chaque nouvelle version de PHP sera ajoutée à la matrice à sa sortie.

## Pourquoi
- `#[\NoDiscard]` sur les méthodes `with...()` de PSR-7 avertit le débutant qui oublie de récupérer le nouvel objet, l'erreur la plus fréquente avec des objets immuables.
- `clone()` avec propriétés rend ces méthodes courtes et lisibles, ce qui sert la mission pédagogique.
- L'extension URI native fournit un analyseur conforme aux standards (voir ADR-005).
- Aucun utilisateur n'est encore bloqué, et PHP 8.5 sera largement disponible à la sortie de la 1.0.

## Conséquences
Certains hébergements mutualisés ne proposeront pas PHP 8.5 au début. Les fonctionnalités de PHP 8.5 peuvent être utilisées librement dans tout le framework.
