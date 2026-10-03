<?php

declare(strict_types=1);

namespace Wazi\View;

/**
 * Rend une valeur inoffensive avant de l'écrire dans une page HTML.
 *
 * Le danger : si un visiteur a saisi « <script>... » comme prénom et qu'on
 * l'écrit tel quel dans la page, le navigateur l'exécute (faille « XSS »).
 * Échapper, c'est remplacer les caractères qui ont un sens en HTML par leur
 * écriture inoffensive : « < » devient « &lt; », et le navigateur AFFICHE
 * alors le texte au lieu de l'exécuter.
 *
 * L'échappement dépend de l'endroit où la valeur est écrite :
 *   - dans le texte ou dans un attribut entre guillemets : html() ;
 *   - dans une adresse (href, src...) : il faut en plus vérifier le protocole,
 *     car « javascript:alert(1) » ne contient aucun caractère spécial et
 *     s'exécute quand même. Voir isSafeUrl().
 */
final class Escaper
{
    /** Les attributs dont la valeur est une adresse que le navigateur peut ouvrir ou charger. */
    private const array URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'poster', 'cite', 'background', 'ping', 'manifest', 'data', 'xlink:href', 'srcset', 'imagesrcset'];

    /** Les seuls protocoles acceptés dans une adresse remplie par une valeur. */
    private const array SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * ENT_QUOTES échappe aussi les deux sortes de guillemets : la valeur ne
     * peut pas refermer l'attribut qui la contient. ENT_SUBSTITUTE remplace
     * les octets invalides au lieu de retourner une chaîne vide.
     */
    public static function html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public static function isUrlAttribute(string $name): bool
    {
        return in_array(strtolower($name), self::URL_ATTRIBUTES, true);
    }

    /**
     * Vrai si l'adresse est relative (« /notes/3 », « page.html », « #haut »)
     * ou utilise un protocole sûr. Faux pour « javascript: », « data: », « vbscript: »...
     */
    public static function isSafeUrl(string $url): bool
    {
        // Les navigateurs ignorent les espaces, tabulations et retours à la
        // ligne glissés dans un protocole : « java\tscript: » s'exécute. On les
        // retire donc avant de regarder.
        $compact = preg_replace('/[\x00-\x20\x7F]+/', '', $url) ?? '';

        // Un protocole, c'est ce qui précède le premier « : », à condition
        // qu'aucun « / », « ? » ou « # » ne vienne avant.
        if (preg_match('/^([^:\/?#]*):/', $compact, $match) !== 1) {
            return true;
        }

        return in_array(strtolower($match[1]), self::SAFE_SCHEMES, true);
    }
}
