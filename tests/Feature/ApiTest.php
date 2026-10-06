<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\BackofficeNonRisponde;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Errori\GettoneRifiutato;
use Zeiras\Auth\Errori\IndirizzoNonSicuro;

// T1.2 (Z3): chi chiama, dove, con quale gettone.

it('chiama ZR_API_URL col gettone del workspace, Accept, Accept-Language e i timeout corti', function () {
    $opzioni = [];
    Http::fake(function (Request $richiesta, array $o) use (&$opzioni) {
        $opzioni = $o;

        return Http::response(['data' => ['ok' => true]]);
    });
    app()->setLocale('en');
    apriSessione();

    expect(Api::workspace()->get('/v1/io', ['limite' => 2]))->toBe(['data' => ['ok' => true]]);

    Http::assertSent(fn (Request $r) => $r->url() === API.'/v1/io?limite=2'
        && $r->header('Authorization') === ['Bearer '.GETTONE_WORKSPACE]
        && $r->header('Accept-Language') === ['en']
        && $r->header('Accept') === ['application/json']);
    expect($opzioni['timeout'])->toBe(5)->and($opzioni['connect_timeout'])->toBe(2);
});

it('persona() manda il gettone dell\'accesso, senzaGettone() nessun gettone', function () {
    Http::fake(['*' => Http::response(['data' => []], 201)]);
    apriSessione();

    Api::persona()->post('/v1/gettoni', ['workspace_id' => 'w']);
    Api::senzaGettone()->post('/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD]);

    $inviate = Http::recorded()->map(fn (array $coppia) => $coppia[0]);
    expect($inviate[0]->header('Authorization'))->toBe(['Bearer '.GETTONE_ACCESSO])
        ->and($inviate[0]->data())->toBe(['workspace_id' => 'w'])
        ->and($inviate[1]->hasHeader('Authorization'))->toBeFalse();
});

it('non parte verso http://, né verso un indirizzo che non è di /v1', function (string $api, string $percorso, string $errore) {
    Http::fake();
    config(['zr-auth.api' => $api]);
    apriSessione();

    expect(fn () => Api::workspace()->post($percorso, ['a' => 1]))->toThrow($errore);
    Http::assertNothingSent();
})->with([
    'http' => ['http://api.zeiras.com', '/v1/io', IndirizzoNonSicuro::class],
    'indirizzo intero' => [API, 'https://altrove.example/v1/io', InvalidArgumentException::class],
    'host relativo' => [API, '//altrove.example/v1/io', InvalidArgumentException::class],
    'query nel percorso' => [API, '/v1/io?limite=2', InvalidArgumentException::class],
]);

it('senza una sessione aperta, persona() e workspace() danno GettoneRifiutato', function () {
    expect(fn () => Api::persona())->toThrow(GettoneRifiutato::class)
        ->and(fn () => Api::workspace())->toThrow(GettoneRifiutato::class);
});

// T1.3 (Z3, nota 5514): gli errori di /v1, e un backoffice che non risponde.

it('un problem+json diventa ErroreApi, col codice, i testi, gli errori dei campi e Retry-After', function () {
    Http::fake(['*' => problema(422, 'dati_non_validi', ['errors' => [['detail' => 'Obbligatorio.', 'pointer' => '#/nome']]])]);

    try {
        Api::senzaGettone()->post('/v1/utenti', []);
        $this->fail('nessuna eccezione');
    } catch (ErroreApi $e) {
        expect($e->stato)->toBe(422)->and($e->codice)->toBe('dati_non_validi')
            ->and($e->titolo)->toBe('Titolo')->and($e->dettaglio)->toBe('Dettaglio per la persona.')
            ->and($e->errori)->toBe([['detail' => 'Obbligatorio.', 'pointer' => '#/nome']])
            ->and($e->riprovaFra)->toBeNull();
    }
});

it('un 429 porta i secondi di Retry-After in riprovaFra', function () {
    Http::fake(['*' => problema(429, 'troppe_richieste', header: ['Retry-After' => '30'])]);

    expect(fn () => Api::senzaGettone()->post('/v1/accessi', []))
        ->toThrow(fn (ErroreApi $e) => expect($e->stato)->toBe(429)->and($e->riprovaFra)->toBe(30));
});

it('legge come JSON ogni application/*+json', function () {
    Http::fake(['*' => Http::response('{"codice":"non_trovato","detail":"Non c\'è."}', 404, ['Content-Type' => 'application/vnd.zeiras.prova+json; charset=utf-8'])]);

    expect(fn () => Api::senzaGettone()->get('/v1/x'))
        ->toThrow(fn (ErroreApi $e) => expect($e->codice)->toBe('non_trovato')->and($e->dettaglio)->toBe('Non c\'è.'));
});

it('un timeout, un 5xx o una risposta senza JSON danno BackofficeNonRisponde', function (Closure $risposta) {
    Http::fake(['*' => $risposta]);

    expect(fn () => Api::senzaGettone()->get('/v1/x'))->toThrow(BackofficeNonRisponde::class);
})->with([
    'timeout' => fn () => fn () => throw new ConnectionException('cURL error 28: timeout'),
    '503' => fn () => fn () => Http::response('<html>503</html>', 503, ['Content-Type' => 'text/html']),
    '500 problem+json' => fn () => fn () => problema(500, 'errore_interno'),
    '200 html' => fn () => fn () => Http::response('<html>ok</html>', 200, ['Content-Type' => 'text/html']),
    '413 di nginx' => fn () => fn () => Http::response('<html>413</html>', 413, ['Content-Type' => 'text/html']),
]);

// T1.8 (B2.4): tutte le pagine di una lista.

it('tutti() scorre successivo fino a null', function () {
    Http::fake(function (Request $r) {
        return isset($r->data()['cursore'])
            ? Http::response(['data' => [['id' => 'c']], 'successivo' => null])
            : Http::response(['data' => [['id' => 'a'], ['id' => 'b']], 'successivo' => 'pagina-2']);
    });
    apriSessione();

    expect(Api::workspace()->tutti('/v1/workspace/membri', ['limite' => 2]))->toBe([['id' => 'a'], ['id' => 'b'], ['id' => 'c']]);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => $r->url() === API.'/v1/workspace/membri?limite=2&cursore=pagina-2');
});

it('tutti() lancia se una pagina fallisce, mai una lista a metà', function () {
    Http::fake(function (Request $r) {
        return isset($r->data()['cursore'])
            ? Http::response('<html>502</html>', 502, ['Content-Type' => 'text/html'])
            : Http::response(['data' => [['id' => 'a']], 'successivo' => 'pagina-2']);
    });
    apriSessione();

    expect(fn () => Api::workspace()->tutti('/v1/workspace/membri'))->toThrow(BackofficeNonRisponde::class);
});

it('tutti() si ferma oltre 100 pagine', function () {
    Http::fake(['*' => Http::response(['data' => [], 'successivo' => 'ancora'])]);
    apriSessione();

    expect(fn () => Api::workspace()->tutti('/v1/workspace/membri'))->toThrow(BackofficeNonRisponde::class);
    Http::assertSentCount(100);
});
