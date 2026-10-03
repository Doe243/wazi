# ADR-007 : le code commun des messages PSR-7 est partagé par des traits

**Statut :** acceptée

## Contexte
`Request`, `ServerRequest` et `Response` ont en commun les en-têtes, le corps et la version du protocole (onze méthodes de `MessageInterface`). `Request` et `ServerRequest` partagent en plus la méthode, l'URI et la cible. Nos classes sont `final` (conventions de code) : elles ne peuvent pas hériter les unes des autres. La validation des en-têtes est un point de sécurité (injection d'en-têtes, ADR-006) : elle ne doit exister qu'à un seul endroit.

## Options envisagées
- Dupliquer le code dans les trois classes : tout se lit dans un seul fichier, mais la validation de sécurité existerait en trois exemplaires qui finiraient par diverger.
- Une classe abstraite `Message` : habituel, mais contraire à la règle « classes `final` », et `ServerRequest` devrait hériter de `Request`, ce qui ouvre l'héritage à tout le monde.
- Un objet `Headers` composé dans chaque message : la validation est centralisée, mais chaque classe doit quand même réécrire les onze méthodes pour lui déléguer.
- Deux traits internes : `MessageTrait` et `RequestTrait`.

## Décision
Le code commun vit dans deux traits marqués `@internal`, `Wazi\Http\MessageTrait` et `Wazi\Http\RequestTrait` (qui utilise le premier). Les trois classes restent `final readonly` et écrivent elles-mêmes leur constructeur.

## Pourquoi
- Un seul exemplaire de la validation des en-têtes, de la méthode et de la version du protocole.
- Un trait n'est pas de la magie : l'IDE y mène par un clic, et son contenu se lit comme s'il était écrit dans la classe (principe 1). La docblock de chaque classe dit d'où vient son comportement.
- Les classes restent `final` : personne ne peut hériter d'un message pour en contourner les règles.

## Conséquences
Pour lire une classe de message en entier, il faut ouvrir jusqu'à trois fichiers. Les traits ne font pas partie de l'API publique : ils peuvent changer sans préavis. Les propriétés y sont déclarées `readonly` une à une, car une classe `readonly` n'accepte que des traits dont les propriétés le sont. Les traits restent réservés à ce cas (code strictement identique entre classes `final`) ; tout nouvel usage se discute.
