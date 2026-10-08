<?php

use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
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

it('un timeout, un 5xx senza problema JSON o una risposta senza JSON danno BackofficeNonRisponde', function (Closure $risposta) {
    Http::fake(['*' => $risposta]);

    expect(fn () => Api::senzaGettone()->get('/v1/x'))->toThrow(BackofficeNonRisponde::class);
})->with([
    'timeout' => fn () => fn () => throw new ConnectionException('cURL error 28: timeout'),
    '503 html' => fn () => fn () => Http::response('<html>503</html>', 503, ['Content-Type' => 'text/html']),
    '200 html' => fn () => fn () => Http::response('<html>ok</html>', 200, ['Content-Type' => 'text/html']),
    '413 di nginx' => fn () => fn () => Http::response('<html>413</html>', 413, ['Content-Type' => 'text/html']),
]);

// T6.3 (ZB3, R30): un trasporto che cade dopo lo stato è un backoffice che non risponde; mai una RequestException che esce.

it('un trasporto che cade dopo lo stato dà BackofficeNonRisponde, col guasto in previous', function (Closure $guasto, string $previous) {
    Http::fake(['*' => $guasto]);

    expect(fn () => Api::senzaGettone()->get('/v1/x'))
        ->toThrow(fn (BackofficeNonRisponde $e) => expect($e->getPrevious())->toBeInstanceOf($previous));
})->with([
    'RequestException' => [fn () => fn () => throw new RequestException(new Response(Http::psr7Response('', 502))), RequestException::class],
    'trasferimento rotto dopo un 422' => [fn () => fn (Request $r) => Create::rejectionFor(new ResponseTransferException(
        'Connessione chiusa a metà del corpo.', $r->toPsrRequest(), Http::psr7Response('{"codice":', 422, ['Content-Type' => 'application/problem+json']),
    )), RequestException::class],
    'timeout dopo un 200' => [fn () => fn (Request $r) => Create::rejectionFor(new ResponseTimeoutException(
        'cURL error 28: timeout', $r->toPsrRequest(), Http::psr7Response('', 200),
    )), ConnectionException::class],
]);

it('un 4xx del backoffice resta ErroreApi', function () {
    Http::fake(['*' => problema(404, 'non_trovato')]);

    expect(fn () => Api::senzaGettone()->get('/v1/x'))
        ->toThrow(fn (ErroreApi $e) => expect($e->stato)->toBe(404)->and($e->codice)->toBe('non_trovato'));
});

// Zr-home (voce #1209, T4.3): un 503 turnstile_non_disponibile restava BackofficeNonRisponde, e il detail del
// backoffice si perdeva dietro «il servizio non risponde». Un 5xx con un problema JSON leggibile resta ErroreApi,
// come un 4xx: si decide su codice, si mostra detail.
it('un 5xx del backoffice con un problema JSON resta ErroreApi', function (int $stato, string $codice) {
    Http::fake(['*' => problema($stato, $codice)]);

    expect(fn () => Api::senzaGettone()->get('/v1/x'))
        ->toThrow(fn (ErroreApi $e) => expect($e->stato)->toBe($stato)->and($e->codice)->toBe($codice));
})->with([
    '500 errore_interno' => [500, 'errore_interno'],
    '503 turnstile_non_disponibile' => [503, 'turnstile_non_disponibile'],
]);

// R31: il client non segue i redirect, e un 3xx non è una risposta da usare.

it('un 3xx dà BackofficeNonRisponde, e il corpo non va al Location', function () {
    Http::fake(['*' => Http::response('{"data":{}}', 307, ['Location' => 'https://altrove.example/v1/accessi', 'Content-Type' => 'application/json'])]);

    expect(fn () => Api::senzaGettone()->post('/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD]))
        ->toThrow(BackofficeNonRisponde::class);
    Http::assertSentCount(1);
});

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

// #1364 (T5): la GET condizionale. Il 304 non è un errore; l'ETag di un 200 si legge e si rimanda com'è.

it('condizionale() manda If-None-Match e torna 200 con l\'ETag e il corpo', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 'b']], 200, ['ETag' => '"abc"'])]);
    apriSessione();

    expect(Api::workspace()->condizionale('/v1/board/board/b', '"vecchio"', ['x' => 1]))
        ->toBe(['stato' => 200, 'etag' => '"abc"', 'corpo' => ['data' => ['id' => 'b']]]); // T5.1

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === API.'/v1/board/board/b?x=1'
        && $r->header('If-None-Match') === ['"vecchio"']
        && $r->header('Authorization') === ['Bearer '.GETTONE_WORKSPACE]);
});

it('condizionale() senza versione non manda If-None-Match, e un 200 senza ETag torna etag null', function () {
    Http::fake(['*' => Http::response(['data' => []])]);
    apriSessione();

    expect(Api::persona()->condizionale('/v1/io'))->toBe(['stato' => 200, 'etag' => null, 'corpo' => ['data' => []]]);
    Http::assertSent(fn (Request $r) => ! $r->hasHeader('If-None-Match'));
});

it('un 304 torna stato 304, senza corpo, e non lancia', function () {
    Http::fake(['*' => Http::response('', 304, ['ETag' => '"abc"'])]);
    apriSessione();

    expect(Api::workspace()->condizionale('/v1/board/board/b', '"abc"'))
        ->toBe(['stato' => 304, 'etag' => '"abc"', 'corpo' => null]); // T5.1: deve fallire se un 304 lancia
});

it('un 304 a una GET senza condizione è un backoffice che non risponde bene', function () {
    Http::fake(['*' => Http::response('', 304)]);
    apriSessione();

    expect(fn () => Api::workspace()->condizionale('/v1/board/board/b'))->toThrow(BackofficeNonRisponde::class);
});

it('get() continua a rifiutare un 304', function () {
    Http::fake(['*' => Http::response('', 304)]);
    apriSessione();

    expect(fn () => Api::workspace()->get('/v1/board/board/b'))->toThrow(BackofficeNonRisponde::class);
});

it('un ETag che non è un entity-tag torna null, e non arriva a chi chiama', function (string $etag) {
    Http::fake(['*' => Http::response(['data' => []], 200, ['ETag' => $etag])]);
    apriSessione();

    expect(Api::workspace()->condizionale('/v1/io')['etag'])->toBeNull();
})->with(['senza virgolette' => ['abc'], 'virgolette di mezzo' => ['"a"b"'], 'due tag' => ['"a", "b"']]);

it('condizionale() accetta un ETag debole', function () {
    Http::fake(['*' => Http::response(['data' => []], 200, ['ETag' => 'W/"abc"'])]);
    apriSessione();

    expect(Api::workspace()->condizionale('/v1/io', 'W/"abc"')['etag'])->toBe('W/"abc"');
});

it('condizionale() ha gli errori di get(): 404, 429, 401, 5xx e trasporto', function () {
    apriSessione();

    Http::fake(['*' => problema(404, 'non_trovato')]);
    expect(fn () => Api::workspace()->condizionale('/v1/x', '"a"'))
        ->toThrow(fn (ErroreApi $e) => expect($e->stato)->toBe(404)->and($e->codice)->toBe('non_trovato')); // T5.2

    Http::fake(['*' => problema(429, 'troppe_richieste', header: ['Retry-After' => '30'])]);
    expect(fn () => Api::workspace()->condizionale('/v1/x', '"a"'))
        ->toThrow(fn (ErroreApi $e) => expect($e->stato)->toBe(429)->and($e->riprovaFra)->toBe(30));

    Http::fake(['*' => problema(401, 'non_autenticato')]);
    expect(fn () => Api::workspace()->condizionale('/v1/x', '"a"'))->toThrow(GettoneRifiutato::class);

    Http::fake(['*' => Http::response('<html>503</html>', 503, ['Content-Type' => 'text/html'])]);
    expect(fn () => Api::workspace()->condizionale('/v1/x', '"a"'))->toThrow(BackofficeNonRisponde::class);

    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: timeout')]);
    expect(fn () => Api::workspace()->condizionale('/v1/x', '"a"'))->toThrow(BackofficeNonRisponde::class);
});

it('condizionale() rifiuta un 3xx diverso dal 304, un 204 e un 200 senza JSON', function (Closure $risposta) {
    Http::fake(['*' => $risposta]);
    apriSessione();

    expect(fn () => Api::workspace()->condizionale('/v1/x', '"a"'))->toThrow(BackofficeNonRisponde::class);
})->with([
    '301' => fn () => fn () => Http::response('', 301, ['Location' => 'https://altrove.example/v1/x']),
    '204' => fn () => fn () => Http::response('', 204),
    '200 html' => fn () => fn () => Http::response('<html>ok</html>', 200, ['Content-Type' => 'text/html']),
]);

it('una versione che non è un entity-tag non parte: InvalidArgumentException prima della chiamata', function (string $versione) {
    Http::fake();
    apriSessione();

    expect(fn () => Api::workspace()->condizionale('/v1/x', $versione))->toThrow(InvalidArgumentException::class); // T5.2
    Http::assertNothingSent();
})->with([
    'CR LF' => ["\"a\"\r\nX-Altro: 1"],
    'LF' => ["\"a\"\n"],
    'senza virgolette' => ['abc'],
    'virgolette di mezzo' => ['"a"b"'],
    'spazio dentro' => ['"a b"'],
    'due tag' => ['"a", "b"'],
    'asterisco' => ['*'],
    'vuota' => [''],
]);

it('condizionale() rifiuta un percorso fuori da /v1, come get()', function () {
    Http::fake();
    apriSessione();

    expect(fn () => Api::workspace()->condizionale('https://altrove.example/v1/x', '"a"'))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('né il gettone né l\'ETag finiscono nel messaggio di un\'eccezione', function () {
    apriSessione();
    $messaggi = [];

    foreach ([
        fn () => Api::workspace()->condizionale('/v1/x', "\"segreto-etag\"\r\n"),
        fn () => Api::workspace()->condizionale('/v1/x', 'segreto-etag'),
    ] as $chiamata) {
        try {
            $chiamata();
        } catch (InvalidArgumentException $e) {
            $messaggi[] = $e->getMessage();
        }
    }

    Http::fake(['*' => Http::response('<html>503</html>', 503, ['ETag' => '"segreto-etag"', 'Content-Type' => 'text/html'])]);
    try {
        Api::workspace()->condizionale('/v1/x', '"segreto-etag"');
    } catch (BackofficeNonRisponde $e) {
        $messaggi[] = $e->getMessage();
    }

    expect($messaggi)->toHaveCount(3);
    foreach ($messaggi as $messaggio) {
        expect($messaggio)->not->toContain('segreto-etag')->not->toContain(GETTONE_WORKSPACE);
    }
}); // T5.3
