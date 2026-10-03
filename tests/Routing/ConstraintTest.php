<?php

declare(strict_types=1);

namespace Wazi\Tests\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Routing\Constraint;

final class ConstraintTest extends TestCase
{
    /**
     * @return iterable<string, array{Constraint, string}>
     */
    public static function acceptedValues(): iterable
    {
        yield 'any : texte' => [Constraint::Any, 'Bonjour le monde'];
        yield 'any : accents' => [Constraint::Any, 'été'];
        yield 'int : zéro' => [Constraint::Int, '0'];
        yield 'int : nombre' => [Constraint::Int, '42'];
        yield 'int : 18 chiffres' => [Constraint::Int, '999999999999999999'];
        yield 'slug : simple' => [Constraint::Slug, 'article'];
        yield 'slug : avec tirets et chiffres' => [Constraint::Slug, 'mon-article-2'];
        yield 'uuid : minuscules' => [Constraint::Uuid, '123e4567-e89b-12d3-a456-426614174000'];
        yield 'uuid : majuscules' => [Constraint::Uuid, '123E4567-E89B-12D3-A456-426614174000'];
    }

    #[DataProvider('acceptedValues')]
    public function testItAcceptsAValidValue(Constraint $constraint, string $value): void
    {
        self::assertTrue($constraint->accepts($value));
    }

    /**
     * @return iterable<string, array{Constraint, string}>
     */
    public static function rejectedValues(): iterable
    {
        yield 'any : vide' => [Constraint::Any, ''];
        yield 'any : trop long' => [Constraint::Any, str_repeat('a', 256)];
        yield 'int : vide' => [Constraint::Int, ''];
        yield 'int : négatif' => [Constraint::Int, '-1'];
        yield 'int : signe plus' => [Constraint::Int, '+1'];
        yield 'int : zéro devant' => [Constraint::Int, '007'];
        yield 'int : virgule' => [Constraint::Int, '1.5'];
        yield 'int : lettres' => [Constraint::Int, '12abc'];
        yield 'int : hexadécimal' => [Constraint::Int, '0x1A'];
        yield 'int : exposant' => [Constraint::Int, '1e3'];
        yield 'int : espace' => [Constraint::Int, ' 1'];
        yield 'int : retour à la ligne final' => [Constraint::Int, "1\n"];
        yield 'int : 19 chiffres, dépasserait un entier' => [Constraint::Int, '9999999999999999999'];
        yield 'int : chiffres non latins' => [Constraint::Int, '٤٢'];
        yield 'slug : majuscules' => [Constraint::Slug, 'Mon-Article'];
        yield 'slug : tiret au début' => [Constraint::Slug, '-article'];
        yield 'slug : tiret à la fin' => [Constraint::Slug, 'article-'];
        yield 'slug : deux tirets' => [Constraint::Slug, 'mon--article'];
        yield 'slug : espace' => [Constraint::Slug, 'mon article'];
        yield 'slug : accent' => [Constraint::Slug, 'été'];
        yield 'slug : point' => [Constraint::Slug, 'index.php'];
        yield 'slug : retour à la ligne final' => [Constraint::Slug, "article\n"];
        yield 'uuid : trop court' => [Constraint::Uuid, '123e4567-e89b-12d3-a456'];
        yield 'uuid : sans tirets' => [Constraint::Uuid, '123e4567e89b12d3a456426614174000'];
        yield 'uuid : lettre hors hexadécimal' => [Constraint::Uuid, '123g4567-e89b-12d3-a456-426614174000'];
        yield 'uuid : retour à la ligne final' => [Constraint::Uuid, "123e4567-e89b-12d3-a456-426614174000\n"];
    }

    #[DataProvider('rejectedValues')]
    public function testItRejectsAnInvalidValue(Constraint $constraint, string $value): void
    {
        self::assertFalse($constraint->accepts($value));
    }

    public function testIntIsConvertedToARealInteger(): void
    {
        self::assertSame(42, Constraint::Int->convert('42'));
        self::assertSame(999999999999999999, Constraint::Int->convert('999999999999999999'));
    }

    public function testOtherConstraintsKeepTheText(): void
    {
        self::assertSame('42', Constraint::Any->convert('42'));
        self::assertSame('mon-article', Constraint::Slug->convert('mon-article'));
    }

    /**
     * Sécurité : une adresse piégée très longue doit être refusée aussitôt,
     * sans faire travailler une expression régulière.
     */
    public function testAVeryLongValueIsRejectedQuickly(): void
    {
        $value = str_repeat('a-', 500000) . '!';
        $start = hrtime(true);

        self::assertFalse(Constraint::Slug->accepts($value));
        self::assertLessThan(100_000_000, hrtime(true) - $start, 'Le refus doit prendre moins de 100 ms.');
    }

    public function testNamesListsEveryConstraint(): void
    {
        self::assertSame('any, int, slug, uuid', Constraint::names());
    }
}
