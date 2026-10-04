<?php

declare(strict_types=1);

namespace Wazi\Console;

use Wazi\Console\Exception\ConsoleException;

/**
 * La console : elle reçoit ce qui a été tapé, trouve la commande, et l'exécute.
 *
 *     $console = new Application();
 *     $console->add(new ServeCommand());
 *
 *     exit($console->run($argv));
 *
 * Le trajet de « wazi serve --port=8080 » :
 *
 *     ce qui a été tapé
 *          │  Application : trouve la commande « serve »
 *          ▼
 *     la commande et le reste de la ligne
 *          │  Input : vérifie arguments et options contre ce que la commande déclare
 *          ▼
 *     Command::run()  ──►  Output : écrit dans le terminal
 *          │
 *          ▼
 *     un code de sortie : 0 si tout va bien
 *
 * Sans nom de commande, elle affiche la liste. Avec --help, elle explique la commande.
 *
 * Sécurité (ADR-006 et ADR-026) :
 *   - elle refuse de s'exécuter hors d'un terminal : appelée par un serveur
 *     web, elle donnerait à un visiteur les pouvoirs du développeur ;
 *   - une erreur affiche son message, jamais la valeur d'un argument ni une trace.
 */
final class Application
{
    /** Tout s'est bien passé. */
    public const int SUCCESS = 0;

    /** La commande a échoué. */
    public const int FAILURE = 1;

    /** La commande a été mal écrite (nom inconnu, option en trop...). */
    public const int USAGE_ERROR = 2;

    private const string COMMAND_NAME = '/^[a-z][a-z0-9-]*(?::[a-z][a-z0-9-]*)*$/D';

    /** @var array<string, Command> */
    private array $commands = [];

    /**
     * @param string $name ce qu'on tape pour lancer la console, cité dans l'aide
     * @param string $sapi la façon dont PHP a été lancé ; « cli » dans un terminal
     */
    public function __construct(private readonly string $name = 'wazi', private readonly string $sapi = PHP_SAPI) {}

    /**
     * @throws ConsoleException si le nom de la commande est mal formé, ou déjà pris
     */
    public function add(Command $command): void
    {
        $name = $command->name();

        if (preg_match(self::COMMAND_NAME, $name) !== 1) {
            throw ConsoleException::invalidName('une commande', $name);
        }

        if (isset($this->commands[$name])) {
            throw ConsoleException::duplicateCommand($name);
        }

        $this->commands[$name] = $command;
        ksort($this->commands);
    }

    /**
     * Exécute ce qui a été tapé. Ne lève jamais d'exception : une erreur est
     * écrite dans le terminal, et devient un code de sortie.
     *
     * @param list<string> $argv   ce que PHP a reçu : le nom du script, puis ce qui a été tapé
     * @param Output|null  $output où écrire ; par défaut, le terminal
     *
     * @return int le code de sortie, à donner à exit()
     */
    public function run(array $argv, ?Output $output = null): int
    {
        $output ??= new Output(STDOUT, STDERR);
        $tokens = array_slice($argv, 1);

        try {
            if ($this->sapi !== 'cli' && $this->sapi !== 'phpdbg') {
                throw ConsoleException::notACommandLine($this->sapi);
            }

            $name = array_shift($tokens);

            if ($name === null || $name === '--help' || $name === 'list') {
                $this->showList($output);

                return self::SUCCESS;
            }

            $command = $this->commands[$name] ?? throw ConsoleException::unknownCommand($name, array_keys($this->commands), $this->name);

            if (in_array('--help', $tokens, true)) {
                $this->showHelp($command, $output);

                return self::SUCCESS;
            }

            $input = Input::parse($command, $tokens, $this->name);
        } catch (ConsoleException $exception) {
            $output->error($exception->getMessage());

            return self::USAGE_ERROR;
        }

        try {
            return $command->run($input, $output);
        } catch (\Throwable $error) {
            // Le message, la sorte d'erreur et l'endroit : de quoi corriger.
            // Jamais la trace, qui contient les valeurs passées aux fonctions.
            $output->error($error->getMessage());
            $output->line('  ' . $error::class . ', dans ' . $error->getFile() . ', ligne ' . $error->getLine());

            return self::FAILURE;
        }
    }

    // ------------------------------------------------------------------
    // Aide
    // ------------------------------------------------------------------

    private function showList(Output $output): void
    {
        $output->title('La console de Wazi');
        $output->line('Utilisation : ' . $this->name . ' <commande> [arguments] [--options]');
        $output->line();

        if ($this->commands === []) {
            $output->line('Aucune commande n\'est déclarée.');

            return;
        }

        $output->line('Commandes :');
        $output->definitions(array_map(static fn(Command $command): string => $command->description(), $this->commands));
        $output->line();
        $output->line('Pour le détail d\'une commande : ' . $this->name . ' <commande> --help');
    }

    private function showHelp(Command $command, Output $output): void
    {
        $usage = $this->name . ' ' . $command->name();
        $arguments = [];
        $options = [];

        foreach ($command->arguments() as $argument) {
            // Un argument facultatif s'écrit entre crochets, par convention.
            $usage .= $argument->default === null ? ' <' . $argument->name . '>' : ' [' . $argument->name . ']';
            $arguments[$argument->name] = $argument->description . ($argument->default !== null ? ' (par défaut : ' . $argument->default . ')' : '');
        }

        foreach ($command->options() as $option) {
            $options[$option->isFlag() ? '--' . $option->name : '--' . $option->name . '=…'] = $option->description
                . ($option->isFlag() ? '' : ' (par défaut : ' . $option->default . ')');
        }

        $output->title($command->description());
        $output->line('Utilisation : ' . $usage . ($options !== [] ? ' [--options]' : ''));

        if ($arguments !== []) {
            $output->line();
            $output->line('Arguments :');
            $output->definitions($arguments);
        }

        if ($options !== []) {
            $output->line();
            $output->line('Options :');
            $output->definitions($options);
        }
    }
}
