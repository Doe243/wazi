<?php

declare(strict_types=1);

namespace Wazi\Routing\Exception;

use Wazi\Contracts\HttpError;
use Wazi\Http\Exception\ValuePreview;

/**
 * Levée quand l'adresse existe, mais pas pour la méthode utilisée (405).
 *
 * Exemple : la route est déclarée avec $router->get('/contact', ...), et un
 * formulaire lui envoie un POST. La réponse porte un en-tête Allow qui liste
 * les méthodes acceptées, comme le demande HTTP.
 */
final class MethodNotAllowedException extends \RuntimeException implements HttpError
{
    /**
     * @param list<string> $allowedMethods
     */
    private function __construct(string $message, private readonly array $allowedMethods)
    {
        parent::__construct($message);
    }

    /**
     * @param list<string> $allowedMethods
     */
    public static function forPath(string $method, string $path, array $allowedMethods): self
    {
        return new self(sprintf(
            'L\'adresse « %s » existe, mais pas pour la méthode %s. Méthodes acceptées : %s.'
            . ' Si un formulaire envoie ici ses données, déclarez la route avec $router->post(...) ;'
            . ' sinon, vérifiez l\'attribut method de votre formulaire.',
            ValuePreview::of($path),
            ValuePreview::of($method),
            implode(', ', $allowedMethods),
        ), $allowedMethods);
    }

    public function getStatusCode(): int
    {
        return 405;
    }

    public function getResponseHeaders(): array
    {
        return ['Allow' => implode(', ', $this->allowedMethods)];
    }

    /**
     * @return list<string>
     */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
