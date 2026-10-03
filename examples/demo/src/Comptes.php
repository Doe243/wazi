<?php

declare(strict_types=1);

namespace Demo;

/**
 * Les comptes de la démonstration : deux personnes, écrites en dur.
 *
 * Un vrai site rangerait ses comptes dans une base de données. Ce qui ne
 * changerait pas, c'est la façon de traiter les mots de passe.
 *
 * Sécurité :
 *   - un mot de passe n'est JAMAIS gardé tel quel. On garde son empreinte,
 *     calculée par password_hash() : même si quelqu'un lit ce fichier, il ne
 *     peut pas retrouver le mot de passe ;
 *   - on compare avec password_verify(), jamais avec == ;
 *   - le paramètre est marqué #[\SensitiveParameter] : si une erreur survient,
 *     PHP masque sa valeur dans la trace.
 */
final class Comptes
{
    /**
     * L'empreinte du mot de passe « wazi », obtenue une fois pour toutes par :
     *
     *     php -r "echo password_hash('wazi', PASSWORD_DEFAULT);"
     */
    private const string EMPREINTE = '$2y$12$Oj.0HPGJYzhGoheTaSgHQuEtGY8r71cxaEq7nPpaVazte5ultWtay';

    /** @var array<string, string> nom => empreinte du mot de passe */
    private const array COMPTES = [
        'alice' => self::EMPREINTE,
        'bob' => self::EMPREINTE,
    ];

    public function verifier(string $nom, #[\SensitiveParameter] string $motDePasse): bool
    {
        // Pour un nom inconnu, on vérifie quand même une empreinte : la
        // réponse met alors le même temps que pour un nom connu, et sa durée
        // ne révèle pas quels comptes existent.
        $empreinte = self::COMPTES[$nom] ?? self::EMPREINTE;
        $motDePasseJuste = password_verify($motDePasse, $empreinte);

        return $motDePasseJuste && array_key_exists($nom, self::COMPTES);
    }
}
