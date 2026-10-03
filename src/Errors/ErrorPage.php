<?php

declare(strict_types=1);

namespace Wazi\Errors;

/**
 * Fabrique le HTML d'une page d'erreur.
 *
 * Sécurité (ADR-006) : cette classe ne reçoit que des textes et les échappe
 * TOUS avant de les écrire dans la page. Un message d'erreur peut contenir ce
 * qu'un visiteur a envoyé ; affiché tel quel, « <script>... » s'exécuterait
 * dans le navigateur (faille dite « XSS »).
 */
final class ErrorPage
{
    /**
     * La feuille de style, intégrée à la page. ErrorHandler en calcule
     * l'empreinte pour que le navigateur n'accepte que celle-ci.
     *
     * Les couleurs sont celles de l'identité de Wazi (docs/brand/). La page
     * ne porte en revanche ni logo ni nom : annoncer le framework utilisé
     * renseignerait quelqu'un qui cherche une faille. Les polices sont celles
     * du système, car la page n'a le droit de charger aucune ressource.
     */
    public const string STYLE = 'body{font-family:system-ui,sans-serif;max-width:42rem;margin:4rem auto;padding:0 1.25rem;'
        . 'line-height:1.55;color:#0D2B30;background:#F3F8F8}h1{font-size:1.5rem;letter-spacing:-.01em}'
        . 'dl{background:#fff;border:1px solid #C5DAD9;border-radius:1rem;padding:1.25rem}'
        . 'dt{font-weight:600;margin-top:.75rem;color:#0B6E70}dt:first-child{margin-top:0}'
        . 'dd{margin:.15rem 0 0;overflow-wrap:anywhere}'
        . '.note{color:#3F5B5F;font-size:.9rem}';

    /**
     * @param array<string, string> $details libellé => valeur, affichés sous le texte (vide : rien)
     */
    public function render(int $statusCode, string $title, string $text, array $details = [], string $note = ''): string
    {
        $rows = '';

        foreach ($details as $label => $value) {
            $rows .= '<dt>' . self::escape($label) . '</dt><dd>' . self::escape($value) . '</dd>';
        }

        return '<!DOCTYPE html>'
            . '<html lang="fr"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>' . self::escape($statusCode . ' — ' . $title) . '</title>'
            . '<style>' . self::STYLE . '</style></head><body>'
            . '<h1>' . self::escape($statusCode . ' — ' . $title) . '</h1>'
            . '<p>' . self::escape($text) . '</p>'
            . ($rows !== '' ? '<dl>' . $rows . '</dl>' : '')
            . ($note !== '' ? '<p class="note">' . self::escape($note) . '</p>' : '')
            . '</body></html>';
    }

    /**
     * ENT_QUOTES échappe aussi les guillemets ; ENT_SUBSTITUTE remplace les
     * octets invalides au lieu de retourner une chaîne vide.
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
