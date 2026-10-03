<?php

declare(strict_types=1);

namespace Demo;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Wazi\Http\Response;
use Wazi\Middleware\WithoutCsrf;
use Wazi\Routing\Attribute\Post;

/**
 * Une route appelée par un autre programme, pas par un navigateur.
 *
 * Un prestataire de paiement, par exemple, prévient votre site qu'un paiement
 * est arrivé. Il n'a ni cookie ni jeton de formulaire : la protection CSRF le
 * refuserait. On l'en dispense donc, sur CETTE route seulement, avec
 * [WithoutCsrf::class].
 *
 * Sécurité : dispenser une route de la protection CSRF ne veut pas dire
 * l'ouvrir à tous. Il faut vérifier autrement d'où vient la requête. Ici,
 * l'expéditeur signe son message avec un secret que vous partagez avec lui :
 *
 *     signature = hash_hmac('sha256', corps du message, secret)
 *
 * Sans le secret, impossible de fabriquer une signature juste.
 *
 * Pour l'essayer (avec DEMO_WEBHOOK_SECRET=secret-de-demo dans .env) :
 *
 *     curl -X POST http://localhost:8000/webhook -d "bonjour" \
 *          -H "X-Signature: $(printf 'bonjour' | openssl dgst -sha256 -hmac 'secret-de-demo' -r | cut -d' ' -f1)"
 */
final readonly class WebhookController
{
    public function __construct(#[\SensitiveParameter] private string $secret) {}

    #[Post('/webhook', [WithoutCsrf::class])]
    public function recevoir(ServerRequestInterface $request): ResponseInterface
    {
        // Sans secret configuré, la route n'existe pas : mieux vaut cela
        // qu'une route ouverte à tous par oubli.
        if ($this->secret === '') {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Le webhook n\'est pas configuré.');
        }

        $attendue = hash_hmac('sha256', (string) $request->getBody(), $this->secret);

        // hash_equals() compare en un temps constant : la durée de la
        // comparaison ne révèle rien de la signature attendue.
        if (!hash_equals($attendue, $request->getHeaderLine('X-Signature'))) {
            return new Response(403, ['Content-Type' => 'text/plain; charset=utf-8'], 'Signature incorrecte.');
        }

        return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], 'Message reçu.');
    }
}
