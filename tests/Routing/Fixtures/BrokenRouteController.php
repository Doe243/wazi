<?php

declare(strict_types=1);

namespace Wazi\Tests\Routing\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Wazi\Http\Response;
use Wazi\Routing\Attribute\Get;

final class BrokenRouteController
{
    #[Get('notes-sans-barre')]
    public function index(): ResponseInterface
    {
        return new Response();
    }
}
