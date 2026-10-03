<?php

declare(strict_types=1);

namespace Wazi\View\Exception;

/**
 * Levée quand un template Kioo est mal écrit, ou qu'une expression ne peut pas être calculée.
 *
 * Chaque message suit la règle des erreurs pédagogiques de Wazi : ce qui s'est
 * passé, pourquoi, et comment corriger. Les messages citent ce que VOUS avez
 * écrit dans le template (un nom de variable, un opérateur), jamais la valeur
 * d'une variable : elle peut venir d'un visiteur ou être un secret (ADR-006).
 */
final class KiooException extends \RuntimeException
{
    /**
     * @param bool $missingValue vrai quand l'erreur signale une valeur absente (variable, clé, propriété) : c'est ce que « ?? » sait rattraper
     */
    private function __construct(string $message, public readonly bool $missingValue = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Situe une erreur dans son template : « Dans notes.kioo, ligne 12 : … ».
     */
    public static function at(self $error, string $template, int $line): self
    {
        return new self(sprintf('Dans le template « %s », ligne %d : %s', $template, $line, $error->getMessage()), false, $error);
    }

    // ------------------------------------------------------------------
    // Template mal écrit
    // ------------------------------------------------------------------

    public static function unterminated(string $what, string $ending): self
    {
        return new self(sprintf('%s n\'est pas terminé : il manque « %s ».', $what, $ending));
    }

    public static function emptyExpression(): self
    {
        return new self(
            'Les accolades { } sont vides : Kioo attend une expression à afficher, par exemple {titre}.'
            . ' Pour écrire une vraie accolade dans la page, faites-la précéder d\'une barre inversée : \{',
        );
    }

    public static function unexpectedClosingTag(string $name): self
    {
        return new self(sprintf(
            'La balise fermante </%s> ne ferme aucune balise ouverte. Vérifiez qu\'il ne manque pas une balise'
            . ' <%s> plus haut, ou qu\'elle n\'a pas déjà été fermée.',
            $name,
            $name,
        ));
    }

    public static function interpolationNotAllowed(string $where, string $instead): self
    {
        return new self(sprintf(
            'Kioo refuse d\'afficher une valeur %s : à cet endroit, aucun échappement ne protège la page,'
            . ' et une valeur venue d\'un visiteur pourrait y exécuter du code. %s'
            . ' Pour écrire une vraie accolade, faites-la précéder d\'une barre inversée : \{',
            $where,
            $instead,
        ));
    }

    // ------------------------------------------------------------------
    // Structures : k:if, k:else, k:for
    // ------------------------------------------------------------------

    /**
     * @param list<string> $available
     */
    public static function unknownDirective(string $name, array $available): self
    {
        return new self(sprintf(
            '« %s » n\'existe pas dans Kioo.%s Disponibles : %s.',
            $name,
            self::suggestion($name, $available),
            implode(', ', $available),
        ));
    }

    public static function conflictingDirectives(string $element): self
    {
        return new self(sprintf(
            'La balise <%s> porte plusieurs attributs parmi k:if, k:for et k:else : Kioo ne saurait pas lequel'
            . ' appliquer d\'abord. Gardez-en un seul, et mettez l\'autre sur une balise qui l\'entoure.',
            $element,
        ));
    }

    public static function directiveNeedsClosingTag(string $element): self
    {
        return new self(sprintf(
            'La balise <%s> porte une structure de Kioo, mais n\'est jamais fermée : Kioo ne sait pas où'
            . ' la structure s\'arrête. Ajoutez la balise fermante </%s>.',
            $element,
            $element,
        ));
    }

    public static function bracesInDirective(string $directive): self
    {
        return new self(sprintf(
            'La valeur de %s contient une accolade. Dans %s, on écrit l\'expression directement,'
            . ' sans accolades : %s.',
            $directive,
            $directive,
            $directive === 'k:for' ? 'k:for="note in notes"' : 'k:if="total > 0"',
        ));
    }

    public static function invalidFor(): self
    {
        return new self(
            'La valeur de k:for est mal écrite. Elle se lit « un élément dans une liste » :'
            . ' k:for="note in notes". Pour avoir aussi le numéro ou la clé : k:for="numero, note in notes".',
        );
    }

    public static function elseWithoutIf(): self
    {
        return new self(
            'Cette balise porte k:else, mais la balise juste avant elle n\'a ni k:if ni k:for (ou a déjà son k:else).'
            . ' k:else se place sur la balise qui suit immédiatement celle du k:if ou du k:for.',
        );
    }

    public static function notIterable(string $givenType): self
    {
        return new self(sprintf(
            'k:for ne peut parcourir qu\'une liste, et a reçu une valeur de type %s. Vérifiez la variable'
            . ' donnée au template ; pour prévoir son absence : k:for="note in notes ?? vide".',
            $givenType,
        ));
    }

    // ------------------------------------------------------------------
    // Mise en page et inclusions
    // ------------------------------------------------------------------

    public static function missingAttribute(string $element, string $attribute): self
    {
        return new self(sprintf(
            'La balise <%s> a besoin de l\'attribut « %s ». Exemple : <%s %s="%s">.',
            $element,
            $attribute,
            $element,
            $attribute,
            $attribute === 'file' ? 'partiels/pied' : 'titre',
        ));
    }

    /**
     * Sécurité (ADR-019) : voir TemplateParser::assertKiooElement().
     */
    public static function dynamicTemplateName(string $element, string $attribute): self
    {
        return new self(sprintf(
            'Dans <%s>, l\'attribut « %s » doit être écrit en toutes lettres, sans accolades. Si une valeur'
            . ' pouvait choisir le template à lire, un visiteur pourrait faire afficher un fichier imprévu.'
            . ' Pour choisir entre deux templates, utilisez k:if sur deux balises <%s>.',
            $element,
            $attribute,
            $element,
        ));
    }

    public static function invalidIncludeVariable(string $name): self
    {
        return new self(sprintf(
            'Dans <k:include>, « %s » ne peut pas servir de nom de variable. Chaque attribut (sauf file) devient'
            . ' une variable du template inclus : son nom ne contient que des lettres, des chiffres et « _ ».',
            $name,
        ));
    }

    public static function layoutMustComeFirst(): self
    {
        return new self(
            'La balise <k:layout> doit être la toute première du template, avant tout texte et toute autre balise :'
            . ' elle dit dans quelle mise en page le reste du fichier vient se placer.',
        );
    }

    public static function nestedBlock(): self
    {
        return new self(
            'Dans un template qui utilise <k:layout>, une balise <k:block> remplit un emplacement de la mise en'
            . ' page : elle se place au premier niveau du fichier, pas à l\'intérieur d\'une autre balise.',
        );
    }

    public static function reservedBlock(): self
    {
        return new self(
            'Le bloc « content » ne se déclare pas dans une page : il désigne tout ce qui, dans la page, n\'est pas'
            . ' dans un <k:block>. Retirez la balise <k:block name="content"> et gardez son contenu.',
        );
    }

    public static function nestedLayout(string $layout): self
    {
        return new self(sprintf(
            'La mise en page « %s » utilise elle-même <k:layout>. Kioo ne gère qu\'un niveau de mise en page pour'
            . ' l\'instant : retirez <k:layout> de ce fichier.',
            $layout,
        ));
    }

    public static function includeTooDeep(int $limit): self
    {
        return new self(sprintf(
            'Plus de %d templates s\'incluent les uns dans les autres : il y a sans doute une boucle (un template'
            . ' qui s\'inclut lui-même, directement ou par un autre). Vérifiez vos balises <k:include>.',
            $limit,
        ));
    }

    /**
     * Sécurité (ADR-006) : le nom refusé n'est pas recopié dans le message.
     */
    public static function invalidTemplateName(): self
    {
        return new self(
            'Ce nom de template est invalide. Un nom se compose de lettres, de chiffres, de « - » et de « _ »,'
            . ' avec des « / » pour les sous-dossiers, sans extension ni « .. » : « accueil », « notes/liste ».'
            . ' Le fichier correspondant est cherché dans le dossier des vues, avec l\'extension .kioo.',
        );
    }

    public static function templateNotFound(string $name): self
    {
        return new self(sprintf(
            'Le template « %s » est introuvable : le fichier %s.kioo n\'existe pas dans le dossier des vues.'
            . ' Vérifiez son nom et son dossier.',
            $name,
            $name,
        ));
    }

    public static function noViewsDirectory(): self
    {
        return new self(
            'Ce moteur Kioo n\'a pas de dossier de vues : il ne peut pas lire de fichier. Indiquez-le à la création :'
            . ' new Kioo(__DIR__ . \'/../views\').',
        );
    }

    public static function viewsDirectoryNotFound(): self
    {
        return new self(
            'Le dossier de vues donné à Kioo n\'existe pas. Créez-le, ou corrigez son chemin :'
            . ' new Kioo(__DIR__ . \'/../views\').',
        );
    }

    // ------------------------------------------------------------------
    // Valeur qui ne peut pas être affichée
    // ------------------------------------------------------------------

    public static function notDisplayable(string $givenType): self
    {
        return new self(sprintf(
            'Cette expression donne une valeur de type %s, que Kioo ne sait pas afficher. Il affiche un texte'
            . ' ou un nombre. Pour un vrai/faux, écrivez {condition ? \'oui\' : \'non\'} ; pour une liste,'
            . ' utilisez k:for ou le filtre join ; pour un objet, affichez une de ses propriétés.',
            $givenType,
        ));
    }

    public static function rawHtmlInAttribute(string $attribute): self
    {
        return new self(sprintf(
            'Le filtre « unsafe_raw » ne peut pas servir dans l\'attribut « %s » : une valeur non échappée'
            . ' y fermerait le guillemet et ajouterait ses propres attributs. Il ne s\'utilise que dans le'
            . ' texte de la page, entre deux balises.',
            $attribute,
        ));
    }

    // ------------------------------------------------------------------
    // Expression mal écrite
    // ------------------------------------------------------------------

    public static function unexpectedCharacter(string $expression, int $position): self
    {
        return new self(sprintf(
            'L\'expression « %s » contient un caractère que Kioo ne comprend pas, à la position %d.'
            . ' Une expression s\'écrit avec des noms de variables, des nombres, des textes entre guillemets'
            . ' et les opérateurs + - * / %% == != < > <= >= and or not ? : ?? |',
            $expression,
            $position + 1,
        ));
    }

    public static function unterminatedString(string $expression, int $position): self
    {
        return new self(sprintf(
            'Dans l\'expression « %s », le texte ouvert à la position %d n\'est pas refermé :'
            . ' il manque le guillemet de fin.',
            $expression,
            $position + 1,
        ));
    }

    public static function unexpectedToken(string $expression, int $position, string $found, string $expected): self
    {
        return new self(sprintf(
            'L\'expression « %s » est mal écrite à la position %d : Kioo attendait %s, et a trouvé %s.',
            $expression,
            $position + 1,
            $expected,
            $found,
        ));
    }

    public static function operatorAfterFilter(string $expression, string $filter, int $position): self
    {
        return new self(sprintf(
            'Dans l\'expression « %s », un opérateur suit le filtre « %s » (position %d). Un filtre s\'applique à'
            . ' tout ce qui est écrit à sa gauche, et termine l\'expression. Pour continuer le calcul après lui,'
            . ' entourez-le de parenthèses : (valeur | %s) > 1.',
            $expression,
            $filter,
            $position + 1,
            $filter,
        ));
    }

    public static function chainedComparison(string $expression, int $position): self
    {
        return new self(sprintf(
            'Dans l\'expression « %s », deux comparaisons se suivent (position %d). Kioo ne sait pas lire'
            . ' « a < b < c » : écrivez « a < b and b < c ».',
            $expression,
            $position + 1,
        ));
    }

    // ------------------------------------------------------------------
    // Valeur absente
    // ------------------------------------------------------------------

    /**
     * @param list<string> $available les noms qui existent, pour proposer le plus proche
     */
    public static function undefinedVariable(string $name, array $available): self
    {
        return new self(sprintf(
            'La variable « %s » n\'existe pas dans ce template.%s Vérifiez son nom, et qu\'elle est bien donnée'
            . ' au template. Pour prévoir son absence, écrivez {%s ?? \'valeur par défaut\'}.',
            $name,
            self::suggestion($name, $available),
            $name,
        ), true);
    }

    /**
     * @param list<string> $available
     */
    public static function undefinedProperty(string $name, string $ownerType, array $available): self
    {
        return new self(sprintf(
            '« %s » n\'existe pas ici : la valeur de gauche (%s) n\'a ni clé ni propriété publique de ce nom.%s'
            . ' Pour appeler une méthode, ajoutez des parenthèses : .%s()',
            $name,
            $ownerType,
            self::suggestion($name, $available),
            $name,
        ), true);
    }

    public static function undefinedIndex(string $ownerType): self
    {
        return new self(sprintf(
            'L\'élément demandé entre crochets n\'existe pas dans cette valeur (%s). Vérifiez la clé,'
            . ' ou prévoyez son absence avec « ?? ».',
            $ownerType,
        ), true);
    }

    public static function accessOnNull(string $name): self
    {
        return new self(sprintf(
            'Impossible de lire « %s » : la valeur de gauche vaut null (elle est vide). Vérifiez qu\'elle est bien'
            . ' remplie, ou prévoyez ce cas avec « ?? ».',
            $name,
        ), true);
    }

    // ------------------------------------------------------------------
    // Valeur du mauvais type
    // ------------------------------------------------------------------

    public static function notAccessible(string $name, string $givenType): self
    {
        return new self(sprintf(
            'Impossible de lire « %s » : la valeur de gauche est de type %s. Le point et les crochets ne'
            . ' s\'utilisent que sur un tableau ou un objet.',
            $name,
            $givenType,
        ));
    }

    /**
     * @param list<string> $available
     */
    public static function undefinedMethod(string $name, string $ownerType, array $available): self
    {
        return new self(sprintf(
            'La méthode « %s() » ne peut pas être appelée sur cette valeur (%s) : elle n\'existe pas, ou n\'est pas'
            . ' publique.%s Un template ne peut appeler que les méthodes publiques des objets qu\'on lui donne.',
            $name,
            $ownerType,
            self::suggestion($name, $available),
        ));
    }

    public static function numberExpected(string $operator, string $givenType): self
    {
        return new self(sprintf(
            'L\'opérateur « %s » ne calcule qu\'avec des nombres, et a reçu une valeur de type %s.'
            . ' Kioo ne transforme pas un texte en nombre tout seul : donnez un nombre au template.',
            $operator,
            $givenType,
        ));
    }

    public static function divisionByZero(): self
    {
        return new self(
            'Division par zéro dans une expression. Vérifiez le diviseur, par exemple avec'
            . ' {total > 0 ? somme / total : 0}.',
        );
    }

    public static function notComparable(string $operator, string $leftType, string $rightType): self
    {
        return new self(sprintf(
            'L\'opérateur « %s » ne peut pas comparer une valeur de type %s avec une valeur de type %s.'
            . ' Il compare deux nombres, ou deux textes. Attention : le nombre 3 et le texte \'3\' sont'
            . ' deux valeurs différentes.',
            $operator,
            $leftType,
            $rightType,
        ));
    }

    // ------------------------------------------------------------------
    // Filtres
    // ------------------------------------------------------------------

    /**
     * @param list<string> $available
     */
    public static function unknownFilter(string $name, array $available): self
    {
        sort($available);

        return new self(sprintf(
            'Le filtre « %s » n\'existe pas.%s Filtres disponibles : %s.',
            $name,
            self::suggestion($name, $available),
            implode(', ', $available),
        ));
    }

    public static function filterExpects(string $filter, string $expected, string $givenType): self
    {
        return new self(sprintf(
            'Le filtre « %s » attend %s, et a reçu une valeur de type %s.',
            $filter,
            $expected,
            $givenType,
        ));
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * Propose le nom existant le plus proche de celui qui a été écrit :
     * la plupart des erreurs sont des fautes de frappe.
     *
     * @param list<string> $available
     */
    private static function suggestion(string $name, array $available): string
    {
        $closest = null;
        $shortest = 3;

        foreach ($available as $candidate) {
            // levenshtein() compte les lettres à changer pour passer d'un mot à l'autre.
            $distance = levenshtein(strtolower($name), strtolower($candidate));

            if ($distance < $shortest) {
                $closest = $candidate;
                $shortest = $distance;
            }
        }

        return $closest === null ? '' : sprintf(' Vouliez-vous écrire « %s » ?', $closest);
    }
}
