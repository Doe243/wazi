<?php

declare(strict_types=1);

namespace Wazi\Tests\Errors;

use PHPUnit\Framework\TestCase;
use Wazi\Errors\ErrorLog;
use Wazi\Errors\PhpErrorLog;

final class PhpErrorLogTest extends TestCase
{
    public function testItIsAnErrorLog(): void
    {
        self::assertInstanceOf(ErrorLog::class, new PhpErrorLog());
    }

    public function testItWritesToThePhpErrorLog(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wazi');
        self::assertIsString($file);
        $previous = ini_set('error_log', $file);

        try {
            new PhpErrorLog()->write('[wazi] compte rendu de test');

            self::assertStringContainsString('[wazi] compte rendu de test', (string) file_get_contents($file));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            unlink($file);
        }
    }
}
