<?php

declare(strict_types=1);

namespace Wazi\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Wazi\Http\Exception\InvalidMessageException;
use Wazi\Http\Exception\RequestRejectedException;
use Wazi\Http\ServerRequestCreator;
use Wazi\Http\Stream;
use Wazi\Http\UploadedFile;

final class ServerRequestCreatorTest extends TestCase
{
    /** Ce qu'un serveur web met dans $_SERVER pour « GET https://exemple.com/articles?page=2 ». */
    private const array SERVER = [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/articles?page=2',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'HTTPS' => 'on',
        'HTTP_HOST' => 'exemple.com',
        'HTTP_ACCEPT_LANGUAGE' => 'fr',
        'SERVER_NAME' => 'exemple.com',
        'SERVER_PORT' => '443',
        'REMOTE_ADDR' => '203.0.113.7',
    ];

    // --- Construction ------------------------------------------------------

    public function testItBuildsTheRequestFromServerValues(): void
    {
        $request = new ServerRequestCreator()->fromArrays(self::SERVER);

        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://exemple.com/articles?page=2', (string) $request->getUri());
        self::assertSame('1.1', $request->getProtocolVersion());
        self::assertSame('fr', $request->getHeaderLine('Accept-Language'));
        self::assertSame(self::SERVER, $request->getServerParams());
    }

    public function testItCarriesQueryCookiesAndBody(): void
    {
        $request = new ServerRequestCreator()->fromArrays(
            self::SERVER,
            ['page' => '2'],
            [],
            ['session' => 'abc'],
            [],
            'corps brut',
        );

        self::assertSame(['page' => '2'], $request->getQueryParams());
        self::assertSame(['session' => 'abc'], $request->getCookieParams());
        self::assertSame('corps brut', (string) $request->getBody());
    }

    public function testAnEmptyServerGivesAMinimalRequest(): void
    {
        $request = new ServerRequestCreator()->fromArrays([]);

        self::assertSame('GET', $request->getMethod());
        self::assertSame('/', (string) $request->getUri());
        self::assertSame('1.1', $request->getProtocolVersion());
        self::assertSame([], $request->getHeaders());
    }

    public function testFromGlobalsReadsThePhpGlobals(): void
    {
        $previous = [$_SERVER, $_GET, $_COOKIE];
        $_SERVER = self::SERVER;
        $_GET = ['page' => '2'];
        $_COOKIE = ['session' => 'abc'];

        try {
            $request = new ServerRequestCreator()->fromGlobals();
        } finally {
            [$_SERVER, $_GET, $_COOKIE] = $previous;
        }

        self::assertSame('https://exemple.com/articles?page=2', (string) $request->getUri());
        self::assertSame(['page' => '2'], $request->getQueryParams());
        self::assertSame(['session' => 'abc'], $request->getCookieParams());
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function serverProtocols(): iterable
    {
        yield 'HTTP/1.0' => ['HTTP/1.0', '1.0'];
        yield 'HTTP/2' => ['HTTP/2', '2'];
        yield 'HTTP/2.0' => ['HTTP/2.0', '2.0'];
        yield 'absent' => [null, '1.1'];
        yield 'illisible' => ["HTTP/1.1\r\nX: y", '1.1'];
    }

    #[DataProvider('serverProtocols')]
    public function testTheProtocolVersionComesFromTheServer(mixed $protocol, string $expected): void
    {
        $request = new ServerRequestCreator()->fromArrays(['SERVER_PROTOCOL' => $protocol]);

        self::assertSame($expected, $request->getProtocolVersion());
    }

    // --- En-têtes ----------------------------------------------------------

    public function testHeadersAreRebuiltFromTheirServerKeys(): void
    {
        $request = new ServerRequestCreator()->fromArrays([
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '2',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_' => 'ignoré',
        ]);

        self::assertSame(
            ['X-Requested-With' => ['XMLHttpRequest'], 'Content-Type' => ['application/json'], 'Content-Length' => ['2']],
            $request->getHeaders(),
        );
    }

    public function testTheAuthorizationHeaderMovedByApacheIsRecovered(): void
    {
        $request = new ServerRequestCreator()->fromArrays(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer jeton']);

        self::assertSame('Bearer jeton', $request->getHeaderLine('Authorization'));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function malformedRequests(): iterable
    {
        yield 'en-tête avec injection' => [['HTTP_X_TEST' => "a\r\nSet-Cookie: session=piege"]];
        yield 'méthode avec espace' => [['REQUEST_METHOD' => 'GET POST']];
        yield 'adresse avec espace' => [['HTTP_HOST' => 'exemple.com', 'REQUEST_URI' => '/mon article']];
        yield 'adresse complète invalide' => [['REQUEST_URI' => 'C:/Program Files/Git/']];
    }

    /**
     * Ces défauts viennent du client : ils donnent un refus (400) que le
     * framework sait traiter, pas une erreur de programmation.
     *
     * @param array<string, string> $server
     */
    #[DataProvider('malformedRequests')]
    public function testAMalformedRequestIsRejectedWithA400(array $server): void
    {
        try {
            new ServerRequestCreator()->fromArrays($server);
            self::fail('Une exception était attendue.');
        } catch (RequestRejectedException $exception) {
            self::assertSame(400, $exception->getStatusCode());
            self::assertInstanceOf(\InvalidArgumentException::class, $exception->getPrevious());
            self::assertStringNotContainsString("\n", $exception->getMessage());
            self::assertStringNotContainsString('piege', $exception->getMessage());
        }
    }

    // --- URI ---------------------------------------------------------------

    public function testTheSchemeIsHttpWithoutHttps(): void
    {
        $server = ['HTTPS' => 'off', 'HTTP_HOST' => 'exemple.com', 'REQUEST_URI' => '/'];

        self::assertSame('http://exemple.com/', (string) new ServerRequestCreator()->fromArrays($server)->getUri());
    }

    public function testTheHostHeaderPortIsKept(): void
    {
        $server = ['HTTP_HOST' => 'localhost:8000', 'REQUEST_URI' => '/'];

        self::assertSame('http://localhost:8000/', (string) new ServerRequestCreator()->fromArrays($server)->getUri());
    }

    public function testWithoutHostHeaderTheServerNameIsUsed(): void
    {
        $server = ['SERVER_NAME' => 'exemple.com', 'SERVER_PORT' => '8080', 'REQUEST_URI' => '/page'];

        self::assertSame('http://exemple.com:8080/page', (string) new ServerRequestCreator()->fromArrays($server)->getUri());
    }

    public function testAnIpv6ServerNameGetsItsBrackets(): void
    {
        $server = ['SERVER_NAME' => '::1', 'SERVER_PORT' => '8000', 'REQUEST_URI' => '/'];

        self::assertSame('http://[::1]:8000/', (string) new ServerRequestCreator()->fromArrays($server)->getUri());
    }

    public function testAnAbsoluteRequestTargetOnlyGivesItsPathAndQuery(): void
    {
        $server = ['HTTP_HOST' => 'exemple.com', 'REQUEST_URI' => 'http://pirate.com/page?a=1'];

        self::assertSame('http://exemple.com/page?a=1', (string) new ServerRequestCreator()->fromArrays($server)->getUri());
    }

    // --- Sécurité : hôte ---------------------------------------------------

    /**
     * Une cible « //pirate.com/page » ne doit jamais devenir l'hôte de la requête.
     */
    public function testALeadingDoubleSlashNeverBecomesTheHost(): void
    {
        $creator = new ServerRequestCreator();

        $withHost = $creator->fromArrays(['HTTP_HOST' => 'exemple.com', 'REQUEST_URI' => '//pirate.com/page'])->getUri();
        $withoutHost = $creator->fromArrays(['REQUEST_URI' => '//pirate.com/page'])->getUri();

        self::assertSame('exemple.com', $withHost->getHost());
        self::assertSame('//pirate.com/page', $withHost->getPath());
        self::assertSame('', $withoutHost->getHost());
        self::assertSame('/pirate.com/page', $withoutHost->getPath());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forgedHosts(): iterable
    {
        yield 'chemin' => ['exemple.com/chemin'];
        yield 'utilisateur' => ['admin@pirate.com'];
        yield 'arobase seule' => ['@pirate.com'];
        yield 'requête' => ['exemple.com?a=1'];
        yield 'fragment' => ['exemple.com#x'];
        yield 'espace' => ['exemple.com pirate.com'];
        yield 'retour à la ligne' => ["exemple.com\npirate.com"];
        yield 'barre oblique inversée' => ['exemple.com\\@pirate.com'];
        yield 'port non numérique' => ['exemple.com:abc'];
    }

    #[DataProvider('forgedHosts')]
    public function testAForgedHostHeaderIsRejected(string $host): void
    {
        try {
            new ServerRequestCreator()->fromArrays(['HTTP_HOST' => $host, 'REQUEST_URI' => '/']);
            self::fail('Une exception était attendue.');
        } catch (RequestRejectedException $exception) {
            self::assertSame(400, $exception->getStatusCode());
            self::assertStringNotContainsString("\n", $exception->getMessage());
        }
    }

    public function testWithoutTrustedHostsAnyValidHostIsAccepted(): void
    {
        $request = new ServerRequestCreator()->fromArrays(['HTTP_HOST' => 'nimporte-quoi.test', 'REQUEST_URI' => '/']);

        self::assertSame('nimporte-quoi.test', $request->getUri()->getHost());
    }

    public function testATrustedHostIsAcceptedWhateverItsCaseAndPort(): void
    {
        $creator = new ServerRequestCreator(trustedHosts: ['Exemple.com']);

        $request = $creator->fromArrays(['HTTP_HOST' => 'EXEMPLE.COM:8443', 'REQUEST_URI' => '/']);

        self::assertSame('exemple.com:8443', $request->getUri()->getAuthority());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function untrustedHosts(): iterable
    {
        yield 'autre domaine' => ['pirate.com'];
        yield 'sous-domaine' => ['admin.exemple.com'];
        yield 'domaine qui commence pareil' => ['exemple.com.pirate.com'];
        yield 'domaine qui finit pareil' => ['pirate-exemple.com'];
    }

    #[DataProvider('untrustedHosts')]
    public function testAnUntrustedHostIsRejected(string $host): void
    {
        $creator = new ServerRequestCreator(trustedHosts: ['exemple.com']);

        try {
            $creator->fromArrays(['HTTP_HOST' => $host, 'REQUEST_URI' => '/']);
            self::fail('Une exception était attendue.');
        } catch (RequestRejectedException $exception) {
            self::assertSame(400, $exception->getStatusCode());
            self::assertStringContainsString('trustedHosts', $exception->getMessage());
        }
    }

    // --- Sécurité : en-têtes de proxy et méthode ---------------------------

    /**
     * N'importe quel client peut écrire ces en-têtes : ils ne changent rien
     * à la requête construite.
     */
    public function testForwardedHeadersNeverChangeTheRequest(): void
    {
        $request = new ServerRequestCreator()->fromArrays([
            'HTTP_HOST' => 'exemple.com',
            'REQUEST_URI' => '/',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'pirate.com',
            'HTTP_X_FORWARDED_PORT' => '8443',
            'HTTP_FORWARDED' => 'host=pirate.com;proto=https',
        ]);

        self::assertSame('http://exemple.com/', (string) $request->getUri());
        self::assertSame('pirate.com', $request->getHeaderLine('X-Forwarded-Host'), "L'en-tête reste lisible tel qu'il a été reçu.");
    }

    public function testTheMethodCannotBeOverridden(): void
    {
        $request = new ServerRequestCreator()->fromArrays(
            [
                'REQUEST_METHOD' => 'POST',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE',
            ],
            [],
            ['_method' => 'DELETE'],
        );

        self::assertSame('POST', $request->getMethod());
    }

    // --- Sécurité : taille du corps ----------------------------------------

    public function testABodyWithinTheLimitIsAccepted(): void
    {
        $request = new ServerRequestCreator(maxBodySize: 100)->fromArrays(['CONTENT_LENGTH' => '100']);

        self::assertSame('100', $request->getHeaderLine('Content-Length'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function oversizedContentLengths(): iterable
    {
        yield 'un octet de trop' => ['101'];
        yield 'énorme' => ['99999999999999999999999999'];
    }

    #[DataProvider('oversizedContentLengths')]
    public function testABodyOverTheLimitIsRejectedBeforeBeingRead(string $length): void
    {
        $body = Stream::fromString('corps');
        $body->seek(2);

        try {
            new ServerRequestCreator(maxBodySize: 100)->fromArrays(['CONTENT_LENGTH' => $length], [], [], [], [], $body);
            self::fail('Une exception était attendue.');
        } catch (RequestRejectedException $exception) {
            self::assertSame(413, $exception->getStatusCode());
            self::assertStringContainsString('maxBodySize', $exception->getMessage());
            self::assertSame(2, $body->tell(), "Le corps n'a pas été lu.");
        }
    }

    public function testTheDefaultLimitIsEightMegabytes(): void
    {
        $creator = new ServerRequestCreator();

        self::assertSame(8 * 1024 * 1024, ServerRequestCreator::DEFAULT_MAX_BODY_SIZE);

        $creator->fromArrays(['CONTENT_LENGTH' => '8388608']);

        $this->expectException(RequestRejectedException::class);

        $creator->fromArrays(['CONTENT_LENGTH' => '8388609']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidContentLengths(): iterable
    {
        yield 'négatif' => ['-1'];
        yield 'texte' => ['beaucoup'];
        yield 'deux valeurs' => ['10, 20'];
        yield 'signe plus' => ['+10'];
        yield 'virgule' => ['10.5'];
        yield 'espace' => [' 10'];
        yield 'tableau' => [['10']];
    }

    #[DataProvider('invalidContentLengths')]
    public function testAnInvalidContentLengthIsRejected(mixed $length): void
    {
        try {
            new ServerRequestCreator()->fromArrays(['CONTENT_LENGTH' => $length]);
            self::fail('Une exception était attendue.');
        } catch (RequestRejectedException $exception) {
            self::assertSame(400, $exception->getStatusCode());
        }
    }

    public function testANegativeLimitIsRejected(): void
    {
        $this->expectException(InvalidMessageException::class);

        new ServerRequestCreator(maxBodySize: -1);
    }

    // --- Corps analysé -----------------------------------------------------

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function formSubmissions(): iterable
    {
        yield 'formulaire simple' => ['POST', 'application/x-www-form-urlencoded', true];
        yield 'formulaire avec fichiers' => ['POST', 'multipart/form-data; boundary=----abc', true];
        yield 'casse différente' => ['POST', 'Application/X-WWW-Form-Urlencoded; charset=UTF-8', true];
        yield 'JSON' => ['POST', 'application/json', false];
        yield 'sans type' => ['POST', '', false];
        yield 'GET' => ['GET', 'application/x-www-form-urlencoded', false];
        yield 'PUT' => ['PUT', 'application/x-www-form-urlencoded', false];
    }

    #[DataProvider('formSubmissions')]
    public function testTheParsedBodyIsOnlySetForAFormSentByPost(string $method, string $contentType, bool $isForm): void
    {
        $post = ['titre' => 'Bonjour'];

        $request = new ServerRequestCreator()->fromArrays(
            ['REQUEST_METHOD' => $method, 'CONTENT_TYPE' => $contentType],
            [],
            $post,
        );

        self::assertSame($isForm ? $post : null, $request->getParsedBody());
    }

    // --- Fichiers envoyés --------------------------------------------------

    public function testASingleFileBecomesAnUploadedFile(): void
    {
        $request = new ServerRequestCreator()->fromArrays([], [], [], [], [
            'avatar' => ['name' => 'photo.png', 'type' => 'image/png', 'tmp_name' => '/tmp/php123', 'error' => UPLOAD_ERR_OK, 'size' => 42],
        ]);

        $avatar = $request->getUploadedFiles()['avatar'];

        self::assertInstanceOf(UploadedFile::class, $avatar);
        self::assertSame('photo.png', $avatar->getClientFilename());
        self::assertSame('image/png', $avatar->getClientMediaType());
        self::assertSame(42, $avatar->getSize());
        self::assertSame(UPLOAD_ERR_OK, $avatar->getError());
    }

    public function testAFailedUploadKeepsItsErrorCode(): void
    {
        $request = new ServerRequestCreator()->fromArrays([], [], [], [], [
            'avatar' => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0],
        ]);

        $avatar = $request->getUploadedFiles()['avatar'];

        self::assertInstanceOf(UploadedFile::class, $avatar);
        self::assertSame(UPLOAD_ERR_NO_FILE, $avatar->getError());
    }

    public function testMultipleFilesAreRearrangedOneObjectPerFile(): void
    {
        $request = new ServerRequestCreator()->fromArrays([], [], [], [], [
            'photos' => [
                'name' => ['a.png', 'b.png'],
                'type' => ['image/png', 'image/png'],
                'tmp_name' => ['/tmp/phpA', '/tmp/phpB'],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size' => [10, 20],
            ],
        ]);

        $photos = $request->getUploadedFiles()['photos'];

        self::assertIsArray($photos);
        self::assertCount(2, $photos);
        self::assertInstanceOf(UploadedFile::class, $photos[1]);
        self::assertSame('b.png', $photos[1]->getClientFilename());
        self::assertSame(20, $photos[1]->getSize());
    }

    public function testDeeplyNestedFilesAreRearrangedToo(): void
    {
        $request = new ServerRequestCreator()->fromArrays([], [], [], [], [
            'profil' => [
                'name' => ['documents' => ['cv' => 'cv.pdf']],
                'type' => ['documents' => ['cv' => 'application/pdf']],
                'tmp_name' => ['documents' => ['cv' => '/tmp/phpC']],
                'error' => ['documents' => ['cv' => UPLOAD_ERR_OK]],
                'size' => ['documents' => ['cv' => 30]],
            ],
        ]);

        $files = $request->getUploadedFiles();

        self::assertIsArray($files['profil']);
        self::assertIsArray($files['profil']['documents']);

        $cv = $files['profil']['documents']['cv'];

        self::assertInstanceOf(UploadedFile::class, $cv);
        self::assertSame('cv.pdf', $cv->getClientFilename());
        self::assertSame(30, $cv->getSize());
    }

    public function testAnUploadedFileObjectIsKeptAsIs(): void
    {
        $file = self::createStub(UploadedFileInterface::class);

        $request = new ServerRequestCreator()->fromArrays([], [], [], [], ['avatar' => $file]);

        self::assertSame(['avatar' => $file], $request->getUploadedFiles());
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function malformedFiles(): iterable
    {
        yield 'texte' => [['avatar' => '/etc/passwd']];
        yield 'sans code d\'erreur' => [['avatar' => ['tmp_name' => '/tmp/php123']]];
        yield 'code d\'erreur inconnu' => [['avatar' => ['tmp_name' => '/tmp/php123', 'error' => 99]]];
        yield 'code d\'erreur en texte' => [['avatar' => ['tmp_name' => '/tmp/php123', 'error' => '0']]];
        yield 'chemin à protocole' => [['avatar' => ['tmp_name' => 'phar://piege.phar/x', 'error' => UPLOAD_ERR_OK]]];
        yield 'chemin qui n\'est pas un texte' => [['avatar' => ['tmp_name' => 42, 'error' => UPLOAD_ERR_OK]]];
        yield 'chemin vide alors que l\'envoi a réussi' => [['avatar' => ['tmp_name' => '', 'error' => UPLOAD_ERR_OK]]];
    }

    /**
     * @param array<array-key, mixed> $files
     */
    #[DataProvider('malformedFiles')]
    public function testMalformedFilesAreRejected(array $files): void
    {
        try {
            new ServerRequestCreator()->fromArrays([], [], [], [], $files);
            self::fail('Une exception était attendue.');
        } catch (RequestRejectedException $exception) {
            self::assertSame(400, $exception->getStatusCode());
            self::assertStringNotContainsString('passwd', $exception->getMessage());
            self::assertStringNotContainsString('piege', $exception->getMessage());
        }
    }
}
