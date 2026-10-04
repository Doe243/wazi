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
     * Les couleurs sont celles de l'identité de Wazi (docs/brand/), en clair
     * comme en sombre : la page suit le réglage du visiteur. Elle ne porte en
     * revanche ni logo ni nom : annoncer le framework utilisé renseignerait
     * quelqu'un qui cherche une faille. Les polices sont celles du système,
     * et les halos du fond sont dessinés en CSS : la page n'a le droit de
     * charger aucune ressource, pas même une image.
     */
    public const string STYLE
        // Les couleurs, nommées une fois ; le thème sombre ne change qu'elles.
        = ':root{color-scheme:light dark;--fond:#F3F8F8;--carte:#fff;--texte:#0D2B30;--second:#3F5B5F;--trait:#C5DAD9;'
        . '--accent:#0B6E70;--buee:#D6EEEC;--halo:rgba(18,163,160,.16);--ombre:rgba(13,43,48,.28)}'
        . '@media(prefers-color-scheme:dark){:root{--fond:#071C20;--carte:#0D2B30;--texte:#F3F8F8;--second:#A9C4C3;'
        . '--trait:#1F4A50;--accent:#5FD6D2;--buee:#12393F;--halo:rgba(95,214,210,.13);--ombre:rgba(0,0,0,.6)}}'
        . '*{box-sizing:border-box}'
        // La page : une carte au centre, deux halos de lumière derrière.
        . 'body{margin:0;min-height:100vh;display:grid;place-items:center;padding:1.5rem;'
        . 'font:400 1rem/1.6 system-ui,sans-serif;color:var(--texte);'
        . 'background:radial-gradient(60rem 30rem at 85% -10%,var(--halo),transparent 70%),'
        . 'radial-gradient(40rem 26rem at -10% 110%,var(--halo),transparent 70%),var(--fond)}'
        . 'main{width:100%;max-width:44rem;background:var(--carte);border:1px solid var(--trait);border-radius:1.25rem;'
        . 'padding:clamp(1.5rem,5vw,2.75rem);box-shadow:0 18px 48px -24px var(--ombre)}'
        // Le code de l'erreur, en pastille ; puis le titre et le texte.
        . '.code{display:inline-block;margin:0 0 1rem;padding:.45rem .8rem;border-radius:999px;background:var(--buee);'
        . 'color:var(--accent);font:600 .8125rem/1 ui-monospace,monospace;letter-spacing:.08em}'
        . 'h1{margin:0 0 .5rem;font-size:clamp(1.5rem,4vw,2rem);line-height:1.2;letter-spacing:-.02em}'
        . 'p{margin:0;max-width:60ch;color:var(--second)}'
        // Les détails : un libellé discret, puis sa valeur.
        . 'dl{margin:1.75rem 0 0;border-top:1px solid var(--trait)}'
        . 'dt{margin-top:1rem;color:var(--accent);font-size:.75rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase}'
        . 'dd{margin:.25rem 0 0;padding-bottom:1rem;border-bottom:1px solid var(--trait);overflow-wrap:anywhere}'
        . 'dd:last-child{padding-bottom:0;border-bottom:0}'
        // Après le message : un type, un fichier, une référence. Ils se lisent mieux à chasse fixe.
        . 'dd~dd{font:400 .875rem/1.6 ui-monospace,monospace}'
        . '.note{margin-top:1.5rem;font-size:.875rem}';

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
            . '<style>' . self::STYLE . '</style></head><body><main>'
            . '<p class="code">' . self::escape('Erreur ' . $statusCode) . '</p>'
            . '<h1>' . self::escape($title) . '</h1>'
            . '<p>' . self::escape($text) . '</p>'
            . ($rows !== '' ? '<dl>' . $rows . '</dl>' : '')
            . ($note !== '' ? '<p class="note">' . self::escape($note) . '</p>' : '')
            . '</main></body></html>';
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
