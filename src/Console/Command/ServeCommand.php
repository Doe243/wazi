<?php

declare(strict_types=1);

namespace Wazi\Console\Command;

use Wazi\Console\Application;
use Wazi\Console\Command;
use Wazi\Console\Input;
use Wazi\Console\Option;
use Wazi\Console\Output;

/**
 * « wazi serve » : lance le serveur de développement de PHP sur le dossier public/.
 *
 *     wazi serve                 http://localhost:8000
 *     wazi serve --port=8080     http://localhost:8080
 *
 * C'est le serveur fourni avec PHP (« php -S »). Il sert à développer, pas à
 * recevoir des visiteurs : en ligne, il faut un vrai serveur web.
 *
 * Sécurité (ADR-026) :
 *   - il n'écoute que sur « localhost » : seul votre ordinateur peut l'atteindre.
 *     Une autre adresse se demande par --host, et déclenche un avertissement ;
 *   - l'adresse et le port sont vérifiés par une forme stricte, puis donnés à
 *     PHP comme une LISTE d'arguments. Aucun interpréteur de commandes n'est
 *     utilisé : rien de ce qui est tapé ne peut devenir une autre commande ;
 *   - seul le dossier public/ est servi.
 */
final readonly class ServeCommand implements Command
{
    /** Un nom d'hôte ou une adresse IPv4 : lettres, chiffres, points et tirets. */
    private const string HOST = '/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]{0,251}[a-zA-Z0-9])?$/D';

    /** Les adresses que seul cet ordinateur peut atteindre. */
    private const array LOCAL_HOSTS = ['localhost', '127.0.0.1'];

    /** @var \Closure(list<string>): int */
    private \Closure $launcher;

    /**
     * @param string                           $directory le dossier du projet ; celui où la commande est tapée, par défaut
     * @param (\Closure(list<string>): int)|null $launcher lance un programme et retourne son code de sortie ; remplacé dans les tests
     */
    public function __construct(private ?string $directory = null, ?\Closure $launcher = null)
    {
        $this->launcher = $launcher ?? self::launch(...);
    }

    public function name(): string
    {
        return 'serve';
    }

    public function description(): string
    {
        return 'Lance le site sur votre ordinateur, pour développer.';
    }

    public function arguments(): array
    {
        return [];
    }

    public function options(): array
    {
        return [
            new Option('port', 'Le port sur lequel écouter', '8000'),
            new Option('host', 'L\'adresse sur laquelle écouter ; à changer seulement en connaissance de cause', 'localhost'),
        ];
    }

    public function run(Input $input, Output $output): int
    {
        $host = $input->option('host');
        $port = $input->option('port');
        $public = ($this->directory ?? (string) getcwd()) . DIRECTORY_SEPARATOR . 'public';

        if (preg_match(self::HOST, $host) !== 1) {
            $output->error('L\'adresse donnée à --host n\'est pas utilisable. Écrivez un nom ou une adresse IPv4, par exemple « localhost » ou « 192.168.1.20 ».');

            return Application::USAGE_ERROR;
        }

        // ctype_digit() refuse les signes, les espaces et les décimales.
        if (!ctype_digit($port) || strlen($port) > 5 || (int) $port < 1 || (int) $port > 65535) {
            $output->error('Le port donné à --port n\'est pas utilisable. Écrivez un nombre entre 1 et 65535, par exemple --port=8080.');

            return Application::USAGE_ERROR;
        }

        if (!is_dir($public)) {
            $output->error(
                'Le dossier public/ est introuvable ici. Lancez cette commande depuis le dossier de votre projet,'
                . ' celui qui contient composer.json et public/.',
            );

            return Application::FAILURE;
        }

        $output->title('Serveur de développement');
        $output->line('Votre site : http://' . $host . ':' . $port);
        $output->line('Pour l\'arrêter : Ctrl+C');

        if (!in_array(strtolower($host), self::LOCAL_HOSTS, true)) {
            $output->warning(
                'Avec cette adresse, le site est visible depuis les autres appareils du réseau. Ce serveur est fait'
                . ' pour développer : il n\'est ni rapide ni protégé. Ne l\'utilisez pas pour un site en ligne.',
            );
        }

        $output->line();

        return ($this->launcher)([PHP_BINARY, '-S', $host . ':' . $port, '-t', $public]);
    }

    /**
     * Lance un programme et attend qu'il se termine.
     *
     * Sécurité : la commande est une LISTE. PHP lance alors le programme
     * directement, sans passer par un interpréteur de commandes.
     *
     * @param list<string> $command le programme, puis ses arguments
     */
    private static function launch(array $command): int
    {
        // Le serveur écrit dans ce terminal, et lit ce clavier (pour Ctrl+C).
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);

        return is_resource($process) ? proc_close($process) : Application::FAILURE;
    }
}
