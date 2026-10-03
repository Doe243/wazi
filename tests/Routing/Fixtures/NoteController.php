<?php

declare(strict_types=1);

namespace Wazi\Tests\Routing\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Wazi\Http\Response;
use Wazi\Routing\Attribute\Delete;
use Wazi\Routing\Attribute\Get;
use Wazi\Routing\Attribute\Patch;
use Wazi\Routing\Attribute\Post;
use Wazi\Routing\Attribute\Put;

/**
 * Un contrôleur dont les routes sont écrites en attributs.
 */
final class NoteController
{
    public function __construct(private readonly Greeter $greeter) {}

    #[Get('/notes')]
    public function index(): ResponseInterface
    {
        return new Response(200, [], 'liste des notes');
    }

    #[Get('/notes/{id:int}')]
    public function show(int $id): ResponseInterface
    {
        return new Response(200, [], 'note ' . $id);
    }

    #[Post('/notes', [RequireToken::class])]
    public function create(): ResponseInterface
    {
        return new Response(201, [], 'note créée');
    }

    // Deux attributs : la même méthode répond à deux routes.
    #[Put('/notes/{id:int}')]
    #[Patch('/notes/{id:int}')]
    public function update(int $id): ResponseInterface
    {
        return new Response(200, [], 'note ' . $id . ' modifiée');
    }

    #[Delete('/notes/{id:int}', middlewares: [RequireToken::class])]
    public function delete(int $id): ResponseInterface
    {
        return new Response(200, [], 'note ' . $id . ' supprimée');
    }

    #[Get('/salut/{name}')]
    #[Get('/bonjour/{name}')]
    public function greet(string $name): ResponseInterface
    {
        return new Response(200, [], $this->greeter->greet($name));
    }

    // Sans attribut : cette méthode publique n'est pas une route.
    public function notARoute(): ResponseInterface
    {
        return new Response(200, [], 'ne doit jamais être atteint');
    }
}
