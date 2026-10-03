<?php

declare(strict_types=1);

namespace Wazi\Tests\View\Fixtures;

/**
 * Un objet donné à un template, pour tester ce qu'une expression peut lire et appeler.
 */
final class Note
{
    public static int $destroyed = 0;

    private string $secret = 'mot-de-passe-tres-secret';

    protected string $brouillon = 'brouillon protégé';

    public function __construct(public string $texte = 'Acheter du pain', public int $id = 7, public ?Note $suivante = null) {}

    public function resume(int $longueur = 5): string
    {
        return mb_substr($this->texte, 0, $longueur);
    }

    public function estLongue(): bool
    {
        return mb_strlen($this->texte) > 10;
    }

    public function echoue(): never
    {
        throw new \DomainException('échec dans la méthode');
    }

    public function __destruct()
    {
        self::$destroyed++;
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    public function __call(string $name, array $arguments): string
    {
        return 'magie : ' . $name;
    }

    private function secret(): string
    {
        return $this->secret;
    }

    protected function brouillon(): string
    {
        return $this->brouillon . $this->secret();
    }
}
