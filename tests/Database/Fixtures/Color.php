<?php

declare(strict_types=1);

namespace Wazi\Tests\Database\Fixtures;

/**
 * Une énumération à valeur : c'est sa valeur qui part en base.
 */
enum Color: string
{
    case Honey = 'miel';
    case Sky = 'ciel';
}
