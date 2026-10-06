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
 * Sans nom de commande, elle affiche son écran d'accueil : le nom en grand, la
 * version, et les commandes rangées par famille. Avec --help, elle explique
 * une commande : description, écriture, arguments, options, et, si la
 * commande les fournit (DetailedCommand), des exemples et un texte d'aide.
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

    /** « Wazi », en grand : la première chose qu'on voit en tapant « wazi ». */
    private const array BANNER = [
        '██╗    ██╗ █████╗ ███████╗██╗',
        '██║    ██║██╔══██╗╚══███╔╝██║',
        '██║ █╗ ██║███████║  ███╔╝ ██║',
        '██║███╗██║██╔══██║ ███╔╝  ██║',
        '╚███╔███╔╝██║  ██║███████╗██║',
        ' ╚══╝╚══╝ ╚═╝  ╚═╝╚══════╝╚═╝',
    ];

    private const string COMMAND_NAME = '/^[a-z][a-z0-9-]*(?::[a-z][a-z0-9-]*)*$/D';

    /** @var array<string, Command> */
    private array $commands = [];

    /**
     * @param string      $name    ce qu'on tape pour lancer la console, cité dans l'aide
     * @param string      $sapi    la façon dont PHP a été lancé ; « cli » dans un terminal
     * @param string|null $version la version à afficher ; par défaut, celle de Wazi que Composer a installée
     */
    public function __construct(
        private readonly string $name = 'wazi',
        private readonly string $sapi = PHP_SAPI,
        private readonly ?string $version = null,
    ) {}

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

    /**
     * L'écran d'accueil : le nom en grand, la version, puis les commandes,
     * rangées par famille (« db: », « make: »...).
     */
    private function showList(Output $output): void
    {
        foreach (self::BANNER as $line) {
            $output->accent($line);
        }

        $output->line();
        $output->title('Wazi ' . $this->version() . ' · le framework PHP où tout est clair');

        $output->section('Utilisation :');
        $output->line('  ' . $this->name . ' <commande> [arguments] [--options]');
        $output->line();

        $output->section('Options :');
        $output->definitions(['--help' => 'Explique une commande, sans l\'exécuter : ' . $this->name . ' serve --help']);
        $output->line();

        if ($this->commands === []) {
            $output->line('Aucune commande n\'est déclarée.');

            return;
        }

        // « db:migrate » appartient à la famille « db » ; « serve » n'en a pas.
        $families = [];
        $width = 0;

        foreach ($this->commands as $name => $command) {
            $family = str_contains($name, ':') ? strstr($name, ':', true) : '';
            $families[$family][$name] = $command->description();
            $width = max($width, mb_strlen($name));
        }

        // Les commandes sans famille d'abord, puis les familles dans l'ordre alphabétique.
        uksort($families, static fn(int|string $a, int|string $b): int => [$a !== '', (string) $a] <=> [$b !== '', (string) $b]);

        $output->section('Commandes :');

        foreach ($families as $family => $commands) {
            if ($family !== '') {
                $output->section(' ' . $family);
            }

            // La même largeur pour toutes les familles : les descriptions s'alignent.
            $output->definitions($commands, $width);
        }

        $output->line();
        $output->note('Pour le détail d\'une commande : ' . $this->name . ' <commande> --help');
    }

    /**
     * L'aide d'une commande : ce qu'elle fait, comment l'écrire, ce qu'elle accepte.
     */
    private function showHelp(Command $command, Output $output): void
    {
        $usage = $this->name . ' ' . $command->name();
        $arguments = [];
        // --help existe pour toutes les commandes : elle est listée avec les autres.
        $options = [];

        foreach ($command->arguments() as $argument) {
            // Un argument facultatif s'écrit entre crochets, par convention.
            $usage .= $argument->default === null ? ' <' . $argument->name . '>' : ' [' . $argument->name . ']';
            // Un défaut vide veut dire « facultatif » : il n'y a rien à montrer.
            $arguments[$argument->name] = $argument->description . ($argument->default !== null && $argument->default !== '' ? ' (par défaut : ' . $argument->default . ')' : '');
        }

        foreach ($command->options() as $option) {
            $options[$option->isFlag() ? '--' . $option->name : '--' . $option->name . '=…'] = $option->description
                . ($option->isFlag() ? '' : ' (par défaut : ' . $option->default . ')');
        }

        $usage .= $options !== [] ? ' [--options]' : '';
        $options['--help'] = 'Affiche cette aide, sans exécuter la commande';

        $output->section('Description :');
        $output->line('  ' . $command->description());
        $output->line();

        $output->section('Utilisation :');
        $output->line('  ' . $usage);

        if ($arguments !== []) {
            $output->line();
            $output->section('Arguments :');
            $output->definitions($arguments);
        }

        $output->line();
        $output->section('Options :');
        $output->definitions($options);

        if (!$command instanceof DetailedCommand) {
            return;
        }

        if ($command->examples() !== []) {
            $examples = [];

            foreach ($command->examples() as $typed => $meaning) {
                $examples[rtrim($this->name . ' ' . $command->name() . ' ' . $typed)] = $meaning;
            }

            $output->line();
            $output->section('Exemples :');
            $output->definitions($examples);
        }

        if (trim($command->help()) !== '') {
            $output->line();
            $output->section('Aide :');

            foreach (explode("\n", trim($command->help())) as $line) {
                $output->line(rtrim('  ' . $line));
            }
        }
    }

    /**
     * La version de Wazi installée, telle que Composer la connaît.
     */
    private function version(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $version = class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('wazi/framework')
            ? \Composer\InstalledVersions::getPrettyVersion('wazi/framework')
            : null;

        // « dev-main » : le code du dépôt, pas une version publiée.
        return $version === null || str_starts_with($version, 'dev-') ? '(version de développement)' : $version;
    }
}
