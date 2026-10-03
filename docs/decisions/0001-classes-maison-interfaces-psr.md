# ADR-001 : classes maison, interfaces PSR

**Statut :** acceptée

## Contexte
Le framework a besoin de Request, Response, d'un conteneur et d'un pipeline de middlewares. Des bibliothèques robustes existent (par exemple nyholm/psr7).

## Décision
Nous écrivons nos propres implémentations, qui respectent les interfaces PSR : `psr/http-message` (PSR-7), `psr/http-factory` (PSR-17), `psr/container` (PSR-11), `psr/http-server-handler` et `psr/http-server-middleware` (PSR-15). Seuls ces paquets d'interfaces sont des dépendances.

## Pourquoi
Le code devient entièrement lisible et maîtrisé, ce qui sert directement la mission pédagogique. Les interfaces PSR gardent la porte ouverte aux outils de l'écosystème.

## Conséquences
Plus de travail et plus de risques de bugs : chaque classe exige une couverture de tests complète, en particulier l'immutabilité des messages PSR-7. Une implémentation trop coûteuse pourra être remplacée par un nouvel ADR sans casser l'API.
