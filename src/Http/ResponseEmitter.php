<?php

declare(strict_types=1);

namespace Wazi\Http;

use Psr\Http\Message\ResponseInterface;
use Wazi\Http\Exception\EmitterException;

/**
 * Envoie une réponse au navigateur : la dernière étape du trajet d'une requête.
 *
 * Jusqu'ici, la Response n'était qu'un objet en mémoire. L'émetteur la traduit
 * en ce que PHP sait envoyer :
 *
 *     en-têtes de la réponse  ──► header('Content-Type: text/html')
 *     code et phrase de statut ─► header('HTTP/1.1 200 OK')
 *     corps                   ──► echo, morceau par morceau
 *
 * C'est le SEUL endroit de Wazi qui appelle header() et echo. Partout ailleurs,
 * on retourne une Response : c'est ce qui rend le code testable sans navigateur.
 *
 * Sécurité (ADR-006) :
 *   - l'émetteur accepte n'importe quelle réponse PSR-7, pas seulement celles
 *     de Wazi : il revérifie donc qu'aucun en-tête ne contient de retour à la
 *     ligne (injection d'en-têtes) ;
 *   - si quelque chose a déjà été affiché, il refuse d'envoyer une réponse à
 *     moitié correcte et explique où chercher ;
 *   - le corps est lu par morceaux : un gros fichier n'est jamais chargé en
 *     entier dans la mémoire.
 */
final class ResponseEmitter
{
    /** Taille des morceaux du corps envoyés l'un après l'autre. */
    private const int CHUNK_SIZE = 8192;

    /** Retour à la ligne ou octet nul : ce qui permet de couper un en-tête en deux. */
    private const string LINE_BREAK = '/[\r\n\0]/';

    /**
     * @param bool $withBody passez false pour répondre à une requête HEAD : mêmes en-têtes, sans le corps
     *
     * @throws EmitterException si la page a déjà commencé à être envoyée, ou si un en-tête est dangereux
     */
    public function emit(ResponseInterface $response, bool $withBody = true): void
    {
        $this->assertNothingWasSentYet();

        // Tout est vérifié AVANT le premier header() : on n'envoie pas une
        // réponse dont la moitié des en-têtes seulement serait partie.
        $this->assertHeadersAreSafe($response);

        $this->emitHeaders($response);
        $this->emitStatusLine($response);

        if ($withBody && self::statusAllowsBody($response->getStatusCode())) {
            $this->emitBody($response);
        }
    }

    // ------------------------------------------------------------------
    // Vérifications
    // ------------------------------------------------------------------

    private function assertNothingWasSentYet(): void
    {
        $file = '';
        $line = 0;

        if (headers_sent($file, $line)) {
            throw EmitterException::headersAlreadySent($file, $line);
        }

        // Un affichage peut aussi attendre dans un tampon de sortie (ob_start()),
        // sans être encore parti : il serait envoyé collé avant la réponse.
        if (ob_get_level() > 0 && ob_get_length() > 0) {
            throw EmitterException::outputAlreadyStarted();
        }
    }

    private function assertHeadersAreSafe(ResponseInterface $response): void
    {
        if (preg_match(self::LINE_BREAK, $response->getProtocolVersion() . $response->getReasonPhrase()) === 1) {
            throw EmitterException::unsafeStatusLine();
        }

        foreach ($response->getHeaders() as $name => $values) {
            $name = (string) $name;

            if ($name === '' || str_contains($name, ':') || preg_match(self::LINE_BREAK, $name . implode('', $values)) === 1) {
                throw EmitterException::unsafeHeader($name);
            }
        }
    }

    // ------------------------------------------------------------------
    // Envoi
    // ------------------------------------------------------------------

    private function emitHeaders(ResponseInterface $response): void
    {
        foreach ($response->getHeaders() as $name => $values) {
            // Le premier envoi d'un en-tête REMPLACE celui que PHP aurait déjà
            // préparé ; les suivants s'ajoutent. Exception : Set-Cookie, qui
            // s'ajoute toujours, pour ne pas effacer le cookie de session de PHP.
            $replace = strtolower((string) $name) !== 'set-cookie';

            foreach ($values as $value) {
                header($name . ': ' . $value, $replace);
                $replace = false;
            }
        }
    }

    /**
     * La ligne de statut part après les en-têtes : un en-tête Location, par
     * exemple, pousse PHP à choisir lui-même le code 302. En l'envoyant en
     * dernier, c'est bien le code de la réponse qui s'applique.
     */
    private function emitStatusLine(ResponseInterface $response): void
    {
        $statusLine = sprintf('HTTP/%s %d', $response->getProtocolVersion(), $response->getStatusCode());

        if ($response->getReasonPhrase() !== '') {
            $statusLine .= ' ' . $response->getReasonPhrase();
        }

        header($statusLine, true, $response->getStatusCode());
    }

    private function emitBody(ResponseInterface $response): void
    {
        $body = $response->getBody();

        if (!$body->isReadable()) {
            return;
        }

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            echo $body->read(self::CHUNK_SIZE);
        }
    }

    /**
     * Certaines réponses n'ont jamais de corps : les réponses d'information (1xx),
     * 204 « pas de contenu » et 304 « non modifié ».
     */
    private static function statusAllowsBody(int $statusCode): bool
    {
        return $statusCode >= 200 && $statusCode !== 204 && $statusCode !== 304;
    }
}
