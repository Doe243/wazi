<?php

declare(strict_types=1);

namespace Wazi\Tests\Http;

use PHPUnit\Framework\TestCase;
use Wazi\Http\CspNonce;

final class CspNonceTest extends TestCase
{
    public function testEachNonceIsDifferent(): void
    {
        $values = [];

        for ($i = 0; $i < 50; $i++) {
            $values[] = new CspNonce()->value;
        }

        self::assertCount(50, array_unique($values));
    }

    /**
     * Le jeton est écrit dans un en-tête HTTP et dans un attribut HTML : il ne
     * doit contenir aucun caractère qui y aurait un sens particulier.
     */
    public function testTheValueIsSafeInAHeaderAndInAnAttribute(): void
    {
        for ($i = 0; $i < 50; $i++) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/D', new CspNonce()->value);
        }
    }

    public function testTheValueIsLongEnoughNotToBeGuessed(): void
    {
        // 22 caractères parmi 64 : 128 bits de hasard.
        self::assertSame(22, strlen(new CspNonce()->value));
    }
}
