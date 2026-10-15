<?php

use Illuminate\Support\Facades\Route;
use Zeiras\Auth\Sessione;

// #2043: una scrittura a sessione scaduta ricorda la pagina da cui è partita, dal Referer, a condizioni strette. Dopo l'accesso si
// torna con un GET, e la scrittura non si ripete.

const PAGINA_BUONA = FRONTEND.'/w/studio/b/3?vista=2';

beforeEach(function () {
    $this->scritture = 0;
    Route::middleware('web')->match(['POST', 'PUT', 'PATCH', 'DELETE'], 'w/{workspace}/nota', function () {
        app()->instance('ritorno.scritture', (app()->bound('ritorno.scritture') ? app('ritorno.scritture') : 0) + 1);

        return 'ok';
    });
});

function scriviDa(?string $referer, string $metodo = 'POST', array $intestazioni = []): Illuminate\Testing\TestResponse
{
    $server = $referer === null ? [] : ['HTTP_REFERER' => $referer];
    foreach ($intestazioni as $nome => $valore) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $nome))] = $valore;
    }

    return test()->call($metodo, FRONTEND.'/w/studio/nota', ['testo' => 'da non perdere'], [], [], $server);
}

// ZA.1
it('una scrittura a sessione scaduta ricorda percorso e query della pagina di partenza, senza il frammento', function (string $metodo) {
    scriviDa(PAGINA_BUONA.'#in-fondo', $metodo)->assertRedirect(INGRESSO);

    expect(session('url.intended'))->toBe(PAGINA_BUONA);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('un Referer che non è una pagina di questo workspace, su questa origine, non diventa il ritorno', function (string $referer) {
    test()->withSession(['url.intended' => FRONTEND.'/w/studio/ricordata-prima']);

    scriviDa($referer)->assertRedirect(INGRESSO);

    expect(session()->has('url.intended'))->toBeFalse();
})->with([
    'altra origine' => 'https://evil.example/w/studio/b/3',
    'un sottodominio finto' => 'https://board.zeiras.com.evil.example/w/studio/b/3',
    'credenziali con l\'host nostro' => 'https://user:pw@board.zeiras.com/w/studio/b/3',
    'utente che mima l\'host' => 'https://board.zeiras.com@evil.example/w/studio/b/3',
    'senza schema' => '//evil.example/w/studio/b/3',
    'solo il percorso' => '/w/studio/b/3',
    'javascript' => 'javascript:alert(1)',
    'un altro schema' => 'http://board.zeiras.com/w/studio/b/3',
    'un\'altra porta' => 'https://board.zeiras.com:8443/w/studio/b/3',
    'un altro workspace' => 'https://board.zeiras.com/w/altro/b/3',
    'il prefisso del workspace' => 'https://board.zeiras.com/w/studio-altro/b/3',
    'senza /w/' => 'https://board.zeiras.com/b/3',
    'la radice del workspace senza barra' => 'https://board.zeiras.com/w/studio',
    '// nel percorso' => 'https://board.zeiras.com//evil.example/w/studio/b/3',
    '// dopo lo slug' => 'https://board.zeiras.com/w/studio//evil.example',
    '// codificato' => 'https://board.zeiras.com/w/studio/%2F%2Fevil.example',
    'che risale' => 'https://board.zeiras.com/w/studio/../altro/b/3',
    'che risale, codificato' => 'https://board.zeiras.com/w/studio/%2e%2e/altro/b/3',
    'con una barra rovesciata' => 'https://board.zeiras.com/w/studio/\\evil',
    'con una barra rovesciata iniziale' => '/\\evil.example',
    'con un a capo' => "https://board.zeiras.com/w/studio/b/3\nSet-Cookie: a=b",
    'con un carattere di controllo codificato' => 'https://board.zeiras.com/w/studio/b%00/3',
    'più lungo del tetto' => 'https://board.zeiras.com/w/studio/'.str_repeat('a', 2000),
    'vuoto' => '',
]);

it('senza Referer non ricorda niente, e un ritorno di prima non vale per questa scrittura', function () {
    test()->withSession(['url.intended' => FRONTEND.'/w/studio/ricordata-prima']);

    scriviDa(null)->assertRedirect(INGRESSO);

    expect(session()->has('url.intended'))->toBeFalse();
});

it('l\'origine nel Referer si confronta senza badare alle maiuscole, e il ritorno è sull\'origine della richiesta', function () {
    scriviDa('HTTPS://BOARD.ZEIRAS.COM/w/studio/b/3?vista=2')->assertRedirect(INGRESSO);

    expect(session('url.intended'))->toBe(PAGINA_BUONA);
});

it('il testo cercato (q, scelta) non entra nel ritorno, il resto della query sì', function () {
    scriviDa(FRONTEND.'/w/studio/b?q=Mario+Rossi&scelta=mario%40example.com&vista=2&pagina=3')->assertRedirect(INGRESSO);

    expect(session('url.intended'))->toBe(FRONTEND.'/w/studio/b?vista=2&pagina=3');
});

it('una rotta senza workspace nel percorso non ricorda il Referer', function () {
    Route::middleware('web')->post('modulo', fn () => 'ok');

    test()->call('POST', FRONTEND.'/modulo', [], [], [], ['HTTP_REFERER' => PAGINA_BUONA])->assertRedirect(INGRESSO);

    expect(session()->has('url.intended'))->toBeFalse();
});

it('HEAD non ricorda niente, un GET ricorda il suo indirizzo e non il Referer', function () {
    Route::middleware('web')->any('w/{workspace}/pagina', fn () => 'ok');

    test()->call('HEAD', FRONTEND.'/w/studio/pagina', [], [], [], ['HTTP_REFERER' => PAGINA_BUONA]);
    expect(session()->has('url.intended'))->toBeFalse();

    test()->call('GET', FRONTEND.'/w/studio/pagina?x=1', [], [], [], ['HTTP_REFERER' => 'https://altro.zeiras.com/x']);
    expect(session('url.intended'))->toBe(FRONTEND.'/w/studio/pagina?x=1');
});

it('una visita di Inertia riceve 409 con X-Inertia-Location e ricorda la pagina; una richiesta JSON 401 e niente', function () {
    scriviDa(PAGINA_BUONA, 'POST', ['X-Inertia' => 'true'])->assertStatus(409)->assertHeader('X-Inertia-Location', INGRESSO);
    expect(session('url.intended'))->toBe(PAGINA_BUONA);

    session()->forget('url.intended');
    test()->postJson(FRONTEND.'/w/studio/nota', [], ['Referer' => PAGINA_BUONA])->assertStatus(401);
    expect(session()->has('url.intended'))->toBeFalse();
});

// ZA.2
it('dopo l\'accesso si torna alla pagina con un GET, e la scrittura non si ripete', function () {
    scriviDa(PAGINA_BUONA)->assertRedirect(INGRESSO);
    apriSessione();

    expect(Sessione::ritorno(FRONTEND.'/predefinita'))->toBe(PAGINA_BUONA)
        ->and(app()->bound('ritorno.scritture') ? app('ritorno.scritture') : 0)->toBe(0)
        // Vale una volta.
        ->and(Sessione::ritorno(FRONTEND.'/predefinita'))->toBe(FRONTEND.'/predefinita');
});

// ZA.3
it('il README descrive il ritorno dopo una scrittura e i suoi limiti', function () {
    $readme = file_get_contents(__DIR__.'/../../README.md');
    preg_match('/^### Il ritorno dopo una scrittura[^\n]*\n(.*?)(?=^##+ |\z)/ms', $readme, $trovata);
    $sezione = $trovata[1] ?? '';

    expect($sezione)->not->toBe('')
        ->toContain('Referer')
        ->toContain('/w/<slug>/')
        ->toContain('Ritorno::dalReferer')
        ->toContain('senza_query')
        ->toContain('GET')
        ->toContain('non si ripete')
        ->toContain('zr-crm');
});
