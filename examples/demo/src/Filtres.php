<?php

declare(strict_types=1);

namespace Demo;

/**
 * Les filtres Kioo propres à cette application.
 *
 * Un filtre est une simple fonction : elle reçoit la valeur écrite à gauche
 * de la barre verticale, et retourne ce qui sera affiché.
 *
 *     {note.creee | depuis}        il y a 3 h
 *     {utilisateur | initiale}     A
 *
 * Ils sont ajoutés à Kioo dans public/index.php :
 *
 *     $kioo->addFilter('depuis', Filtres::depuis(...));
 *
 * Un template ne peut appeler que les filtres de Kioo et ceux-ci : aucune
 * fonction de PHP.
 */
final class Filtres
{
    /**
     * Le temps écoulé depuis une date, en mots : « à l'instant », « il y a 3 h », « hier ».
     * Au-delà d'une semaine, la date elle-même.
     */
    public static function depuis(mixed $date, ?\DateTimeInterface $maintenant = null): string
    {
        if (!$date instanceof \DateTimeInterface) {
            throw new \InvalidArgumentException('Le filtre « depuis » attend une date (un objet DateTime ou DateTimeImmutable).');
        }

        $secondes = ($maintenant ?? new \DateTimeImmutable())->getTimestamp() - $date->getTimestamp();

        return match (true) {
            $secondes < 60 => 'à l\'instant',
            $secondes < 3600 => 'il y a ' . intdiv($secondes, 60) . ' min',
            $secondes < 86400 => 'il y a ' . intdiv($secondes, 3600) . ' h',
            $secondes < 2 * 86400 => 'hier',
            $secondes < 7 * 86400 => 'il y a ' . intdiv($secondes, 86400) . ' jours',
            default => 'le ' . $date->format('d/m/Y'),
        };
    }

    /**
     * La première lettre d'un texte, en majuscule : pour la pastille d'un compte.
     */
    public static function initiale(mixed $texte): string
    {
        return is_string($texte) && $texte !== '' ? mb_strtoupper(mb_substr($texte, 0, 1)) : '?';
    }
}
