<?php

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as RispostaPsr;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\BackofficeNonRisponde;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Errori\GettoneRifiutato;
use Zeiras\Auth\Scaricamento;
use Zeiras\Auth\Testing\BackofficeFinto;

// T6 (#1906, S.1-S.5): Api::scarica, una GET di /v1 passata a flusso per le risposte che non sono JSON (l'esportazione CSV del CRM).

const SCARICA_CSV = '/v1/crm/persone/esportazione';

/** Una risposta CSV come la dà il backoffice. */
function fileCsv(string $corpo = "id,nome\r\n1,Anna\r\n", string $disposizione = 'attachment; filename="contatti-2026-10-13.csv"', string $tipo = 'text/csv; charset=utf-8'): Closure
{
    return fn () => Http::response($corpo, 200, ['Content-Type' => $tipo, 'Content-Disposition' => $disposizione]);
}

function problemaJson(int $stato, string $codice, array $header = []): Closure
{
    return fn () => Http::response(json_encode(['type' => "https://docs.zeiras.com/v1/errori/{$codice}", 'title' => 'T', 'status' => $stato, 'detail' => 'D', 'codice' => $codice]), $stato, ['Content-Type' => 'application/problem+json', ...$header]);
}

it('scarica un file dello stesso host, col gettone del workspace e Accept dei tipi ammessi, e dà il tipo, il nome e il corpo a pezzi (S.1, S.2)', function () {
    Http::fake([API.'/*' => fileCsv()()]);
    apriSessione();

    $file = Api::workspace()->scarica(SCARICA_CSV, ['stato' => 'lead']);

    expect($file)->toBeInstanceOf(Scaricamento::class)
        ->and($file->tipo)->toBe('text/csv')
        ->and($file->nome)->toBe('contatti-2026-10-13.csv')
        ->and(implode('', iterator_to_array($file->blocchi(4), false)))->toBe("id,nome\r\n1,Anna\r\n");
    Http::assertSent(fn (Request $r) => $r->url() === API.SCARICA_CSV.'?stato=lead'
        && $r->method() === 'GET'
        && $r->header('Authorization') === ['Bearer '.GETTONE_WORKSPACE]
        && $r->header('Accept') === ['text/csv, application/zip']);
});

it('accetta solo un percorso dentro /v1 senza query: un indirizzo intero, un percorso fuori da /v1, una query nel percorso non partono (S.1)', function (string $percorso) {
    Http::fake();
    apriSessione();

    expect(fn () => Api::workspace()->scarica($percorso))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    'un indirizzo intero' => ['https://altrove.example/v1/crm/persone/esportazione'],
    'senza barra' => ['//altrove.example/v1/x'],
    'fuori da /v1' => ['/crm/persone/esportazione'],
    'la query nel percorso' => ['/v1/crm/persone/esportazione?stato=lead'],
    'un frammento' => ['/v1/crm/persone/esportazione#x'],
    'un segmento ..' => ['/v1/../up'],
    'un prefisso che non è /v1' => ['/v1x/crm/persone/esportazione'],
]);

it('un redirect non si segue, nemmeno verso un altro host (S.1)', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://altrove.example/file.csv'])]);
    apriSessione();

    expect(fn () => Api::workspace()->scarica(SCARICA_CSV))->toThrow(BackofficeNonRisponde::class);
    Http::assertSentCount(1);
});

it('senza sessione apre GettoneRifiutato, e senzaGettone() manda la firma Zr-* come ogni chiamata (S.2)', function () {
    Http::fake(['*' => fileCsv()()]);

    expect(fn () => Api::workspace()->scarica(SCARICA_CSV))->toThrow(GettoneRifiutato::class);

    config(['zr-auth.cliente' => BackofficeFinto::CLIENTE, 'zr-auth.segreto' => BackofficeFinto::SEGRETO_DEL_CLIENTE]);
    Api::senzaGettone()->scarica(SCARICA_CSV);

    Http::assertSent(fn (Request $r) => ! $r->hasHeader('Authorization') && $r->hasHeader('Zr-Firma') && $r->header('Zr-Cliente') === [BackofficeFinto::CLIENTE]);
});

it('gli errori problem+json sono quelli di ogni chiamata: ErroreApi, e il 429 col suo Retry-After (S.3)', function (int $stato, string $codice, array $header, ?int $riprova) {
    Http::fake(['*' => problemaJson($stato, $codice, $header)()]);
    apriSessione();

    try {
        Api::workspace()->scarica(SCARICA_CSV);
        $errore = null;
    } catch (ErroreApi $e) {
        $errore = $e;
    }

    expect($errore)->toBeInstanceOf(ErroreApi::class)
        ->and($errore->stato)->toBe($stato)->and($errore->codice)->toBe($codice)->and($errore->riprovaFra)->toBe($riprova);
})->with([
    '403' => [403, 'permesso_negato', [], null],
    '422' => [422, 'dati_non_validi', [], null],
    '429' => [429, 'troppe_richieste', ['Retry-After' => '120'], 120],
]);

it('un 401 è il gettone rifiutato, un 5xx senza JSON il backoffice che non risponde (S.3)', function () {
    apriSessione();
    Http::fake(['*' => problemaJson(401, 'gettone_non_valido')()]);
    expect(fn () => Api::workspace()->scarica(SCARICA_CSV))->toThrow(GettoneRifiutato::class);

    Http::swap(new Factory);
    Http::fake(['*' => Http::response('<html>Bad Gateway</html>', 502, ['Content-Type' => 'text/html'])]);
    expect(fn () => Api::workspace()->scarica(SCARICA_CSV))->toThrow(BackofficeNonRisponde::class);
});

it('un errore JSON non arriva mai al chiamante come un file da salvare, nemmeno con un 200 (S.3)', function () {
    apriSessione();
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    expect(fn () => Api::workspace()->scarica(SCARICA_CSV))->toThrow(BackofficeNonRisponde::class);
});

it('un tipo che non è nell\'elenco chiuso non passa: text/html, application/json, nessun tipo (S.5)', function (?string $tipo) {
    apriSessione();
    Http::fake(['*' => Http::response('<script>alert(1)</script>', 200, $tipo === null ? [] : ['Content-Type' => $tipo])]);

    expect(fn () => Api::workspace()->scarica(SCARICA_CSV))->toThrow(BackofficeNonRisponde::class);
})->with(['text/html', 'text/html; charset=utf-8', 'application/json', 'application/octet-stream', 'text/csv-con-altro', null]);

it('il nome del file è ripulito: niente percorsi, caratteri di controllo, a capo (S.5)', function (string $disposizione, string $atteso) {
    expect(Scaricamento::nomeDi($disposizione))->toBe($atteso);
})->with([
    'un nome buono' => ['attachment; filename="contatti-2026-10-13.csv"', 'contatti-2026-10-13.csv'],
    'senza virgolette' => ['attachment; filename=aziende.csv', 'aziende.csv'],
    'risalita di cartelle' => ['attachment; filename="../../x"', 'x.csv'],
    'un percorso' => ['attachment; filename="/etc/passwd"', 'passwd.csv'],
    'cartelle alla Windows' => ['attachment; filename="..\\..\\boot.ini"', 'boot.ini.csv'],
    'un a capo' => ["attachment; filename=\"a\r\nSet-Cookie: x=1.csv\"", 'aSet-Cookie-x-1.csv'],
    'un carattere di controllo' => ["attachment; filename=\"a\x00b\x1f.csv\"", 'ab.csv'],
    'virgolette e punti e virgola dentro' => ['attachment; filename="a;b\'c.csv"', 'a-b-c.csv'],
    'un nome che sparisce' => ['attachment; filename="..."', Scaricamento::NOME_PREDEFINITO],
    'senza filename' => ['attachment', Scaricamento::NOME_PREDEFINITO],
    'nessuna intestazione' => ['', Scaricamento::NOME_PREDEFINITO],
    'troppo lungo' => ['attachment; filename="'.str_repeat('a', 300).'.csv"', str_repeat('a', 100).'.csv'],
]);

it('le intestazioni per il browser: tipo, nome ripulito, nosniff e no-store (S.5)', function () {
    Http::fake(['*' => fileCsv(disposizione: 'attachment; filename="../../x"')()]);
    apriSessione();

    $risposta = Api::workspace()->scarica(SCARICA_CSV)->comeRisposta();

    expect($risposta->headers->get('Content-Type'))->toBe('text/csv; charset=utf-8')
        ->and($risposta->headers->get('Content-Disposition'))->toBe('attachment; filename="x.csv"')
        ->and($risposta->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($risposta->headers->get('Cache-Control'))->toContain('no-store');

    ob_start();
    $risposta->sendContent();

    expect(ob_get_clean())->toBe("id,nome\r\n1,Anna\r\n");
});

it('il corpo non passa mai da una stringa intera: lo legge a blocchi, e Api::scarica non lo tocca (S.4)', function () {
    $letture = [];
    $flusso = new class($letture) implements StreamInterface
    {
        private int $dati = 0;

        public function __construct(private array &$letture) {}

        public function __toString(): string
        {
            throw new LogicException('Il corpo intero non si legge.');
        }

        public function close(): void {}

        public function detach()
        {
            return null;
        }

        public function getSize(): ?int
        {
            return null;
        }

        public function tell(): int
        {
            return $this->dati;
        }

        public function eof(): bool
        {
            return $this->dati >= 50 * 1024 * 1024;
        }

        public function isSeekable(): bool
        {
            return false;
        }

        public function seek(int $offset, int $whence = SEEK_SET): void {}

        public function rewind(): void {}

        public function isWritable(): bool
        {
            return false;
        }

        public function write(string $string): int
        {
            return 0;
        }

        public function isReadable(): bool
        {
            return true;
        }

        public function read(int $length): string
        {
            $this->letture[] = $length;
            $this->dati += $length;

            return str_repeat('x', $length);
        }

        public function getContents(): string
        {
            throw new LogicException('Il corpo intero non si legge.');
        }

        public function getMetadata(?string $key = null)
        {
            return null;
        }
    };
    Http::fake(['*' => fn () => Create::promiseFor(new RispostaPsr(200, ['Content-Type' => 'text/csv'], $flusso))]);
    apriSessione();

    $file = Api::workspace()->scarica(SCARICA_CSV);

    expect($letture)->toBe([]);

    $byte = 0;
    $piuGrande = 0;
    foreach ($file->blocchi(1024 * 1024) as $blocco) {
        $byte += strlen($blocco);
        $piuGrande = max($piuGrande, strlen($blocco));
    }

    expect($byte)->toBe(50 * 1024 * 1024)->and($piuGrande)->toBe(1024 * 1024);
});

it('la chiamata ha un tempo massimo dichiarato, e la lettura lo rispetta (S.4)', function () {
    $opzioni = [];
    Http::fake(function (Request $richiesta, array $o) use (&$opzioni) {
        $opzioni = $o;

        return Http::response("id\r\n", 200, ['Content-Type' => 'text/csv']);
    });
    apriSessione();

    Api::workspace()->scarica(SCARICA_CSV);

    expect(config('zr-auth.scarico'))->toBe(60)
        ->and($opzioni['timeout'])->toBe(60)->and($opzioni['connect_timeout'])->toBe(2)->and($opzioni['stream'])->toBeTrue()
        ->and($opzioni['read_timeout'])->toBe(10);

    config(['zr-auth.scarico' => 1]);
    $lento = new class(Utils::streamFor(str_repeat('x', 10))) implements StreamInterface
    {
        public function __construct(private StreamInterface $dentro) {}

        public function __toString(): string
        {
            return (string) $this->dentro;
        }

        public function close(): void
        {
            $this->dentro->close();
        }

        public function detach()
        {
            return $this->dentro->detach();
        }

        public function getSize(): ?int
        {
            return $this->dentro->getSize();
        }

        public function tell(): int
        {
            return $this->dentro->tell();
        }

        public function eof(): bool
        {
            return $this->dentro->eof();
        }

        public function isSeekable(): bool
        {
            return false;
        }

        public function seek(int $offset, int $whence = SEEK_SET): void {}

        public function rewind(): void {}

        public function isWritable(): bool
        {
            return false;
        }

        public function write(string $string): int
        {
            return 0;
        }

        public function isReadable(): bool
        {
            return true;
        }

        public function read(int $length): string
        {
            usleep(1_200_000);

            return $this->dentro->read(1);
        }

        public function getContents(): string
        {
            return $this->dentro->getContents();
        }

        public function getMetadata(?string $key = null)
        {
            return $this->dentro->getMetadata($key);
        }
    };
    Http::swap(new Factory);
    Http::fake(['*' => fn () => Create::promiseFor(new RispostaPsr(200, ['Content-Type' => 'text/csv'], $lento))]);

    $file = Api::workspace()->scarica(SCARICA_CSV);

    expect(fn () => iterator_to_array($file->blocchi(1), false))->toThrow(BackofficeNonRisponde::class);
});


it('una lettura che cade o scade a metà è BackofficeNonRisponde, e il flusso si chiude (S.4)', function () {
    $cade = new GuzzleHttp\Psr7\PumpStream(fn () => throw new RuntimeException('Unable to read from stream'));
    Http::swap(new Factory);
    Http::fake(['*' => fn () => Create::promiseFor(new RispostaPsr(200, ['Content-Type' => 'text/csv'], $cade))]);
    apriSessione();

    $file = Api::workspace()->scarica(SCARICA_CSV);

    expect(fn () => iterator_to_array($file->blocchi(), false))->toThrow(BackofficeNonRisponde::class);
});

it('un errore dopo il primo byte non accoda la pagina d\'errore al file: finisce nel log e il file finisce lì (S.4)', function () {
    $cade = new GuzzleHttp\Psr7\PumpStream(fn () => throw new RuntimeException('Unable to read from stream'));
    Http::swap(new Factory);
    Http::fake(['*' => fn () => Create::promiseFor(new RispostaPsr(200, ['Content-Type' => 'text/csv'], $cade))]);
    apriSessione();
    $segnalati = [];
    app()->instance(Illuminate\Contracts\Debug\ExceptionHandler::class, new class($segnalati) implements Illuminate\Contracts\Debug\ExceptionHandler
    {
        public function __construct(public array &$segnalati) {}

        public function report(Throwable $e): void
        {
            $this->segnalati[] = $e;
        }

        public function shouldReport(Throwable $e): bool
        {
            return true;
        }

        public function render($request, Throwable $e)
        {
            return response('');
        }

        public function renderForConsole($output, Throwable $e): void {}
    });

    ob_start();
    Api::workspace()->scarica(SCARICA_CSV)->comeRisposta()->sendContent();
    $uscito = ob_get_clean();

    expect($uscito)->toBe('')->and($segnalati)->toHaveCount(1)->and($segnalati[0])->toBeInstanceOf(BackofficeNonRisponde::class);
});

// #1959 (zr-home): lo ZIP di «Esporta i miei dati» passa come il CSV; il nome prende l'estensione dal tipo.

it('scarica uno ZIP: il tipo, il nome con .zip e le intestazioni per il browser senza charset (#1959)', function () {
    Http::fake(['*' => Http::response("PK\x05\x06".str_repeat("\0", 18), 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename=zeiras-i-miei-dati.zip'])]);
    apriSessione();

    $file = Api::persona()->scarica('/v1/io/esportazioni/01k7n4r6t8v0x2z4b6d8f0h2j4/archivio');
    $risposta = $file->comeRisposta();

    expect($file->tipo)->toBe('application/zip')
        ->and($file->nome)->toBe('zeiras-i-miei-dati.zip')
        ->and($risposta->headers->get('Content-Type'))->toBe('application/zip')
        ->and($risposta->headers->get('Content-Disposition'))->toBe('attachment; filename="zeiras-i-miei-dati.zip"')
        ->and($risposta->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('il nome di uno ZIP prende .zip, e senza un nome che valga è zeiras-i-miei-dati.zip; un CSV resta .csv (#1959)', function () {
    expect(Scaricamento::nomeDi('attachment; filename="../x"', 'application/zip'))->toBe('x.zip')
        ->and(Scaricamento::nomeDi('attachment; filename=a.csv', 'application/zip'))->toBe('a.csv.zip')
        ->and(Scaricamento::nomeDi('attachment', 'application/zip'))->toBe('zeiras-i-miei-dati.zip')
        ->and(Scaricamento::nomeDi('attachment; filename="../x"'))->toBe('x.csv')
        ->and(Scaricamento::nomeDi('attachment'))->toBe(Scaricamento::NOME_PREDEFINITO);
});

it('un 403 conferma_richiesta e un 404 non_trovato dello scaricamento sono ErroreApi col loro codice (#1959)', function (int $stato, string $codice) {
    Http::fake(['*' => problemaJson($stato, $codice)()]);
    apriSessione();

    try {
        Api::persona()->scarica('/v1/io/esportazioni/01k7n4r6t8v0x2z4b6d8f0h2j4/archivio');
        $preso = null;
    } catch (ErroreApi $errore) {
        $preso = $errore;
    }

    expect($preso)->toBeInstanceOf(ErroreApi::class)
        ->and($preso->codice)->toBe($codice);
})->with([[403, 'conferma_richiesta'], [404, 'non_trovato']]);

// #2015: Api::scaricaConPost, la POST senza corpo a flusso (workspace.esportazioni.scaricamento.crea).

const SCARICA_ZIP_POST = '/v1/workspace/esportazioni/01k7n4r6t8v0x2z4b6d8f0h2j4/scaricamento';

it('scaricaConPost: una POST senza query, col gettone e Accept dei tipi ammessi, dà il tipo zip, il nome del backoffice e il corpo a pezzi', function () {
    Http::fake([API.'/*' => Http::response("PK\x05\x06".str_repeat("\0", 18), 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename=zeiras-workspace-dati.zip'])]);
    apriSessione();

    $file = Api::workspace()->scaricaConPost(SCARICA_ZIP_POST);

    expect($file)->toBeInstanceOf(Scaricamento::class)
        ->and($file->tipo)->toBe('application/zip')
        ->and($file->nome)->toBe('zeiras-workspace-dati.zip')
        ->and(strlen(implode('', iterator_to_array($file->blocchi(5), false))))->toBe(22);
    Http::assertSent(fn (Request $r) => $r->url() === API.SCARICA_ZIP_POST
        && $r->method() === 'POST'
        && $r->header('Authorization') === ['Bearer '.GETTONE_WORKSPACE]
        && $r->header('Accept') === ['text/csv, application/zip']);
});

it('scaricaConPost: un percorso fuori da /v1, con una query o con un segmento .. non parte', function (string $percorso) {
    Http::fake();
    apriSessione();

    expect(fn () => Api::workspace()->scaricaConPost($percorso))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['/up', 'https://altrove.example/v1/x', SCARICA_ZIP_POST.'?x=1', '/v1/../up']);

it('scaricaConPost: gli errori sono quelli di ogni chiamata, il 403 conferma_richiesta compreso', function (int $stato, string $codice) {
    Http::fake(['*' => problemaJson($stato, $codice)()]);
    apriSessione();

    try {
        Api::workspace()->scaricaConPost(SCARICA_ZIP_POST);
        $errore = null;
    } catch (ErroreApi $e) {
        $errore = $e;
    }

    expect($errore)->toBeInstanceOf(ErroreApi::class)->and($errore->stato)->toBe($stato)->and($errore->codice)->toBe($codice);
})->with([[403, 'conferma_richiesta'], [404, 'non_trovato'], [429, 'troppe_richieste']]);

it('scaricaConPost: un 401 è il gettone rifiutato; un JSON con un 200 e un 5xx senza JSON non sono un file', function () {
    apriSessione();

    Http::fake(['*' => problemaJson(401, 'gettone_non_valido')()]);
    expect(fn () => Api::workspace()->scaricaConPost(SCARICA_ZIP_POST))->toThrow(GettoneRifiutato::class);

    Http::swap(new Factory);
    Http::fake(['*' => Http::response(['data' => []], 200)]);
    expect(fn () => Api::workspace()->scaricaConPost(SCARICA_ZIP_POST))->toThrow(BackofficeNonRisponde::class);

    Http::swap(new Factory);
    Http::fake(['*' => Http::response('<html>Bad Gateway</html>', 502, ['Content-Type' => 'text/html'])]);
    expect(fn () => Api::workspace()->scaricaConPost(SCARICA_ZIP_POST))->toThrow(BackofficeNonRisponde::class);
});

