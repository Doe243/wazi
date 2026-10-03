<?php

declare(strict_types=1);

namespace Wazi\Tests\Routing\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Wazi\Http\Response;

final class ArticleController
{
    /** Le nombre de contrôleurs fabriqués, pour vérifier quand le conteneur travaille. */
    public static int $created = 0;

    public function __construct(private readonly Greeter $greeter)
    {
        self::$created++;
    }

    public function index(): ResponseInterface
    {
        return new Response(200, [], 'liste');
    }

    public function show(ServerRequestInterface $request, int $id): ResponseInterface
    {
        return new Response(200, [], $request->getMethod() . ' article ' . $id . ' (' . get_debug_type($id) . ')');
    }

    public function greet(string $name, string $punctuation = ' !'): ResponseInterface
    {
        return new Response(200, [], $this->greeter->greet($name) . $punctuation);
    }

    public function wrongType(string $id): ResponseInterface
    {
        return new Response(200, [], $id);
    }

    public function needsService(Greeter $greeter): ResponseInterface
    {
        return new Response(200, [], $greeter->greet('x'));
    }

    public function returnsText(): string
    {
        return 'pas une réponse';
    }

    public static function fromStatic(): ResponseInterface
    {
        return new Response(200, [], 'statique');
    }

    protected function hidden(): ResponseInterface
    {
        return new Response(200, [], 'ne doit jamais être atteint');
    }
}
