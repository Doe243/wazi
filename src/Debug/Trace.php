<?php

declare(strict_types=1);

namespace Wazi\Debug;

use Wazi\Contracts\Tracer;

/**
 * Ce que les composants ont signalé pendant la requête, pour la barre de débogage.
 *
 * Le noyau en crée un en mode développement, et le donne aux composants qui
 * savent signaler ce qu'ils font (Kioo, par exemple). La barre lit ensuite ce
 * qui a été noté.
 */
final class Trace implements Tracer
{
    /** Au-delà, une page boucle sur quelque chose : inutile de tout garder. */
    private const int LIMIT = 200;

    /** @var list<array{kind: string, label: string, milliseconds: float}> */
    private array $entries = [];

    private int $dropped = 0;

    public function record(string $kind, string $label, float $milliseconds): void
    {
        if (count($this->entries) >= self::LIMIT) {
            ++$this->dropped;

            return;
        }

        $this->entries[] = ['kind' => $kind, 'label' => $label, 'milliseconds' => $milliseconds];
    }

    /**
     * Ce qui a été signalé pour une sorte de chose, dans l'ordre.
     *
     * @return list<array{kind: string, label: string, milliseconds: float}>
     */
    public function of(string $kind): array
    {
        return array_values(array_filter($this->entries, static fn(array $entry): bool => $entry['kind'] === $kind));
    }

    /**
     * Le nombre de signalements laissés de côté, une fois la limite atteinte.
     */
    public function dropped(): int
    {
        return $this->dropped;
    }
}
