<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\BackofficeNonRisponde;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Scaricamento;

// Vetrine di Bookings (#3378-#3380): Api::caricaFile con i campi di testo accanto al file, il verbo e la Idempotency-Key (la copertina è una modifica,
// una voce del materiale è una creazione), e Scaricamento con le immagini (png, jpeg, gif, webp) e `application/octet-stream`.

/** Una risposta con un file, come la dà il backoffice. */
function fileDiTipo(string $tipo, string $corpo = 'BYTE', ?string $disposizione = null): Closure
{
    return fn () => Http::response($corpo, 200, array_filter(['Content-Type' => $tipo, 'Content-Disposition' => $disposizione]));
}

it('caricaFile senza altro resta una POST con _method=PATCH e il solo file, senza Idempotency-Key (come per la foto)', function () {
    Http::fake([API.'/*' => Http::response(['data' => ['ok' => true]], 200)]);
    apriSessione();

    Api::workspace()->caricaFile('/v1/bookings/vetrine/abc/copertina', 'file', 'BYTE', 'copertina.webp');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === API.'/v1/bookings/vetrine/abc/copertina'
        && collect($r->data())->contains(fn ($parte) => ($parte['name'] ?? null) === '_method' && ($parte['contents'] ?? null) === 'PATCH')
        && collect($r->data())->contains(fn ($parte) => ($parte['name'] ?? null) === 'file' && ($parte['filename'] ?? null) === 'copertina.webp')
        && ! $r->hasHeader('Idempotency-Key'));
});

it('caricaFile con verbo POST è una POST vera (niente _method), porta i campi di testo e la Idempotency-Key data (deve fallire se resta _method=PATCH)', function () {
    Http::fake([API.'/*' => Http::response(['data' => ['id' => 'v1']], 201)]);
    apriSessione();

    $r = Api::workspace()->caricaFile('/v1/bookings/vetrine/abc/materiale', 'file', 'PDF', 'scheda.pdf', ['titolo' => 'Scheda', 'visibilita' => 'prenotati'], 'POST', 'chiave-1');

    expect($r)->toBe(['data' => ['id' => 'v1']]);
    Http::assertSent(function (Request $r) {
        $parti = collect($r->data())->mapWithKeys(fn ($parte) => [$parte['name'] => $parte['contents'] ?? null]);

        return $r->method() === 'POST'
            && $parti->has('file') && $parti['titolo'] === 'Scheda' && $parti['visibilita'] === 'prenotati'
            && ! $parti->has('_method')
            && $r->header('Idempotency-Key') === ['chiave-1'];
    });
});

it('caricaFile con verbo PATCH e campi li manda accanto a _method; un verbo che non è POST o PATCH è un errore di chi scrive il codice', function () {
    Http::fake([API.'/*' => Http::response(['data' => []], 200)]);
    apriSessione();

    Api::workspace()->caricaFile('/v1/bookings/vetrine/abc/materiale/m1', 'file', 'PDF', 'scheda.pdf', ['titolo' => 'Nuovo'], 'PATCH', 'chiave-2');

    Http::assertSent(fn (Request $r) => collect($r->data())->contains(fn ($p) => $p['name'] === '_method' && $p['contents'] === 'PATCH')
        && collect($r->data())->contains(fn ($p) => $p['name'] === 'titolo' && $p['contents'] === 'Nuovo')
        && $r->header('Idempotency-Key') === ['chiave-2']);
    expect(fn () => Api::workspace()->caricaFile('/v1/x', 'file', 'B', 'n.pdf', [], 'DELETE'))->toThrow(InvalidArgumentException::class);
});

it('un 409 limite_raggiunto o richiesta_in_corso di un caricamento arriva come ErroreApi', function () {
    Http::fake([API.'/*' => Http::response(['type' => 'https://docs.zeiras.com/v1/errori/limite_raggiunto', 'title' => 'T', 'status' => 409, 'detail' => 'D', 'codice' => 'limite_raggiunto'], 409, ['Content-Type' => 'application/problem+json'])]);
    apriSessione();

    expect(fn () => Api::workspace()->caricaFile('/v1/bookings/vetrine/abc/materiale', 'file', 'X', 'a.pdf', ['titolo' => 'A'], 'POST'))
        ->toThrow(fn (ErroreApi $e) => expect($e->stato)->toBe(409)->and($e->codice)->toBe('limite_raggiunto'));
});

it('Scaricamento ammette png, jpeg, gif, webp e octet-stream: tipo, nome predefinito con la sua estensione (deve fallire se un\'immagine è BackofficeNonRisponde)', function (string $tipo, string $predefinito) {
    Http::fake([API.'/*' => fileDiTipo($tipo)()]);
    apriSessione();

    $file = Api::workspace()->scarica('/v1/bookings/pubblico/pagine/p/vetrine/v/copertina');

    expect($file->tipo)->toBe($tipo)->and($file->nome)->toBe($predefinito)->and(implode('', iterator_to_array($file->blocchi(), false)))->toBe('BYTE');
})->with([
    'png' => ['image/png', 'immagine.png'],
    'jpeg' => ['image/jpeg', 'immagine.jpg'],
    'gif' => ['image/gif', 'immagine.gif'],
    'webp' => ['image/webp', 'immagine.webp'],
    'octet-stream' => ['application/octet-stream', 'file.bin'],
]);

it('il nome di un\'immagine ha sempre la sua estensione e mai un percorso: foto.png con tipo webp diventa foto.png.webp', function () {
    expect(Scaricamento::nomeDi('attachment; filename="../x/foto.png"', 'image/webp'))->toBe('foto.png.webp')
        ->and(Scaricamento::nomeDi('inline; filename="logo.JPG"', 'image/jpeg'))->toBe('logo.JPG');
});

it('inLinea è vero solo per le immagini: pdf, csv, zip e octet-stream si salvano sempre (deve fallire se octet-stream o pdf escono inline)', function (string $tipo, bool $inLinea, string $disposizione) {
    Http::fake([API.'/*' => fileDiTipo($tipo, 'BYTE', 'attachment; filename="nome.bin"')()]);
    apriSessione();

    $file = Api::workspace()->scarica('/v1/x/file');
    $intestazioni = $file->intestazioni();

    expect($file->inLinea())->toBe($inLinea)
        ->and($intestazioni['Content-Disposition'])->toStartWith($disposizione.'; filename="')
        ->and($intestazioni['X-Content-Type-Options'])->toBe('nosniff');
})->with([
    'png' => ['image/png', true, 'inline'],
    'jpeg' => ['image/jpeg', true, 'inline'],
    'gif' => ['image/gif', true, 'inline'],
    'webp' => ['image/webp', true, 'inline'],
    'pdf' => ['application/pdf', false, 'attachment'],
    'csv' => ['text/csv', false, 'attachment'],
    'zip' => ['application/zip', false, 'attachment'],
    'octet-stream' => ['application/octet-stream', false, 'attachment'],
]);

it('un tipo fuori dall\'elenco (svg, html) non è un file ammesso, e la richiesta dichiara nell\'Accept anche le immagini', function () {
    Http::fake([API.'/*' => fileDiTipo('image/svg+xml')()]);
    apriSessione();

    expect(fn () => Api::workspace()->scarica('/v1/x/file'))->toThrow(BackofficeNonRisponde::class);
    Http::assertSent(fn (Request $r) => $r->header('Accept') === [implode(', ', Scaricamento::TIPI)]
        && str_contains($r->header('Accept')[0], 'image/webp') && str_contains($r->header('Accept')[0], 'application/octet-stream'));
});
