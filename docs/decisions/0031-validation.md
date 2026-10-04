# ADR-031 : la validation des formulaires

**Statut :** acceptée

## Contexte
Jusqu'ici, chaque contrôleur vérifiait à la main ce qu'il recevait : `is_string()`, `trim()`, `mb_strlen()`. C'est répétitif, et un oubli est une faille. La démonstration et le projet de départ contiennent chacun une trentaine de lignes de ce genre.

### Analyse de menaces
Tout ce qui vient d'un formulaire est choisi par le visiteur :
- **un type inattendu** : un tableau là où l'on attend un texte (`nom[]=x`) fait échouer `trim()` ;
- **une taille démesurée** : un texte de plusieurs mégaoctets rangé en base ou réaffiché ;
- **des caractères de contrôle, de l'UTF-8 invalide** : ils abîment les journaux, les pages, les fichiers ;
- **des champs en trop** : un champ `role=admin` ajouté à la main, qu'un code naïf enregistrerait avec les autres ;
- **un nombre mal formé** : `1e9`, ` 12 `, `0x1A`, que PHP convertit à sa façon ;
- **des règles écrites en expressions régulières libres** : une expression mal écrite peut bloquer le serveur (ReDoS) ;
- **le message d'erreur lui-même** : s'il recopie la valeur reçue, il devient un moyen d'injection.

## Options envisagées
- Des règles écrites en texte, à la Laravel (`'nom' => 'required|max:80'`) : compact, mais c'est un petit langage caché dans des chaînes. L'éditeur ne le connaît pas, une faute de frappe ne se voit pas (principe 1).
- Des objets de règles à assembler (`new Required()`, `new MaxLength(80)`) : explicite, mais verbeux pour un débutant.
- **Une méthode par sorte de champ, qui vérifie et retourne la valeur dans le bon type.**

## Décision
1. Nouveau composant **`Validation`**, de la couche des fonctionnalités. Une classe : `Validator`.
2. **Une méthode par sorte de champ**, qui retourne la valeur vérifiée, dans son type :
   `text()`, `longText()`, `integer()`, `decimal()`, `email()`, `choice()`, `checkbox()`, `date()`, `password()`.
   Les règles sont des arguments nommés : `$v->text('nom', max: 80)`, `$v->integer('age', min: 18)`.
3. **Défauts sûrs** : un champ est obligatoire ; un texte fait 255 caractères au plus (5 000 pour `longText()`) ; un texte court ne contient pas de retour à la ligne. On assouplit en l'écrivant (`required: false`, `max: 2000`).
4. **Un champ qui n'est pas un texte est refusé** (tableau, fichier), comme l'UTF-8 invalide et les caractères de contrôle.
5. **Les nombres ont une forme stricte** : des chiffres, un signe éventuel. `decimal()` accepte la virgule comme le point.
6. **Une erreur est un message pour le visiteur**, en français, court, qui ne recopie jamais la valeur reçue. Il se remplace par l'argument `message:`.
7. `fails()` dit s'il y a une erreur ; `errors()` donne un message par champ ; `input()` donne ce qui a été saisi, à réafficher, **sans les mots de passe** ; `values()` ne donne que les champs vérifiés.
8. Une règle propre à l'application s'ajoute par `check($champ, $condition, $message)`. **Aucune règle ne prend une expression régulière.**
9. Le validateur ne connaît ni la requête, ni la base de données : on lui donne les données, il rend des valeurs.

## Pourquoi
- Une méthode par sorte de champ se découvre dans l'éditeur, et son type de retour évite toute conversion : `integer()` rend un `int`, pas un texte à convertir.
- `values()` ne contient que ce qui a été demandé : un champ ajouté par le visiteur n'y entre jamais. L'enregistrement en masse devient sûr sans y penser.
- Des défauts stricts appliquent le principe 8 : oublier une règle donne un formulaire trop sévère, jamais trop ouvert.

## Conséquences
- Les fichiers envoyés ne sont pas validés ici : ils demandent un stockage sûr, à traiter à part.
- Les champs imbriqués (`adresse[ville]`) et les listes (`tags[]`) ne sont pas gérés en 0.4.
- Les règles qui interrogent la base (« cette adresse est déjà prise ») s'écrivent avec `check()`, dans le contrôleur.
- Les messages sont en français. Leur traduction viendra avec l'internationalisation, hors périmètre avant la 1.0.
