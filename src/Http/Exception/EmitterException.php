<?php

declare(strict_types=1);

namespace Wazi\Http\Exception;

/**
 * Levée quand une réponse ne peut pas être envoyée au navigateur.
 *
 * Chaque message suit la règle des erreurs pédagogiques de Wazi :
 * ce qui s'est passé, pourquoi, et comment corriger.
 */
final class EmitterException extends \RuntimeException
{
    /**
     * Le fichier et la ligne sont ceux de VOTRE code, donnés par PHP : c'est
     * l'endroit à corriger.
     */
    public static function headersAlreadySent(string $file, int $line): self
    {
        return new self(sprintf(
            'Impossible d\'envoyer la réponse : PHP a déjà commencé à envoyer la page, à cause d\'un affichage'
            . ' dans %s, ligne %d. Une réponse HTTP commence par ses en-têtes : dès qu\'un seul caractère est'
            . ' affiché, il est trop tard pour les envoyer. Cherchez à cet endroit un echo, un print,'
            . ' un var_dump, ou du texte (même une ligne vide) avant la balise <?php, et retirez-le.'
            . ' Dans Wazi, on n\'affiche rien : on retourne une Response.',
            $file,
            $line,
        ));
    }

    public static function outputAlreadyStarted(): self
    {
        return new self(
            'Impossible d\'envoyer la réponse : quelque chose a déjà été affiché (un echo, un print,'
            . ' un var_dump...) et attend dans le tampon de sortie de PHP. Ce texte se retrouverait'
            . ' collé avant votre réponse. Retirez cet affichage : dans Wazi, on n\'affiche rien,'
            . ' on retourne une Response.',
        );
    }

    public static function unsafeHeader(string $name): self
    {
        return new self(sprintf(
            'Impossible d\'envoyer la réponse : l\'en-tête « %s » (son nom ou une de ses valeurs) contient'
            . ' un retour à la ligne, qui permettrait d\'injecter de faux en-têtes. Les réponses de Wazi'
            . ' ne peuvent pas contenir cela : cette réponse vient sans doute d\'une autre bibliothèque.',
            ValuePreview::of($name),
        ));
    }

    public static function unsafeStatusLine(): self
    {
        return new self(
            'Impossible d\'envoyer la réponse : sa version du protocole ou sa phrase de statut contient'
            . ' un retour à la ligne, qui permettrait d\'injecter de faux en-têtes. Les réponses de Wazi'
            . ' ne peuvent pas contenir cela : cette réponse vient sans doute d\'une autre bibliothèque.',
        );
    }
}
