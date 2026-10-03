<?php

declare(strict_types=1);

namespace Wazi\Tests\Routing\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Wazi\Http\Response;
use Wazi\Routing\Attribute\Get;

final class PrivateRouteController
{
    #[Get('/secret')]
    private function secret(): ResponseInterface
    {
        return new Response(200, [], 'ne doit jamais être atteint');
    }

    public function touch(): ResponseInterface
    {
        return $this->secret();
    }
}
