<?php

declare(strict_types=1);

namespace Wazi\Tests\Routing\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Http\Response;

/**
 * Un garde : sans le bon jeton, la suite n'est jamais appelée.
 */
final class RequireToken implements MiddlewareInterface
{
    public static int $created = 0;

    public function __construct(private readonly Greeter $greeter)
    {
        self::$created++;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getHeaderLine('Authorization') !== 'Bearer bon-jeton') {
            return new Response(401, [], $this->greeter->greet('inconnu') . ', connexion requise');
        }

        return $handler->handle($request)->withHeader('X-Garde', 'passé');
    }
}
