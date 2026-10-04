<?php

declare(strict_types=1);

namespace Wazi\Validation;

/**
 * Vérifie ce qu'un formulaire a envoyé, champ par champ.
 *
 *     $v = new Validator($request->getParsedBody());
 *
 *     $nom   = $v->text('nom', max: 80);                    // un texte
 *     $age   = $v->integer('age', min: 18);                 // un nombre entier
 *     $email = $v->email('email');                          // une adresse e-mail
 *     $sujet = $v->choice('sujet', ['devis', 'question']);  // une valeur d'une liste
 *     $texte = $v->longText('message', required: false);    // un texte de plusieurs lignes, facultatif
 *
 *     if ($v->fails()) {
 *         // On réaffiche le formulaire, avec ce qui a été saisi et ce qui ne va pas.
 *         return $this->kioo->page('contact', ['saisie' => $v->input(), 'erreurs' => $v->errors()], 422);
 *     }
 *
 * Chaque méthode fait trois choses : elle lit le champ, le vérifie, et
 * retourne sa valeur dans le bon type. Si le champ ne convient pas, elle note
 * un message d'erreur pour lui, et retourne une valeur vide.
 *
 * Par défaut, un champ est OBLIGATOIRE et BORNÉ : un texte fait 255
 * caractères au plus. Ce qui est plus souple s'écrit (required: false, max: 2000).
 *
 * Sécurité (ADR-031) :
 *   - un champ qui n'est pas un texte (un tableau, par exemple) est refusé ;
 *   - un texte contenant des caractères de contrôle, ou qui n'est pas de
 *     l'UTF-8 valide, est refusé ;
 *   - un message d'erreur ne recopie jamais la valeur reçue ;
 *   - values() ne contient que les champs que vous avez vérifiés : un champ
 *     ajouté à la main par un visiteur n'y entre jamais ;
 *   - input() ne contient jamais un mot de passe.
 */
final class Validator
{
    /** Tout caractère de contrôle, sauf la tabulation et le retour à la ligne. */
    private const string CONTROL = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

    /** Des chiffres, un signe éventuel, pas de zéro inutile : le nombre tient dans un entier PHP. */
    private const string INTEGER = '/^-?(?:0|[1-9]\d{0,17})$/D';

    /** Un nombre à virgule, écrit avec un point ou une virgule : 12, 12.5, 12,50. */
    private const string DECIMAL = '/^-?\d{1,15}(?:[.,]\d{1,10})?$/D';

    /** Ce qu'envoie un champ <input type="date"> : 2026-10-04. */
    private const string DATE = '/^\d{4}-\d{2}-\d{2}$/D';

    /** Ce qu'envoie une case cochée, selon la valeur écrite dans le formulaire. */
    private const array CHECKED = ['1', 'on', 'true', 'oui'];

    /** @var array<array-key, mixed> ce que le formulaire a envoyé */
    private array $data;

    /** @var array<string, string> champ => message d'erreur */
    private array $errors = [];

    /** @var array<string, mixed> champ => valeur vérifiée */
    private array $values = [];

    /** @var array<string, string> champ => texte saisi, à réafficher */
    private array $input = [];

    /**
     * @param array<array-key, mixed>|object|null $data les champs reçus : $request->getParsedBody(), ou $request->getQueryParams()
     */
    public function __construct(array|object|null $data)
    {
        // Sans formulaire (ou avec autre chose qu'un formulaire), il n'y a aucun champ.
        $this->data = is_array($data) ? $data : [];
    }

    // ------------------------------------------------------------------
    // Vérifier un champ
    // ------------------------------------------------------------------

    /**
     * Un texte court, sur une seule ligne : un nom, un titre.
     *
     * @param int $max le nombre maximal de caractères ; 255 par défaut
     *
     * @return string le texte, sans les espaces du début et de la fin ; '' s'il est absent ou refusé
     */
    public function text(string $field, bool $required = true, int $min = 0, int $max = 255, ?string $message = null): string
    {
        $text = $this->read($field, $required, $message);

        if ($text === null || $text === '') {
            return $this->keep($field, '');
        }

        if (str_contains($text, "\n") || str_contains($text, "\r")) {
            return $this->reject($field, $message ?? 'Ce champ tient sur une seule ligne.', '');
        }

        return $this->bounded($field, $text, $min, $max, $message);
    }

    /**
     * Un texte de plusieurs lignes : un message, une description.
     *
     * @param int $max le nombre maximal de caractères ; 5 000 par défaut
     *
     * @return string le texte, sans les espaces du début et de la fin ; '' s'il est absent ou refusé
     */
    public function longText(string $field, bool $required = true, int $min = 0, int $max = 5000, ?string $message = null): string
    {
        $text = $this->read($field, $required, $message);

        if ($text === null || $text === '') {
            return $this->keep($field, '');
        }

        // Un navigateur envoie les retours à la ligne sous la forme « \r\n » :
        // on les ramène à « \n », pour qu'un retour compte pour un caractère.
        return $this->bounded($field, str_replace(["\r\n", "\r"], "\n", $text), $min, $max, $message);
    }

    /**
     * Un nombre entier.
     *
     * @return int|null le nombre ; null s'il est absent ou refusé
     */
    public function integer(string $field, bool $required = true, ?int $min = null, ?int $max = null, ?string $message = null): ?int
    {
        $text = $this->read($field, $required, $message);

        if ($text === null || $text === '') {
            return $this->keep($field, null);
        }

        // Sécurité : seuls des chiffres. « 1e3 », « 0x1A » ou « 12abc » ne sont
        // pas convertis à la façon de PHP : ils sont refusés.
        if (preg_match(self::INTEGER, $text) !== 1) {
            return $this->reject($field, $message ?? 'Écrivez un nombre entier, en chiffres.', null);
        }

        $number = (int) $text;
        $error = self::outOfRange($number, $min, $max);

        return $error === null ? $this->keep($field, $number) : $this->reject($field, $message ?? $error, null);
    }

    /**
     * Un nombre à virgule : un prix, une mesure. La virgule et le point sont acceptés.
     *
     * @return float|null le nombre ; null s'il est absent ou refusé
     */
    public function decimal(string $field, bool $required = true, ?float $min = null, ?float $max = null, ?string $message = null): ?float
    {
        $text = $this->read($field, $required, $message);

        if ($text === null || $text === '') {
            return $this->keep($field, null);
        }

        if (preg_match(self::DECIMAL, $text) !== 1) {
            return $this->reject($field, $message ?? 'Écrivez un nombre, en chiffres : 12 ou 12,5.', null);
        }

        $number = (float) str_replace(',', '.', $text);
        $error = self::outOfRange($number, $min, $max);

        return $error === null ? $this->keep($field, $number) : $this->reject($field, $message ?? $error, null);
    }

    /**
     * Une adresse e-mail.
     *
     * La forme de l'adresse est vérifiée, pas son existence : seul un message
     * envoyé à cette adresse prouve qu'elle est bien celle du visiteur.
     *
     * @return string l'adresse ; '' si elle est absente ou refusée
     */
    public function email(string $field, bool $required = true, ?string $message = null): string
    {
        $text = $this->read($field, $required, $message);

        if ($text === null || $text === '') {
            return $this->keep($field, '');
        }

        // 254 caractères : la longueur maximale d'une adresse.
        if (strlen($text) > 254 || filter_var($text, FILTER_VALIDATE_EMAIL) === false) {
            return $this->reject($field, $message ?? 'Cette adresse e-mail n\'a pas la bonne forme. Exemple : prenom@exemple.com', '');
        }

        return $this->keep($field, $text);
    }

    /**
     * Une valeur à choisir dans une liste : un <select>, des boutons radio.
     *
     * Sécurité : la liste affichée par le formulaire ne protège rien, un
     * visiteur peut envoyer n'importe quelle valeur. Seule cette vérification compte.
     *
     * @param list<string> $allowed les valeurs permises
     *
     * @return string la valeur choisie ; '' si elle est absente ou refusée
     */
    public function choice(string $field, array $allowed, bool $required = true, ?string $message = null): string
    {
        $text = $this->read($field, $required, $message);

        if ($text === null || $text === '') {
            return $this->keep($field, '');
        }

        if (!in_array($text, $allowed, true)) {
            return $this->reject($field, $message ?? 'Choisissez une des valeurs proposées.', '');
        }

        return $this->keep($field, $text);
    }

    /**
     * Une case à cocher. Une case non cochée n'est pas envoyée du tout : ce
     * n'est donc jamais une erreur.
     */
    public function checkbox(string $field): bool
    {
        $value = $this->data[$field] ?? null;
        $checked = is_string($value) && in_array(strtolower($value), self::CHECKED, true);

        $this->values[$field] = $checked;

        return $checked;
    }

    /**
     * Une date, telle que l'envoie un champ <input type="date"> : 2026-10-04.
     *
     * @return \DateTimeImmutable|null la date, à minuit ; null si elle est absente ou refusée
     */
    public function date(
        string $field,
        bool $required = true,
        ?\DateTimeInterface $min = null,
        ?\DateTimeInterface $max = null,
        ?string $message = null,
    ): ?\DateTimeImmutable {
        $text = $this->read($field, $required, $message);

        if ($text === null || $text === '') {
            return $this->keep($field, null);
        }

        $date = preg_match(self::DATE, $text) === 1 ? \DateTimeImmutable::createFromFormat('!Y-m-d', $text) : false;

        // PHP accepte le 31 février et le reporte en mars : on vérifie que la
        // date obtenue est bien celle qui a été écrite.
        if ($date === false || $date->format('Y-m-d') !== $text) {
            return $this->reject($field, $message ?? 'Cette date n\'existe pas. Écrivez-la sous la forme 2026-10-04.', null);
        }

        if ($min !== null && $date < $min) {
            return $this->reject($field, $message ?? 'Choisissez une date à partir du ' . $min->format('d/m/Y') . '.', null);
        }

        if ($max !== null && $date > $max) {
            return $this->reject($field, $message ?? 'Choisissez une date jusqu\'au ' . $max->format('d/m/Y') . '.', null);
        }

        return $this->keep($field, $date);
    }

    /**
     * Un mot de passe.
     *
     * Il est pris tel quel (les espaces comptent), et il n'est JAMAIS rendu
     * par input() : un mot de passe ne se réaffiche pas.
     *
     * @param int $min le nombre minimal de caractères ; 8 par défaut
     *
     * @return string le mot de passe ; '' s'il est absent ou refusé
     */
    public function password(string $field, int $min = 8, int $max = 200, ?string $message = null): string
    {
        $value = $this->data[$field] ?? null;

        if (!is_string($value) || $value === '') {
            return $this->rejectSilently($field, $message ?? ($value === null || $value === '' ? 'Ce champ est obligatoire.' : 'Ce champ n\'a pas la forme attendue.'));
        }

        if (preg_match('//u', $value) !== 1 || preg_match(self::CONTROL, $value) === 1) {
            return $this->rejectSilently($field, $message ?? 'Ce mot de passe contient des caractères qui ne sont pas acceptés.');
        }

        $length = mb_strlen($value);

        if ($length < $min) {
            return $this->rejectSilently($field, $message ?? 'Écrivez au moins ' . $min . ' caractères.');
        }

        if ($length > $max) {
            return $this->rejectSilently($field, $message ?? 'Écrivez ' . $max . ' caractères au maximum.');
        }

        $this->values[$field] = $value;

        return $value;
    }

    /**
     * Une règle propre à votre application.
     *
     *     $v->check('email', !$comptes->existe($email), 'Cette adresse est déjà utilisée.');
     *
     * Si la condition est fausse, le message devient l'erreur du champ, sauf
     * s'il en a déjà une : la première erreur d'un champ est celle qu'on affiche.
     */
    public function check(string $field, bool $condition, string $message): void
    {
        if (!$condition && !isset($this->errors[$field])) {
            $this->errors[$field] = $message;
            unset($this->values[$field]);
        }
    }

    // ------------------------------------------------------------------
    // Le résultat
    // ------------------------------------------------------------------

    /**
     * Vrai si au moins un champ est refusé.
     */
    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Ce qui ne va pas : un message par champ refusé, à afficher près du champ.
     *
     * @return array<string, string> champ => message
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Ce que le visiteur a saisi, pour réafficher le formulaire sans qu'il
     * ait tout à retaper. Chaque champ vérifié y figure, sauf les mots de passe.
     *
     * @return array<string, string> champ => texte saisi
     */
    public function input(): array
    {
        return $this->input;
    }

    /**
     * Les valeurs vérifiées, dans leur type. Seuls les champs que vous avez
     * vérifiés et qui conviennent y figurent.
     *
     * @return array<string, mixed> champ => valeur
     */
    public function values(): array
    {
        return $this->values;
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * Lit un champ comme un texte, débarrassé des espaces qui l'entourent.
     *
     * @return string|null le texte ('' si le champ est vide ou absent) ; null si une erreur vient d'être notée
     */
    private function read(string $field, bool $required, ?string $message): ?string
    {
        $value = $this->data[$field] ?? '';

        // Sécurité : « nom[]=x » envoie un tableau. Ce n'est pas un texte.
        if (!is_string($value)) {
            $this->input[$field] = '';
            $this->errors[$field] = $message ?? 'Ce champ n\'a pas la forme attendue.';

            return null;
        }

        // Sécurité : ni octets qui ne forment pas de l'UTF-8, ni caractères de
        // contrôle. On ne les réaffiche pas non plus.
        if (preg_match('//u', $value) !== 1 || preg_match(self::CONTROL, $value) === 1) {
            $this->input[$field] = '';
            $this->errors[$field] = $message ?? 'Ce champ contient des caractères qui ne sont pas acceptés.';

            return null;
        }

        $text = trim($value);
        $this->input[$field] = $text;

        if ($text === '' && $required) {
            $this->errors[$field] = $message ?? 'Ce champ est obligatoire.';

            return null;
        }

        return $text;
    }

    private function bounded(string $field, string $text, int $min, int $max, ?string $message): string
    {
        $length = mb_strlen($text);

        if ($length > $max) {
            return $this->reject($field, $message ?? 'Écrivez ' . $max . ' caractères au maximum (vous en avez écrit ' . $length . ').', '');
        }

        if ($length < $min) {
            return $this->reject($field, $message ?? 'Écrivez au moins ' . $min . ' caractères.', '');
        }

        return $this->keep($field, $text);
    }

    private static function outOfRange(int|float $number, int|float|null $min, int|float|null $max): ?string
    {
        return match (true) {
            $min !== null && $max !== null && ($number < $min || $number > $max) => 'Écrivez un nombre entre ' . self::plain($min) . ' et ' . self::plain($max) . '.',
            $min !== null && $number < $min => 'Écrivez un nombre à partir de ' . self::plain($min) . '.',
            $max !== null && $number > $max => 'Écrivez un nombre jusqu\'à ' . self::plain($max) . '.',
            default => null,
        };
    }

    /**
     * Un nombre écrit comme on l'écrit en français : 12,5.
     */
    private static function plain(int|float $number): string
    {
        return str_replace('.', ',', (string) $number);
    }

    /**
     * Garde une valeur vérifiée, et la retourne.
     *
     * @template T
     *
     * @param T $value
     *
     * @return T
     */
    private function keep(string $field, mixed $value): mixed
    {
        // Un champ déjà refusé (obligatoire et vide, par exemple) n'est pas gardé.
        if (!isset($this->errors[$field])) {
            $this->values[$field] = $value;
        }

        return $value;
    }

    /**
     * Note une erreur, et retourne la valeur vide de ce type de champ.
     *
     * @template T
     *
     * @param T $empty
     *
     * @return T
     */
    private function reject(string $field, string $message, mixed $empty): mixed
    {
        $this->errors[$field] = $message;

        return $empty;
    }

    /**
     * Note l'erreur d'un mot de passe, sans rien garder de ce qui a été saisi.
     */
    private function rejectSilently(string $field, string $message): string
    {
        $this->errors[$field] = $message;

        return '';
    }
}
