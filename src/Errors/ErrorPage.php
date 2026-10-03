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
     */
    public const string STYLE = 'body{font-family:system-ui,sans-serif;max-width:42rem;margin:4rem auto;padding:0 1rem;'
        . 'line-height:1.5;color:#1c1c1c;background:#fafafa}h1{font-size:1.5rem}'
        . 'dl{background:#fff;border:1px solid #ddd;border-radius:.5rem;padding:1rem}'
        . 'dt{font-weight:600;margin-top:.5rem}dd{margin:0;overflow-wrap:anywhere}'
        . '.note{color:#555;font-size:.9rem}';

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
