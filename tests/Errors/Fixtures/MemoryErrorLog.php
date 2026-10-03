<?php

declare(strict_types=1);

namespace Wazi\Tests\Errors\Fixtures;

use Wazi\Errors\ErrorLog;

/**
 * Un journal qui garde ses entrées en mémoire, pour que les tests les examinent.
 */
final class MemoryErrorLog implements ErrorLog
{
    /** @var list<string> */
    public array $entries = [];

    public function write(string $entry): void
    {
        $this->entries[] = $entry;
    }
}
