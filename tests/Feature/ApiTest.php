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
use Zeiras\Auth\Freno;

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

it('condizionale() ha gli errori di get(): 404, 429, 401, 5xx e trasporto', function (string $caso, string $eccezione) {
    Http::fake(['*' => match ($caso) {
        '404' => problema(404, 'non_trovato'),
        '429' => problema(429, 'troppe_richieste', header: ['Retry-After' => '30']),
        '401' => problema(401, 'non_autenticato'),
        '503 html' => Http::response('<html>503</html>', 503, ['Content-Type' => 'text/html']),
        'trasporto' => fn () => throw new ConnectionException('cURL error 28: timeout'),
    }]);
    apriSessione();

    try {
        Api::workspace()->condizionale('/v1/x', '"a"'); // T5.2
        $this->fail('nessuna eccezione');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($eccezione);

        if ($e instanceof ErroreApi) {
            expect($e->stato)->toBe((int) $caso)->and($e->riprovaFra)->toBe($caso === '429' ? 30 : null);
        }
    }
})->with([
    '404' => ['404', ErroreApi::class],
    '429' => ['429', ErroreApi::class],
    '401' => ['401', GettoneRifiutato::class],
    '503 html' => ['503 html', BackofficeNonRisponde::class],
    'trasporto' => ['trasporto', BackofficeNonRisponde::class],
]);

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

    expect(fn () => Api::workspace()->condizionale('/v1/x', $versione))->toThrow(InvalidArgumentException::class, 'entity-tag'); // T5.2: il messaggio è il nostro, non quello di Guzzle
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

it('i membri estesi di un problema (RFC 9457) arrivano in estensioni: limite e direzione del 409 limite_raggiunto (#1457)', function () {
    Http::fake(['*' => problema(409, 'limite_raggiunto', ['limite' => 10, 'direzione' => 'in_uscita'])]);

    expect(fn () => Api::senzaGettone()->post('/v1/board/schede/x/collegamenti', []))
        ->toThrow(fn (ErroreApi $e) => expect($e->codice)->toBe('limite_raggiunto')
            ->and($e->estensioni)->toBe(['limite' => 10, 'direzione' => 'in_uscita']));
});

it('senza membri estesi, estensioni è vuoto: i testi e gli errori dei campi non ci sono (#1457)', function () {
    Http::fake(['*' => problema(422, 'dati_non_validi', ['errors' => [['detail' => 'Obbligatorio.', 'pointer' => '#/nome']]])]);

    expect(fn () => Api::senzaGettone()->post('/v1/utenti', []))
        ->toThrow(fn (ErroreApi $e) => expect($e->estensioni)->toBe([]));
});

it('delete() manda la query nei parametri della richiesta, senza corpo, e rifiuta un percorso con la query dentro (#1902, T1.4)', function () {
    Http::fake(['*' => Http::response(null, 204)]);
    apriSessione();

    expect(Api::workspace()->delete('/v1/crm/fasi/f1', ['sposta_in' => 'f2']))->toBe([])
        ->and(Api::workspace()->delete('/v1/crm/fasi/f3'))->toBe([]);

    $inviate = Http::recorded()->map(fn (array $coppia) => $coppia[0])->values();

    expect($inviate[0]->method())->toBe('DELETE')
        ->and($inviate[0]->url())->toBe(API.'/v1/crm/fasi/f1?sposta_in=f2')
        ->and($inviate[0]->body())->toBe('')
        ->and($inviate[1]->url())->toBe(API.'/v1/crm/fasi/f3');

    expect(fn () => Api::workspace()->delete('/v1/crm/fasi/f1?sposta_in=f2'))->toThrow(InvalidArgumentException::class, 'senza query');
});

// #1911 (T4): il residuo dei freni dopo una chiamata, da RateLimit-Policy e RateLimit (draft IETF httpapi-ratelimit-headers).

it('freni() dà, per nome, quota e finestra della politica e residuo e ripristino dell\'ultima risposta (#1911, R.1)', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['data' => []], 201, [
            'RateLimit-Policy' => '"chiamate_persona";q=300;w=3600, "prova_persona";q=30000;w=3600',
            'RateLimit' => '"chiamate_persona";r=299;t=3600, "prova_persona";r=29996;t=3599',
        ])
        ->push(['data' => []], 201, ['RateLimit-Policy' => '"chiamate_persona";q=300;w=3600', 'RateLimit' => '"chiamate_persona";r=298;t=3540'])]);
    apriSessione();
    $api = Api::workspace();

    expect($api->freni())->toBeNull();

    $api->post('/v1/crm/persone/importazioni', ['righe' => [[]]]);
    $freni = $api->freni();

    expect(array_keys($freni->tutti()))->toBe(['chiamate_persona', 'prova_persona'])
        ->and($freni->freno('prova_persona'))->toEqual(new Freno('prova_persona', q: 30000, w: 3600, r: 29996, t: 3599))
        ->and($freni->freno('chiamate_persona')->r)->toBe(299)
        ->and($freni->freno('inventato'))->toBeNull();

    // La seconda chiamata sostituisce la prima: niente resta della risposta di prima.
    $api->post('/v1/crm/persone/importazioni', ['righe' => [[]]]);

    expect(array_keys($api->freni()->tutti()))->toBe(['chiamate_persona'])
        ->and($api->freni()->freno('chiamate_persona'))->toEqual(new Freno('chiamate_persona', q: 300, w: 3600, r: 298, t: 3540));
});

it('freni() si legge anche dopo un errore: il 429 del freno e un 422 portano il residuo (#1911, R.1)', function (int $stato, string $codice) {
    Http::fake(['*' => problema($stato, $codice, header: ['RateLimit-Policy' => '"scrittura_persona";q=12000;w=3600', 'RateLimit' => '"scrittura_persona";r=0;t=1200'])]);
    apriSessione();
    $api = Api::workspace();

    expect(fn () => $api->post('/v1/crm/persone/importazioni', ['righe' => [[]]]))->toThrow(ErroreApi::class)
        ->and($api->freni()->freno('scrittura_persona'))->toEqual(new Freno('scrittura_persona', q: 12000, w: 3600, r: 0, t: 1200));
})->with([[429, 'troppe_richieste'], [422, 'dati_non_validi']]);

it('una risposta senza le intestazioni dà nessun freno noto (null), non un residuo zero, anche dopo una che le aveva (#1911, R.2)', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['data' => []], 200, ['RateLimit-Policy' => '"chiamate_persona";q=300;w=3600', 'RateLimit' => '"chiamate_persona";r=299;t=3600'])
        ->push(['data' => []], 200)
        ->push(['data' => []], 200, ['RateLimit-Policy' => '', 'RateLimit' => ''])
        ->pushStatus(204)]);
    apriSessione();
    $api = Api::workspace();

    $api->get('/v1/crm/persone');
    expect($api->freni())->not->toBeNull();

    $api->get('/v1/crm/persone');
    expect($api->freni())->toBeNull();

    $api->get('/v1/crm/persone');
    expect($api->freni())->toBeNull();

    $api->delete('/v1/crm/fasi/f1');
    expect($api->freni())->toBeNull();
});

it('un backoffice che non risponde lascia nessun freno noto, non quello della chiamata prima (#1911, R.1, R.2)', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['data' => []], 200, ['RateLimit-Policy' => '"chiamate_persona";q=300;w=3600', 'RateLimit' => '"chiamate_persona";r=299;t=3600'])
        ->pushFailedConnection()]);
    apriSessione();
    $api = Api::workspace();

    $api->get('/v1/crm/persone');
    expect(fn () => $api->get('/v1/crm/persone'))->toThrow(BackofficeNonRisponde::class)
        ->and($api->freni())->toBeNull();
});

it('il parser regge le maiuscole del nome delle intestazioni, i token e gli spazi, e ignora senza lanciare ciò che non legge (#1911, R.3)', function (array $intestazioni, ?array $atteso) {
    Http::fake(['*' => Http::response(['data' => []], 200, $intestazioni)]);
    apriSessione();
    $api = Api::workspace();

    $api->get('/v1/crm/persone');
    $freni = $api->freni();

    expect($freni === null ? null : array_map(fn (Freno $f) => [$f->q, $f->w, $f->r, $f->t], $freni->tutti()))->toBe($atteso);
})->with([
    'minuscole' => [['ratelimit-policy' => '"a";q=10;w=60', 'ratelimit' => '"a";r=4;t=30'], ['a' => [10, 60, 4, 30]]],
    'maiuscole' => [['RATELIMIT-POLICY' => '"a";q=10;w=60', 'RATELIMIT' => '"a";r=4;t=30'], ['a' => [10, 60, 4, 30]]],
    'token e spazi' => [['RateLimit-Policy' => ' a ; q=10 ;w=60 ,  b;q=5', 'RateLimit' => 'b;r=1, a;t=30;r=4'], ['a' => [10, 60, 4, 30], 'b' => [5, null, 1, null]]],
    'parametri sconosciuti' => [['RateLimit-Policy' => '"a";q=10;w=60;qu="requests";pk=:YWJj:', 'RateLimit' => '"a";r=4;t=30;pk=:YWJj:'], ['a' => [10, 60, 4, 30]]],
    'politica senza residuo' => [['RateLimit-Policy' => '"a";q=10;w=60', 'RateLimit' => '"b";r=1;t=2'], ['a' => [10, 60, null, null]]],
    'residuo senza politica' => [['RateLimit' => '"a";r=4;t=30'], null],
    'politica senza q' => [['RateLimit-Policy' => '"a";w=60, "b";q=3', 'RateLimit' => '"a";r=4, "b";r=2'], ['b' => [3, null, 2, null]]],
    'q non intero' => [['RateLimit-Policy' => '"a";q=1.5;w=60, "b";q=3', 'RateLimit' => '"a";r=1;t=1, "b";r=2'], ['b' => [3, null, 2, null]]],
    'negativo' => [['RateLimit-Policy' => '"a";q=-1, "b";q=3', 'RateLimit' => '"b";r=-2'], ['b' => [3, null, null, null]]],
    'stringa aperta' => [['RateLimit-Policy' => '"a;q=10, "b";q=3', 'RateLimit' => '"b";r=2;t=1'], null],
    'virgola nel nome' => [['RateLimit-Policy' => '"a,b";q=10', 'RateLimit' => '"a,b";r=4;t=30'], ['a,b' => [10, null, 4, 30]]],
    'rumore' => [['RateLimit-Policy' => ';;;,,=,"";q=1, 9x;q=2, "c";q=3;q=4', 'RateLimit' => '"c";r=1;r=2'], ['c' => [4, null, 2, null]]],
    'doppione' => [['RateLimit-Policy' => '"a";q=10, "a";q=20', 'RateLimit' => '"a";r=4, "a";r=9'], ['a' => [10, null, 4, null]]],
    'enorme' => [['RateLimit-Policy' => '"a";q=99999999999999999999, "b";q=3', 'RateLimit' => '"b";r=1'], ['b' => [3, null, 1, null]]],
]);
