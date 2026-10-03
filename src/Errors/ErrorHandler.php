<?php

declare(strict_types=1);

namespace Wazi\Errors;

use Wazi\Contracts\HttpError;
use Wazi\Http\Response;

/**
 * Transforme une exception en réponse HTTP, et consigne son détail dans le journal.
 *
 *     try {
 *         $response = $router->handle($request);
 *     } catch (\Throwable $error) {
 *         $response = $errorHandler->handle($error);
 *     }
 *
 * Deux sortes d'exceptions :
 *   - celles qui implémentent HttpError (page introuvable, requête refusée...)
 *     connaissent leur code de statut : ce sont des réponses, pas des pannes ;
 *   - toutes les autres sont des pannes : erreur 500.
 *
 * Sécurité (ADR-006) — ce que voit le visiteur :
 *   - en production (le mode par défaut) : une page générique et, pour une
 *     panne, une référence à communiquer au développeur. Rien d'autre ;
 *   - en développement : en plus, le message de l'erreur, son type, et le
 *     fichier et la ligne de VOTRE code où elle est née. Jamais la trace,
 *     jamais la valeur d'une variable ou d'un réglage.
 * Le détail complet va dans le journal, que seul le développeur peut lire.
 * Sa trace ne contient pas les arguments des fonctions : un mot de passe passé
 * à une fonction n'a rien à faire dans un journal.
 */
final readonly class ErrorHandler
{
    /** Le titre et le texte montrés au visiteur, pour chaque code de statut connu. */
    private const array PAGES = [
        400 => ['Requête incorrecte', 'Votre navigateur a envoyé une requête que le site ne peut pas traiter.'],
        401 => ['Connexion requise', 'Vous devez vous connecter pour voir cette page.'],
        403 => ['Accès refusé', 'Vous n\'avez pas le droit de voir cette page.'],
        404 => ['Page introuvable', 'L\'adresse demandée n\'existe pas, ou n\'existe plus.'],
        405 => ['Méthode non permise', 'Cette adresse ne peut pas être utilisée de cette façon.'],
        413 => ['Contenu trop volumineux', 'Ce que vous avez envoyé dépasse la taille autorisée.'],
        429 => ['Trop de requêtes', 'Vous avez envoyé trop de requêtes. Réessayez dans un moment.'],
        500 => ['Erreur interne', 'Une erreur s\'est produite de notre côté. Elle a été enregistrée.'],
        503 => ['Service indisponible', 'Le site est momentanément indisponible. Réessayez dans un moment.'],
    ];

    private const string DEVELOPMENT_NOTE = 'Vous voyez ce détail parce que l\'application est en mode développement.'
        . ' En production, le visiteur ne voit qu\'un message générique.'
        . ' Le compte rendu complet de l\'erreur est dans le journal (ou dans le terminal du serveur de développement).';

    private const string CONTROL_CHARACTER = '/[\x00-\x1F\x7F]/';

    /** Le dossier src/ de Wazi : les fichiers qui s'y trouvent ne sont pas « votre code ». */
    private string $frameworkDirectory;

    /**
     * @param bool $development true pour montrer le message de l'erreur dans le navigateur ; false par défaut
     */
    public function __construct(
        private bool $development = false,
        private ErrorLog $log = new PhpErrorLog(),
        private ErrorPage $page = new ErrorPage(),
    ) {
        $this->frameworkDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR;
    }

    /**
     * Ne lève jamais d'exception : si le traitement de l'erreur échoue à son
     * tour, une réponse 500 minimale est retournée.
     */
    public function handle(\Throwable $error): Response
    {
        try {
            return $this->respond($error);
        } catch (\Throwable) {
            return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], 'Erreur interne.');
        }
    }

    // ------------------------------------------------------------------
    // Réponse
    // ------------------------------------------------------------------

    private function respond(\Throwable $error): Response
    {
        $statusCode = self::statusCodeOf($error);
        $isFailure = $statusCode >= 500;

        // Une panne reçoit une référence : le visiteur peut la transmettre, et
        // le développeur la retrouve dans le journal.
        $reference = $isFailure ? bin2hex(random_bytes(8)) : '';

        // En production, seules les pannes sont consignées : une page
        // introuvable n'est pas un incident. En développement, tout l'est.
        if ($isFailure || $this->development) {
            $this->report($error, $statusCode, $reference);
        }

        [$title, $text] = self::PAGES[$statusCode] ?? self::PAGES[$isFailure ? 500 : 400];

        $html = $this->development
            ? $this->page->render($statusCode, $title, $text, $this->developmentDetails($error, $isFailure, $reference), self::DEVELOPMENT_NOTE)
            : $this->page->render($statusCode, $title, $text, $reference !== '' ? ['Référence de l\'erreur' => $reference] : []);

        $response = new Response($statusCode, self::securityHeaders(), $html);

        // Les en-têtes de l'exception (Allow pour un 405, par exemple) s'ajoutent,
        // mais ne remplacent jamais un en-tête de sécurité.
        foreach (self::responseHeadersOf($error) as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    private static function statusCodeOf(\Throwable $error): int
    {
        if (!$error instanceof HttpError) {
            return 500;
        }

        $statusCode = $error->getStatusCode();

        // Une HttpError annonce une erreur (4xx ou 5xx). Tout autre code serait
        // une erreur de programmation : on répond 500.
        return $statusCode >= 400 && $statusCode <= 599 ? $statusCode : 500;
    }

    /**
     * @return array<string, string>
     */
    private static function responseHeadersOf(\Throwable $error): array
    {
        return $error instanceof HttpError ? $error->getResponseHeaders() : [];
    }

    /**
     * @return array<string, string>
     */
    private static function securityHeaders(): array
    {
        // La page n'a le droit de charger RIEN, sauf sa propre feuille de style,
        // reconnue à son empreinte. Même si un script s'y glissait, le
        // navigateur refuserait de l'exécuter.
        $styleHash = base64_encode(hash('sha256', ErrorPage::STYLE, true));

        return [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'sha256-" . $styleHash . "'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            // Une page d'erreur ne doit pas être gardée en mémoire par un cache.
            'Cache-Control' => 'no-store',
        ];
    }

    /**
     * Ce que le développeur voit dans son navigateur, en mode développement uniquement.
     *
     * @return array<string, string>
     */
    private function developmentDetails(\Throwable $error, bool $isFailure, string $reference): array
    {
        $details = [
            'Ce qui s\'est passé' => $error->getMessage(),
            'Type de l\'erreur' => $error::class,
        ];

        if (!$isFailure) {
            return $details;
        }

        $origin = $this->originInYourCode($error);

        if ($origin !== null) {
            $details['Où, dans votre code'] = $origin;
        }

        $details['Référence dans le journal'] = $reference;

        return $details;
    }

    /**
     * L'endroit de VOTRE code d'où part l'erreur : « fichier, ligne N ».
     *
     * Une exception naît souvent au fond du framework ou d'une bibliothèque,
     * ce qui n'aide pas à corriger. On remonte donc la trace jusqu'au premier
     * fichier qui n'appartient ni à Wazi ni au dossier vendor/.
     */
    private function originInYourCode(\Throwable $error): ?string
    {
        $places = [['file' => $error->getFile(), 'line' => $error->getLine()], ...$error->getTrace()];

        foreach ($places as $place) {
            $file = $place['file'] ?? '';
            $line = $place['line'] ?? 0;

            if ($file !== '' && !$this->isLibraryFile($file)) {
                return $file . ', ligne ' . $line;
            }
        }

        return null;
    }

    private function isLibraryFile(string $file): bool
    {
        return str_starts_with($file, $this->frameworkDirectory)
            || str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);
    }

    // ------------------------------------------------------------------
    // Journal
    // ------------------------------------------------------------------

    private function report(\Throwable $error, int $statusCode, string $reference): void
    {
        $lines = [sprintf(
            '[wazi] %s%d — %s',
            $reference !== '' ? 'Erreur ' . $reference . ' — ' : '',
            $statusCode,
            self::summary($error),
        )];

        foreach ($error->getTrace() as $position => $frame) {
            $lines[] = sprintf(
                '  #%d %s:%d %s%s%s()',
                $position,
                $frame['file'] ?? '[code interne de PHP]',
                $frame['line'] ?? 0,
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'],
            );
        }

        for ($cause = $error->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            $lines[] = '  causée par : ' . self::summary($cause);
        }

        // Sécurité : un message peut contenir un retour à la ligne venu d'un
        // visiteur. Chaque ligne est nettoyée, pour qu'on ne puisse pas glisser
        // une fausse entrée dans le journal.
        $entry = implode("\n", array_map(
            static fn(string $line): string => preg_replace(self::CONTROL_CHARACTER, '?', $line) ?? '',
            $lines,
        ));

        try {
            $this->log->write($entry);
        } catch (\Throwable) {
            // Un journal en panne ne doit pas empêcher de répondre au visiteur.
        }
    }

    private static function summary(\Throwable $error): string
    {
        return sprintf('%s : %s (dans %s:%d)', $error::class, $error->getMessage(), $error->getFile(), $error->getLine());
    }
}
