<?php

declare(strict_types=1);

namespace Wazi\Console;

/**
 * Ce qu'une commande écrit dans le terminal.
 *
 *     $output->title('Serveur de développement');
 *     $output->line('Le site est servi sur http://localhost:8000');
 *     $output->success('Contrôleur créé.');
 *     $output->warning('Ce fichier existe déjà.');
 *     $output->error('Le dossier public/ est introuvable.');
 *
 * Les messages ordinaires vont sur la sortie standard, les erreurs sur la
 * sortie d'erreur : on peut ainsi enregistrer le résultat d'une commande dans
 * un fichier sans y mêler ses erreurs.
 *
 * Sécurité (ADR-026) : tout texte écrit est nettoyé de ses caractères de
 * contrôle, sauf le retour à la ligne et la tabulation. Une valeur venue
 * d'ailleurs (une adresse reçue, une ligne d'un journal) ne peut donc pas
 * glisser une séquence qui effacerait l'écran ou piégerait le terminal. La
 * couleur vient uniquement des méthodes de cette classe.
 */
final readonly class Output
{
    /** Tout caractère de contrôle, sauf le retour à la ligne (\n) et la tabulation (\t). */
    private const string CONTROL = '/[\x00-\x08\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/';

    private const string BOLD = '1';

    private const string GREEN = '32';

    private const string YELLOW = '33';

    private const string RED = '31';

    private const string CYAN = '36';

    private bool $colors;

    /**
     * @param resource  $standard la sortie ordinaire (STDOUT)
     * @param resource  $errors   la sortie des erreurs (STDERR)
     * @param bool|null $colors   true ou false pour forcer la couleur ; null pour décider selon le terminal
     */
    public function __construct(private mixed $standard, private mixed $errors, ?bool $colors = null)
    {
        $this->colors = $colors ?? self::supportsColors($standard);
    }

    public function line(string $text = ''): void
    {
        $this->write($this->standard, self::clean($text));
    }

    /**
     * Un titre, suivi d'une ligne vide.
     */
    public function title(string $text): void
    {
        $this->write($this->standard, $this->styled(self::clean($text), self::BOLD));
        $this->write($this->standard, '');
    }

    public function success(string $text): void
    {
        $this->write($this->standard, $this->styled('OK', self::GREEN) . '  ' . self::clean($text));
    }

    public function warning(string $text): void
    {
        $this->write($this->standard, $this->styled('Attention', self::YELLOW) . '  ' . self::clean($text));
    }

    public function error(string $text): void
    {
        $this->write($this->errors, $this->styled('Erreur', self::RED) . '  ' . self::clean($text));
    }

    /**
     * Une liste à deux colonnes, alignée : un nom, puis ce qu'il désigne.
     *
     * @param array<string, string> $items nom => description
     */
    public function definitions(array $items): void
    {
        $width = 0;

        foreach (array_keys($items) as $name) {
            $width = max($width, mb_strlen(self::clean($name)));
        }

        foreach ($items as $name => $description) {
            $name = self::clean($name);
            $padding = str_repeat(' ', $width - mb_strlen($name) + 2);

            $this->write($this->standard, '  ' . $this->styled($name, self::CYAN) . $padding . self::clean($description));
        }
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * @param resource $stream
     */
    private function write(mixed $stream, string $line): void
    {
        fwrite($stream, $line . PHP_EOL);
    }

    private function styled(string $text, string $code): string
    {
        return $this->colors ? "\e[" . $code . 'm' . $text . "\e[0m" : $text;
    }

    private static function clean(string $text): string
    {
        return preg_replace(self::CONTROL, '?', $text) ?? '';
    }

    /**
     * La couleur n'a de sens que dans un terminal : dans un fichier ou dans
     * les journaux d'un outil, les séquences de couleur seraient du bruit.
     * La variable NO_COLOR est la convention pour s'en passer (no-color.org).
     *
     * @param resource $stream
     */
    private static function supportsColors(mixed $stream): bool
    {
        $noColor = getenv('NO_COLOR');

        if (is_string($noColor) && $noColor !== '') {
            return false;
        }

        if (!stream_isatty($stream)) {
            return false;
        }

        // Sous Windows, le terminal doit accepter les séquences de couleur.
        return PHP_OS_FAMILY !== 'Windows' || sapi_windows_vt100_support($stream, true);
    }
}
