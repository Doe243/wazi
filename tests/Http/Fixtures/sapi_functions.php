<?php

declare(strict_types=1);

namespace Wazi\Http;

use Wazi\Tests\Http\Fixtures\FakeSapi;

/**
 * Voir FakeSapi : ces deux fonctions prennent la place de celles de PHP
 * pour le code du namespace Wazi\Http, uniquement pendant les tests.
 * Elles ont la même signature que les fonctions natives.
 */
function header(string $header, bool $replace = true, int $response_code = 0): void
{
    FakeSapi::$headers[] = [$header, $replace, $response_code];
}

/**
 * @param-out string $filename
 * @param-out int    $line
 */
function headers_sent(?string &$filename = null, ?int &$line = null): bool
{
    [$filename, $line] = FakeSapi::$sentAt ?? ['', 0];

    return FakeSapi::$sentAt !== null;
}
