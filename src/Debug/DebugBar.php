<?php

declare(strict_types=1);

namespace Wazi\Debug;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Wazi\Http\HttpFactory;

/**
 * La barre de débogage : en bas de vos pages, pendant que vous développez,
 * elle dit quelle route a répondu, par quel code, en combien de temps.
 *
 *     ┌──────────────────────────────────────────────────────────────────┐
 *     │ W  GET /notes · 200   Route NoteController::liste   12 ms   ...  │
 *     └──────────────────────────────────────────────────────────────────┘
 *
 * Elle est ajoutée à la fin de la page HTML que le site allait répondre de
 * toute façon. Elle n'a pas d'adresse à elle, ne garde rien d'une requête à
 * l'autre, et ne fait que montrer : aucun bouton n'agit sur l'application.
 *
 * Sécurité (ADR-035). Une barre de débogage montrée à un visiteur lui livre
 * le plan du site. Elle n'est donc écrite que si TOUTES ces conditions sont
 * réunies, vérifiées par refusal() à chaque requête :
 *   - le noyau est en mode développement (c'est lui qui crée cet objet) ;
 *   - la connexion vient de cette machine (127.0.0.1 ou ::1) ;
 *   - la requête ne porte aucun en-tête de proxy : derrière un proxy installé
 *     sur la même machine, TOUTES les requêtes semblent locales ;
 *   - le site est demandé sous un nom local (localhost, 127.0.0.1...).
 *
 * Et elle ne reçoit que des textes sans secret (voir Panel), qu'elle échappe tous.
 */
final readonly class DebugBar
{
    /** Les en-têtes qu'ajoute un proxy. Un seul suffit à écarter la barre. */
    private const array PROXY_HEADERS = [
        'Forwarded',
        'Via',
        'X-Forwarded-For',
        'X-Forwarded-Host',
        'X-Forwarded-Proto',
        'X-Forwarded-Port',
        'X-Real-IP',
        'X-Client-IP',
        'CF-Connecting-IP',
        'True-Client-IP',
    ];

    /** Au-delà, la page n'est pas relue pour y ajouter la barre. */
    private const int MAX_BODY = 2_097_152;

    /**
     * Les styles de la barre. Tout est préfixé « wz- » et remis à zéro
     * (all: revert) : les styles du site ne débordent pas sur la barre, ni l'inverse.
     */
    private const string STYLE
        // Le dessin du signe (svg, path) échappe à la remise à zéro : elle effacerait son tracé.
        // :where() ne pèse rien : les règles qui suivent gardent le dernier mot.
        = '#wz-barre,#wz-barre :where(:not(svg,path)){all:revert;box-sizing:border-box}'
        . '#wz-barre{position:fixed;left:0;right:0;bottom:0;z-index:2147483000;display:flex;flex-wrap:wrap;align-items:stretch;'
        . 'background:#0D2B30;color:#F3F8F8;border-top:1px solid #1F4A50;font:400 13px/1.4 system-ui,sans-serif}'
        . '#wz-barre .wz-signe{display:flex;align-items:center;padding:0 12px;border-right:1px solid #1F4A50}'
        . '#wz-barre .wz-signe svg{width:26px;height:19px;display:block}'
        . '#wz-barre .wz-signe path{fill:none;stroke:#F3F8F8;stroke-width:9;stroke-linecap:round;stroke-linejoin:round}'
        . '#wz-barre .wz-signe path+path{stroke:#5FD6D2;opacity:.85}'
        . '#wz-barre details{position:static}'
        . '#wz-barre summary{display:flex;align-items:center;gap:8px;padding:9px 14px;cursor:pointer;list-style:none;'
        . 'border-right:1px solid #1F4A50;white-space:nowrap}'
        . '#wz-barre summary::-webkit-details-marker{display:none}'
        . '#wz-barre summary:hover,#wz-barre details[open]>summary{background:#12393F}'
        . '#wz-barre summary:focus-visible{outline:2px solid #5FD6D2;outline-offset:-2px}'
        . '#wz-barre .wz-nom{color:#A9C4C3;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase}'
        . '#wz-barre .wz-resume{color:#F3F8F8;font:600 12px/1.4 ui-monospace,monospace}'
        . '#wz-barre .wz-alerte .wz-resume{color:#F2C879}'
        // La rubrique ouverte se déplie au-dessus de la barre, sur toute la largeur.
        . '#wz-barre .wz-detail{position:absolute;left:0;right:0;bottom:100%;max-height:60vh;overflow:auto;margin:0;'
        . 'padding:14px 18px;background:#071C20;border-top:1px solid #1F4A50;display:grid;'
        . 'grid-template-columns:minmax(120px,max-content) 1fr;gap:6px 18px}'
        . '#wz-barre dt{color:#5FD6D2;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;padding-top:2px}'
        . '#wz-barre dd{margin:0;font:400 12px/1.5 ui-monospace,monospace;overflow-wrap:anywhere;white-space:pre-wrap}';

    /**
     * Pourquoi la barre ne doit PAS être écrite pour cette requête, ou null si elle peut l'être.
     *
     * @return string|null la raison du refus, pour vos tests et vos diagnostics
     */
    public static function refusal(ServerRequestInterface $request): ?string
    {
        // L'adresse de celui qui a ouvert la connexion. Sécurité : jamais une
        // adresse annoncée par un en-tête, qu'un visiteur écrit comme il veut.
        $peer = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        if (!is_string($peer) || !self::isLoopback($peer)) {
            return 'la connexion ne vient pas de cette machine';
        }

        foreach (self::PROXY_HEADERS as $header) {
            if ($request->hasHeader($header)) {
                return 'la requête porte un en-tête de proxy (' . $header . ') : derrière un proxy, toutes les requêtes semblent locales';
            }
        }

        $host = strtolower($request->getUri()->getHost());

        if ($host !== 'localhost' && !str_ends_with($host, '.localhost') && !self::isLoopback(trim($host, '[]'))) {
            return 'le site n\'est pas demandé sous un nom local (localhost, 127.0.0.1)';
        }

        return null;
    }

    /**
     * Ajoute la barre à la fin d'une page HTML. Toute autre réponse (JSON,
     * redirection, fichier) est rendue telle quelle.
     *
     * @param list<Panel> $panels les rubriques, dans l'ordre où elles s'affichent
     */
    public function inject(ResponseInterface $response, array $panels): ResponseInterface
    {
        if (!str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'text/html')) {
            return $response;
        }

        $size = $response->getBody()->getSize();

        if ($size === null || $size > self::MAX_BODY) {
            return $response;
        }

        $body = (string) $response->getBody();
        // La barre se place juste avant la fin de la page. Sans </body>, ce
        // n'est pas une page complète (un morceau de HTML) : on n'y touche pas.
        $position = strripos($body, '</body>');

        if ($position === false) {
            return $response;
        }

        $body = substr($body, 0, $position) . $this->render($panels) . substr($body, $position);

        return $response
            ->withBody(new HttpFactory()->createStream($body))
            // La taille a changé : mieux vaut ne rien annoncer qu'annoncer faux.
            ->withoutHeader('Content-Length');
    }

    /**
     * Le HTML de la barre.
     *
     * @param list<Panel> $panels
     */
    public function render(array $panels): string
    {
        $html = '<aside id="wz-barre" aria-label="Barre de débogage de Wazi, visible seulement sur votre ordinateur">'
            . '<style>' . self::STYLE . '</style>'
            . '<span class="wz-signe" title="Barre de débogage de Wazi : mode développement, sur votre ordinateur seulement">'
            . '<svg viewBox="0 0 76 56" aria-hidden="true"><path d="M6 8 L24 48 L40 14"/><path d="M36 14 L52 48 L70 8"/></svg></span>';

        foreach ($panels as $panel) {
            $rows = '';

            foreach ($panel->rows as $label => $value) {
                $rows .= '<dt>' . self::escape($label) . '</dt><dd>' . self::escape($value) . '</dd>';
            }

            // Le même attribut name sur toutes les rubriques : le navigateur
            // n'en garde qu'une ouverte à la fois, sans JavaScript.
            $html .= '<details name="wz-barre"' . ($panel->alert ? ' class="wz-alerte"' : '') . '>'
                . '<summary><span class="wz-nom">' . self::escape($panel->name) . '</span>'
                . '<span class="wz-resume">' . self::escape($panel->summary) . '</span></summary>'
                . ($rows !== '' ? '<dl class="wz-detail">' . $rows . '</dl>' : '')
                . '</details>';
        }

        return $html . '</aside>';
    }

    private static function isLoopback(string $address): bool
    {
        return $address === '::1'
            || (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($address, '127.'));
    }

    /**
     * Sécurité : tout ce qui est écrit dans la barre passe par ici. Une
     * adresse ou un nom de champ peut contenir ce qu'un visiteur a envoyé.
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
