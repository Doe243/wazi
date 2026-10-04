<?php

declare(strict_types=1);

namespace Wazi\Tests\Debug;

use PHPUnit\Framework\TestCase;
use Wazi\Debug\Trace;

final class TraceTest extends TestCase
{
    public function testWhatIsRecordedIsGivenBackByKindInOrder(): void
    {
        $trace = new Trace();
        $trace->record('template', 'accueil', 1.5);
        $trace->record('sql', 'SELECT 1', 0.2);
        $trace->record('template', 'pied', 0.4);

        self::assertSame(
            [
                ['kind' => 'template', 'label' => 'accueil', 'milliseconds' => 1.5],
                ['kind' => 'template', 'label' => 'pied', 'milliseconds' => 0.4],
            ],
            $trace->of('template'),
        );
        self::assertSame([], $trace->of('autre'));
        self::assertSame(0, $trace->dropped());
    }

    /**
     * Une page qui boucle signalerait des milliers de choses : on en garde 200.
     */
    public function testItStopsKeepingBeyondALimit(): void
    {
        $trace = new Trace();

        for ($i = 0; $i < 250; ++$i) {
            $trace->record('template', 'ligne', 0.1);
        }

        self::assertCount(200, $trace->of('template'));
        self::assertSame(50, $trace->dropped());
    }
}
