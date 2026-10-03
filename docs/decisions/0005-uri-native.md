# ADR-005 : la classe Uri s'appuie sur l'extension URI native

**Statut :** acceptée — précise l'ADR-001

## Contexte
L'ADR-001 prévoit d'écrire nos propres classes PSR-7. PHP 8.5 fournit désormais `Uri\Rfc3986\Uri`, un analyseur d'URI conforme à la RFC 3986, maintenu par l'équipe de PHP.

## Options envisagées
- Réimplémenter entièrement l'analyse des URI : très pédagogique, mais long et risqué (encodage, cas limites, sécurité).
- Déléguer l'analyse à la classe native, en gardant notre propre classe PSR-7.

## Décision
`Wazi\Http\Uri` reste notre classe : elle implémente `Psr\Http\Message\UriInterface` et expose l'API PSR-7. En interne, elle délègue l'analyse et la validation à `Uri\Rfc3986\Uri`.

## Pourquoi
L'analyse d'URI est un terrain à failles de sécurité ; un moteur natif éprouvé est plus fiable qu'un parseur maison. La classe reste lisible, et elle enseigne une leçon utile : ne pas réinventer ce que la plateforme fournit déjà.

## Conséquences
L'ADR-001 reste valable pour les autres classes. Les différences de comportement entre la RFC 3986 et les exigences de PSR-7 (normalisation, encodage) sont gérées et testées dans notre classe.
