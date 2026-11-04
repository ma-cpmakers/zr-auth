<?php

use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Sessione;
use Zeiras\Auth\Testing\BackofficeFinto;

// #2870: il finto di io.foto.* e di foto_aggiornata_il in io.mostra, e Api::immagine / Api::caricaFile che lo chiamano. Che le risposte e gli errori
// siano quelli del backoffice lo prova il FintoTest del backoffice (G67).

/** Una persona nata nel finto, con la sessione aperta: il client di zr-auth parla con il suo gettone dell'accesso. */
function personaConSessione(BackofficeFinto $finto, string $email = 'anna@example.com', string $nome = 'Anna'): array
{
    $finto->persona($email, PASSWORD, nome: $nome);
    $accesso = entraNelFinto($email);
    Sessione::apri($accesso);

    return [Api::persona(), $accesso['gettone']['gettone']];
}

/** I primi byte di un JPEG: al finto basta il formato. */
function jpegFinto(): string
{
    return "\xFF\xD8\xFF\xE0".str_repeat("\0", 64);
}

it('carica la foto, io.mostra dice quando, e immagine() dà il file con l\'ETag e il 304 (#2870, D.1-D.3)', function () {
    $finto = BackofficeFinto::attiva();
    [$api, $gettone] = personaConSessione($finto);

    expect(alFinto('GET', '/v1/io', gettone: $gettone)->json('data.utente.foto_aggiornata_il'))->toBeNull()
        ->and(fn () => $api->immagine('/v1/io/foto/file'))->toThrow(fn (ErroreApi $e) => expect($e->stato)->toBe(404)->and($e->codice)->toBe('foto_assente'));

    $caricata = $api->caricaFile('/v1/io/foto', 'file', jpegFinto(), 'foto.jpg');

    expect(array_keys($caricata['data']))->toBe(['aggiornata_il'])
        ->and(alFinto('GET', '/v1/io', gettone: $gettone)->json('data.utente.foto_aggiornata_il'))->toBe($caricata['data']['aggiornata_il']);

    $file = $api->immagine('/v1/io/foto/file');

    expect($file['stato'])->toBe(200)->and($file['tipo'])->toBe('image/webp')->and(substr((string) $file['corpo'], 0, 4))->toBe('RIFF')
        ->and($file['etag'])->toBeString();

    $uguale = $api->immagine('/v1/io/foto/file', $file['etag']);

    expect($uguale)->toBe(['stato' => 304, 'etag' => $file['etag'], 'tipo' => null, 'corpo' => null]);

    // Una foto nuova ha un ETag nuovo e un istante dopo quello di prima.
    $nuova = $api->caricaFile('/v1/io/foto', 'file', jpegFinto().'altra', 'altra.jpg');

    expect($nuova['data']['aggiornata_il'] > $caricata['data']['aggiornata_il'])->toBeTrue()
        ->and($api->immagine('/v1/io/foto/file', $file['etag'])['stato'])->toBe(200);
});

it('rifiuta peso, formato e lato con 422 sul file, un campo in più, e il file mancante (#2870, D.1)', function () {
    $finto = BackofficeFinto::attiva();
    [$api, $gettone] = personaConSessione($finto);
    $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 4001, 10).str_repeat("\0", 16);

    foreach ([
        'troppo_lungo' => str_repeat('a', 2 * 1024 * 1024 + 1),
        'formato' => 'non sono un\'immagine',
        'fuori_intervallo' => $png,
    ] as $regola => $contenuto) {
        expect(fn () => $api->caricaFile('/v1/io/foto', 'file', $contenuto, 'foto.jpg'))
            ->toThrow(fn (ErroreApi $e) => expect($e->stato)->toBe(422)->and($e->codice)->toBe('dati_non_validi')
                ->and($e->errori[0]['pointer'])->toBe('#/file')->and($e->errori[0]['regola'])->toBe($regola));
    }

    $senza = alFinto('PATCH', '/v1/io/foto', [], gettone: $gettone);
    expect(esito($senza))->toBe([422, 'dati_non_validi'])->and($senza->json('errors.0.pointer'))->toBe('#/file')->and($senza->json('errors.0.regola'))->toBe('obbligatorio');

    $inPiu = formFoto($gettone)->post('/v1/io/foto', ['_method' => 'PATCH', 'nome' => 'Anna']);
    expect(esito($inPiu))->toBe([422, 'dati_non_validi'])->and($inPiu->json('errors'))->toHaveCount(1)->and($inPiu->json('errors.0.pointer'))->toBe('#/nome')->and($inPiu->json('errors.0.regola'))->toBe('campo_estraneo');

    expect(alFinto('GET', '/v1/io', gettone: $gettone)->json('data.utente.foto_aggiornata_il'))->toBeNull();
});

/** Un form multipart col file e il gettone, da completare con il verbo: la richiesta che un browser manda a un backoffice di PHP. */
function formFoto(string $gettone): Illuminate\Http\Client\PendingRequest
{
    return Illuminate\Support\Facades\Http::baseUrl(API)->acceptJson()->withToken($gettone)->attach('file', jpegFinto(), 'foto.jpg');
}

it('un PATCH multipart arriva senza il file, come in PHP (422 obbligatorio su #/file); il POST con _method=PATCH lo carica, e senza _method non è una rotta (#2870)', function () {
    $finto = BackofficeFinto::attiva();
    [, $gettone] = personaConSessione($finto);

    $patch = formFoto($gettone)->patch('/v1/io/foto');
    expect(esito($patch))->toBe([422, 'dati_non_validi'])->and($patch->json('errors.0.pointer'))->toBe('#/file')->and($patch->json('errors.0.regola'))->toBe('obbligatorio')
        ->and(alFinto('GET', '/v1/io', gettone: $gettone)->json('data.utente.foto_aggiornata_il'))->toBeNull();

    $grande = Illuminate\Support\Facades\Http::baseUrl(API)->acceptJson()->withToken($gettone)->attach('file', str_repeat('a', 2 * 1024 * 1024 + 70000), 'foto.jpg')->patch('/v1/io/foto');
    expect($grande->json('errors.0.regola'))->toBe('troppo_lungo');

    $post = formFoto($gettone)->post('/v1/io/foto', ['_method' => 'PATCH']);
    expect($post->status())->toBe(200)->and(array_keys($post->json('data')))->toBe(['aggiornata_il'])
        ->and(alFinto('GET', '/v1/io', gettone: $gettone)->json('data.utente.foto_aggiornata_il'))->toBe($post->json('data.aggiornata_il'));

    expect(fn () => formFoto($gettone)->post('/v1/io/foto'))->toThrow(Zeiras\Auth\Testing\Finto\RichiestaSconosciuta::class);
});

it('elimina toglie la foto: 204, poi 404 foto_assente, e foto_aggiornata_il torna null; la foto di una persona non è di un\'altra (#2870, D.4)', function () {
    $finto = BackofficeFinto::attiva();
    [$api, $gettone] = personaConSessione($finto);
    $api->caricaFile('/v1/io/foto', 'file', jpegFinto(), 'foto.jpg');
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $bruno = entraNelFinto('bruno@example.com')['gettone']['gettone'];

    expect(esito(alFinto('GET', '/v1/io/foto/file', gettone: $bruno)))->toBe([404, 'foto_assente'])
        ->and(esito(alFinto('DELETE', '/v1/io/foto', gettone: $bruno)))->toBe([404, 'foto_assente'])
        ->and(alFinto('DELETE', '/v1/io/foto', gettone: $gettone)->status())->toBe(204)
        ->and(alFinto('GET', '/v1/io', gettone: $gettone)->json('data.utente.foto_aggiornata_il'))->toBeNull()
        ->and(esito(alFinto('GET', '/v1/io/foto/file', gettone: $gettone)))->toBe([404, 'foto_assente'])
        ->and(esito(alFinto('DELETE', '/v1/io/foto', gettone: $gettone)))->toBe([404, 'foto_assente'])
        ->and(esito(alFinto('GET', '/v1/io/foto/file')))->toBe([401, 'gettone_assente'])
        ->and(esito(alFinto('PATCH', '/v1/io/foto', [])))->toBe([401, 'gettone_assente']);
});

it('immagine() rifiuta un tipo che non è un\'immagine, un file oltre il tetto e una versione che non è un entity-tag (#2870, D.6)', function () {
    apriSessione(false);

    Illuminate\Support\Facades\Http::fake([API.'/*' => Illuminate\Support\Facades\Http::response('<html>', 200, ['Content-Type' => 'text/html'])]);
    expect(fn () => Api::persona()->immagine('/v1/io/foto/file'))->toThrow(Zeiras\Auth\Errori\BackofficeNonRisponde::class);

    Illuminate\Support\Facades\Http::fake([API.'/*' => Illuminate\Support\Facades\Http::response(str_repeat('a', Api::IMMAGINE_MASSIMA + 1), 200, ['Content-Type' => 'image/webp'])]);
    expect(fn () => Api::persona()->immagine('/v1/io/foto/file'))->toThrow(Zeiras\Auth\Errori\BackofficeNonRisponde::class)
        ->and(fn () => Api::persona()->immagine('/v1/io/foto/file', 'senza-virgolette'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Api::persona()->immagine('/v1/io/foto/file?x=1'))->toThrow(InvalidArgumentException::class);
});
