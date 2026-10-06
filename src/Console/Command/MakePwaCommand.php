<?php

declare(strict_types=1);

namespace Wazi\Console\Command;

use Wazi\Console\Application;
use Wazi\Console\Argument;
use Wazi\Console\DetailedCommand;
use Wazi\Console\Input;
use Wazi\Console\Option;
use Wazi\Console\Output;

/**
 * « wazi make:pwa "Mon carnet" » : rend le site installable, comme une application.
 *
 * Une application installable (« PWA ») est un site que l'on ajoute à l'écran
 * d'accueil d'un téléphone ou au bureau d'un ordinateur. Il lui faut :
 *
 *     public/manifest.webmanifest   sa fiche : nom, couleurs, icône
 *     public/service-worker.js      un script que le navigateur garde, et qui
 *                                   répond quand le réseau manque
 *     public/pwa.js                 la ligne qui enregistre ce script
 *     public/hors-ligne.html        la page affichée sans réseau
 *     public/icone.svg              une icône provisoire, à remplacer
 *
 * La commande crée ces cinq fichiers. Ils sont à vous : lisez-les, modifiez-les.
 *
 * Le service worker créé est volontairement prudent (ADR-037) : il ne garde
 * AUCUNE page de votre site, seulement quelques fichiers fixes, et demande
 * toujours au réseau d'abord. Il ne peut donc ni montrer la page d'un autre
 * visiteur, ni masquer une mise en ligne.
 *
 * Sécurité (ADR-028, ADR-037) :
 *   - un fichier qui existe déjà n'est jamais remplacé, et tout est vérifié
 *     avant la première écriture : ou les cinq fichiers sont créés, ou aucun ;
 *   - le nom de l'application est vérifié, puis écrit par json_encode() dans
 *     le manifeste et échappé dans les pages : il ne peut rien y injecter ;
 *   - la mise en page n'est pas modifiée : la commande affiche les lignes à ajouter.
 */
final readonly class MakePwaCommand implements DetailedCommand
{
    private const int NAME_MAX = 60;

    /** La couleur de la barre du navigateur et de l'icône provisoire. À changer dans les fichiers créés. */
    private const string COLOR = '#0B6E70';

    /** Les lignes à ajouter à la mise en page, avant </head>. */
    private const array LAYOUT_LINES = [
        '<link rel="manifest" href="/manifest.webmanifest">',
        '<meta name="theme-color" content="' . self::COLOR . '">',
        '<script src="/pwa.js" defer></script>',
    ];

    /**
     * @param string|null $directory le dossier du projet ; celui où la commande est tapée, par défaut
     */
    public function __construct(private ?string $directory = null) {}

    public function name(): string
    {
        return 'make:pwa';
    }

    public function description(): string
    {
        return 'Rend le site installable comme une application : manifeste, service worker, page hors ligne.';
    }

    public function arguments(): array
    {
        return [new Argument('nom', 'Le nom de l\'application, entre guillemets s\'il contient un espace ; par défaut, celui du dossier', '')];
    }

    public function options(): array
    {
        return [new Option('no-comments', 'Créer les scripts sans les commentaires d\'explication')];
    }

    public function help(): string
    {
        return implode("\n", [
            'Crée cinq fichiers dans public/, puis affiche les trois lignes à ajouter',
            'à votre mise en page. Aucun fichier existant n\'est remplacé.',
            '',
            'Le service worker créé ne garde aucune page de votre site : il affiche',
            'hors-ligne.html quand le réseau manque, rien de plus. Vous pouvez le lire',
            'et l\'étendre.',
            '',
            'Une application ne s\'installe que depuis un site en HTTPS, ou depuis',
            'localhost pendant que vous développez.',
        ]);
    }

    public function examples(): array
    {
        return [
            '"Mon carnet"' => 'Une application nommée « Mon carnet »',
            '' => 'Le nom du dossier du projet sert de nom',
        ];
    }

    public function run(Input $input, Output $output): int
    {
        $project = $this->directory ?? (string) getcwd();

        if (!is_dir($project . '/public')) {
            $output->error(
                'Le dossier public/ est introuvable ici. Lancez cette commande depuis le dossier de votre projet,'
                . ' celui qui contient app.php et le dossier public/.',
            );

            return Application::FAILURE;
        }

        $typed = trim($input->argument('nom'));
        // Sans nom tapé, celui du dossier ; s'il ne convient pas non plus, un nom neutre.
        $name = $typed !== '' ? $typed : basename(str_replace('\\', '/', $project));

        if (!self::isValidName($name)) {
            if ($typed === '') {
                $name = 'Mon application';
            } else {
                $output->error(
                    'Ce nom ne convient pas pour une application. Écrivez un nom de ' . self::NAME_MAX . ' caractères au plus,'
                    . ' sur une seule ligne : wazi make:pwa "Mon carnet".',
                );

                return Application::USAGE_ERROR;
            }
        }

        $withComments = !$input->flag('no-comments');

        $files = [
            'public/manifest.webmanifest' => self::manifest($name),
            'public/service-worker.js' => self::script(self::serviceWorker(), $withComments),
            'public/pwa.js' => self::script(self::registration(), $withComments),
            'public/hors-ligne.html' => self::offlinePage($name),
            'public/icone.svg' => self::icon($name),
        ];

        // On vérifie tout avant d'écrire quoi que ce soit : ou les cinq
        // fichiers sont créés, ou aucun.
        foreach (array_keys($files) as $file) {
            if (file_exists($project . '/' . $file) || is_link($project . '/' . $file)) {
                $output->error('Le fichier ' . $file . ' existe déjà. Rien n\'a été modifié : renommez ou supprimez ce fichier vous-même, puis relancez la commande.');

                return Application::FAILURE;
            }
        }

        foreach ($files as $file => $content) {
            if (!self::create($project . '/' . $file, $content)) {
                $output->error('Le fichier ' . $file . ' n\'a pas pu être créé. Vérifiez que vous avez le droit d\'écrire dans ce dossier.');

                return Application::FAILURE;
            }

            $output->success('Créé : ' . $file);
        }

        $output->line();
        $output->line('Il reste trois lignes à ajouter à votre mise en page, avant </head> :');
        $output->line();

        foreach (self::LAYOUT_LINES as $line) {
            $output->line('    ' . $line);
        }

        $output->line();
        $output->line('Puis rechargez le site : votre navigateur propose de l\'installer.');
        $output->note('L\'icône créée est provisoire : remplacez public/icone.svg par la vôtre.');

        return Application::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * Un nom d'application : du texte bien encodé, sur une ligne, ni vide ni trop long.
     */
    private static function isValidName(string $name): bool
    {
        return $name !== ''
            && mb_check_encoding($name, 'UTF-8')
            && mb_strlen($name) <= self::NAME_MAX
            // Ni retour à la ligne, ni caractère de contrôle.
            && preg_match('/[\x00-\x1F\x7F]/', $name) !== 1;
    }

    /**
     * Crée un fichier, et seulement s'il n'existe pas.
     */
    private static function create(string $file, string $content): bool
    {
        // Le mode « x » échoue si le fichier existe : même créé entre notre
        // vérification et cette ligne, il ne serait pas remplacé.
        $handle = @fopen($file, 'x');

        if ($handle === false) {
            return false;
        }

        $written = fwrite($handle, $content);
        fclose($handle);

        return $written !== false;
    }

    /**
     * Le manifeste : la fiche de l'application, lue par le navigateur.
     *
     * json_encode() écrit le nom : quoi qu'il contienne, il reste un texte.
     */
    private static function manifest(string $name): string
    {
        return json_encode([
            'name' => $name,
            // Le nom court s'affiche sous l'icône : une douzaine de caractères tiennent.
            'short_name' => mb_substr($name, 0, 12),
            'lang' => 'fr',
            'id' => '/',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#FFFFFF',
            'theme_color' => self::COLOR,
            'icons' => [
                ['src' => '/icone.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Assemble un script. Sans commentaires, les lignes d'explication sont retirées.
     *
     * @param list<string> $lines
     */
    private static function script(array $lines, bool $withComments): string
    {
        if (!$withComments) {
            $kept = [];

            foreach ($lines as $line) {
                if (str_starts_with(ltrim($line), '//')) {
                    continue;
                }

                // Pas deux lignes vides de suite, ni de ligne vide en tête.
                if ($line === '' && ($kept === [] || $kept[array_key_last($kept)] === '')) {
                    continue;
                }

                $kept[] = $line;
            }

            $lines = $kept;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return list<string>
     */
    private static function serviceWorker(): array
    {
        return [
            '// Le service worker de votre application. Créé par « wazi make:pwa », il est à vous.',
            '//',
            '// Un service worker est un script que le navigateur garde après la visite, et',
            '// qu\'il place entre vos pages et le réseau. Celui-ci fait une seule chose :',
            '// quand le réseau manque, il affiche la page hors-ligne.html.',
            '//',
            '// Il est prudent, exprès :',
            '//   - il ne garde AUCUNE page de votre site. Une page gardée serait montrée',
            '//     au visiteur suivant sur le même appareil, même après une déconnexion ;',
            '//   - il demande toujours au réseau d\'abord. Une mise en ligne n\'est jamais',
            '//     masquée par une vieille copie ;',
            '//   - il ne touche qu\'aux lectures (GET) de votre propre site. Un formulaire',
            '//     envoyé passe sans qu\'il intervienne.',
            '',
            '// Changez ce numéro quand vous modifiez la liste FICHIERS : le navigateur',
            '// jette alors l\'ancienne réserve et en refait une.',
            'const VERSION = \'v1\';',
            'const RESERVE = \'application-\' + VERSION;',
            '',
            '// La page affichée quand le réseau manque.',
            'const HORS_LIGNE = \'/hors-ligne.html\';',
            '',
            '// Les fichiers gardés pour servir sans réseau. N\'y mettez que des fichiers',
            '// fixes et publics (une feuille de styles, un logo) : jamais une page.',
            'const FICHIERS = [HORS_LIGNE, \'/icone.svg\'];',
            '',
            '// À l\'installation : mettre les fichiers en réserve.',
            'self.addEventListener(\'install\', (event) => {',
            '    event.waitUntil(',
            '        caches.open(RESERVE)',
            '            .then((reserve) => reserve.addAll(FICHIERS))',
            '            // Prendre la place de l\'ancienne version sans attendre la fermeture des onglets.',
            '            .then(() => self.skipWaiting()),',
            '    );',
            '});',
            '',
            '// À l\'activation : jeter les réserves des versions précédentes.',
            'self.addEventListener(\'activate\', (event) => {',
            '    event.waitUntil(',
            '        caches.keys()',
            '            .then((noms) => Promise.all(',
            '                noms',
            '                    .filter((nom) => nom.startsWith(\'application-\') && nom !== RESERVE)',
            '                    .map((nom) => caches.delete(nom)),',
            '            ))',
            '            .then(() => self.clients.claim()),',
            '    );',
            '});',
            '',
            '// À chaque requête d\'une page du site.',
            'self.addEventListener(\'fetch\', (event) => {',
            '    const request = event.request;',
            '    const url = new URL(request.url);',
            '',
            '    // Un envoi de formulaire, ou un autre site : le navigateur fait comme d\'habitude.',
            '    if (request.method !== \'GET\' || url.origin !== self.location.origin) {',
            '        return;',
            '    }',
            '',
            '    // Une page : toujours le réseau. S\'il manque, la page hors ligne.',
            '    if (request.mode === \'navigate\') {',
            '        event.respondWith(fetch(request).catch(() => caches.match(HORS_LIGNE)));',
            '',
            '        return;',
            '    }',
            '',
            '    // Un fichier de la liste : le réseau d\'abord, et sa copie est rafraîchie ;',
            '    // sans réseau, la copie gardée.',
            '    if (FICHIERS.includes(url.pathname)) {',
            '        event.respondWith(',
            '            fetch(request)',
            '                .then((response) => {',
            '                    if (response.ok) {',
            '                        const copie = response.clone();',
            '',
            '                        caches.open(RESERVE).then((reserve) => reserve.put(request, copie));',
            '                    }',
            '',
            '                    return response;',
            '                })',
            '                .catch(() => caches.match(request)),',
            '        );',
            '    }',
            '',
            '    // Tout le reste (vos styles, vos scripts, vos images) : le navigateur fait comme d\'habitude.',
            '});',
        ];
    }

    /**
     * @return list<string>
     */
    private static function registration(): array
    {
        return [
            '// Enregistre le service worker de l\'application (service-worker.js).',
            '// Créé par « wazi make:pwa », ce fichier est à vous.',
            '//',
            '// Un navigateur qui ne connaît pas les service workers ignore ce fichier :',
            '// le site fonctionne sans.',
            'if (\'serviceWorker\' in navigator) {',
            '    // Après le chargement de la page : l\'enregistrement ne la ralentit pas.',
            '    window.addEventListener(\'load\', () => {',
            '        navigator.serviceWorker.register(\'/service-worker.js\').catch(() => {',
            '            /* Navigation privée, stockage interdit : le site fonctionne sans. */',
            '        });',
            '    });',
            '}',
        ];
    }

    /**
     * La page affichée sans réseau : fixe, sans rien de personnel, et qui ne
     * charge aucun autre fichier (ils ne seraient pas disponibles).
     */
    private static function offlinePage(string $name): string
    {
        $title = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return implode("\n", [
            '<!DOCTYPE html>',
            '<html lang="fr">',
            '<head>',
            '    <meta charset="utf-8">',
            '    <meta name="viewport" content="width=device-width, initial-scale=1">',
            '    <meta name="color-scheme" content="light dark">',
            '    <title>Hors ligne — ' . $title . '</title>',
            '    <style>',
            '        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; font: 1rem/1.6 system-ui, sans-serif; color: #0D2B30; background: #F3F8F8; }',
            '        main { max-width: 28rem; text-align: center; }',
            '        h1 { font-size: 1.75rem; line-height: 1.2; margin: 0 0 .75rem; }',
            '        p { margin: 0 0 1.5rem; }',
            '        a { display: inline-block; padding: .75rem 1.5rem; border-radius: 10px; color: #FFFFFF; background: ' . self::COLOR . '; font-weight: 600; text-decoration: none; }',
            '        @media (prefers-color-scheme: dark) { body { color: #F3F8F8; background: #071C20; } }',
            '    </style>',
            '</head>',
            '<body>',
            '<main>',
            '    <h1>Vous êtes hors ligne</h1>',
            '    <p>' . $title . ' a besoin du réseau pour afficher cette page. Vérifiez votre connexion, puis réessayez.</p>',
            '    <a href="/">Réessayer</a>',
            '</main>',
            '</body>',
            '</html>',
            '',
        ]);
    }

    /**
     * Une icône provisoire : la première lettre du nom, sur un carré de couleur.
     */
    private static function icon(string $name): string
    {
        $letter = htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1)), ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');

        return implode("\n", [
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512">',
            '    <rect width="512" height="512" rx="96" fill="' . self::COLOR . '"/>',
            '    <text x="256" y="256" text-anchor="middle" dominant-baseline="central" font-family="system-ui, sans-serif" font-size="280" font-weight="700" fill="#FFFFFF">' . $letter . '</text>',
            '</svg>',
            '',
        ]);
    }
}
