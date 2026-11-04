<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Zeiras\Auth\Sessione;

// T1.4 (Z3): un 401 del backoffice chiude la sessione e rimanda all'ingresso, con una chiamata sola.

it('un 401 del backoffice chiude la sessione e rimanda all\'ingresso, senza ripetere la chiamata', function () {
    Http::fake(['*' => problema(401, 'gettone_non_valido')]);
    apriSessione();

    $this->get('/io')->assertRedirect(INGRESSO);

    Http::assertSentCount(1);
    expect(session()->has(Sessione::CHIAVE))->toBeFalse()
        ->and(session('url.intended'))->toBe(url('/io'));
});

it('a una visita di Inertia il 401 risponde 409 con X-Inertia-Location', function () {
    Http::fake(['*' => problema(401, 'gettone_non_valido')]);
    apriSessione();

    $this->get('/io', ['X-Inertia' => 'true'])->assertStatus(409)->assertHeader('X-Inertia-Location', INGRESSO);

    Http::assertSentCount(1);
    expect(session()->has(Sessione::CHIAVE))->toBeFalse();
});

// T1.5 (Z4): la guardia in ogni rotta del gruppo `web`.

it('senza sessione una pagina rimanda all\'ingresso e ricorda dove voleva andare', function () {
    $this->get('/pagina')->assertRedirect(INGRESSO);

    expect(session('url.intended'))->toBe(url('/pagina'));
});

it('senza sessione una richiesta JSON riceve 401, e una visita di Inertia 409', function () {
    $this->getJson('/pagina')->assertStatus(401);
    $this->get('/pagina', ['X-Inertia' => 'true'])->assertStatus(409)->assertHeader('X-Inertia-Location', INGRESSO);
});

it('col gettone scaduto rimanda all\'ingresso, e il gettone esce dalla sessione', function () {
    apriSessione(scadeIl: now()->subSecond()->toJSON());

    $this->get('/pagina')->assertRedirect(INGRESSO);

    expect(session()->has(Sessione::CHIAVE))->toBeFalse();
});

it('con la sessione aperta la pagina risponde', function () {
    apriSessione();

    $this->get('/pagina')->assertOk();
});

it('l\'ingresso si sceglie con ZR_AUTH_INGRESSO', function () {
    config(['zr-auth.ingresso' => '/accedi']);

    $this->get('/pagina')->assertRedirect(url('/accedi'));
});

// T6.1 (ZB1): il ritorno di un GET è l'indirizzo della richiesta, mai il Referer (quello di una scrittura: RitornoDalRefererTest).

it('senza sessione un GET ricorda l\'indirizzo della richiesta, con la query corta, e non il Referer', function () {
    $this->get(FRONTEND.'/pagina?vista=2', ['Referer' => 'https://altro.zeiras.com/x'])->assertRedirect(INGRESSO);

    expect(session('url.intended'))->toBe('https://board.zeiras.com/pagina?vista=2');
});

it('senza sessione HEAD, POST, PUT, PATCH e DELETE rimandano all\'ingresso e non ricordano niente: il Referer di una rotta senza workspace non vale', function (string $metodo) {
    Route::middleware('web')->any('modulo', fn () => 'ok');

    $this->call($metodo, FRONTEND.'/modulo', [], [], [], ['HTTP_REFERER' => 'https://altro.zeiras.com/x'])
        ->assertRedirect(INGRESSO);

    expect(session()->has('url.intended'))->toBeFalse();
})->with(['HEAD', 'POST', 'PUT', 'PATCH', 'DELETE']);

// #2985: il ritorno si ricorda solo per una navigazione. Un'immagine o un fetch bloccati rimandano all'ingresso come prima, ma non lasciano la loro
// pagina come «dove tornare»: dopo l'accesso si finirebbe sul file della foto.

it('senza sessione una navigazione (Sec-Fetch-Dest: document) ricorda la pagina (#2985, G1)', function () {
    $this->get('/pagina', ['Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Mode' => 'navigate', 'Accept' => 'text/html,application/xhtml+xml'])->assertRedirect(INGRESSO);

    expect(session('url.intended'))->toBe(url('/pagina'));
});

it('senza sessione un\'immagine, uno script, uno stile, un font o un fetch bloccati non ricordano niente e rimandano all\'ingresso come prima (#2985, G1, deve fallire se la pagina diventa il ritorno)', function (string $destinazione) {
    $this->get('/pagina', ['Sec-Fetch-Dest' => $destinazione, 'Accept' => 'image/avif,image/webp,image/*,*/*;q=0.8'])->assertRedirect(INGRESSO);

    expect(session()->has('url.intended'))->toBeFalse();
})->with(['image', 'script', 'style', 'font', 'empty', 'iframe', 'frame', 'object']);

it('una richiesta che non è una navigazione non cancella il ritorno che una navigazione ha già ricordato (#2985, G1)', function () {
    $this->get('/pagina?vista=2', ['Sec-Fetch-Dest' => 'document'])->assertRedirect(INGRESSO);
    $this->get('/pagina', ['Sec-Fetch-Dest' => 'image'])->assertRedirect(INGRESSO);

    expect(session('url.intended'))->toBe(url('/pagina?vista=2'));
});

it('dopo l\'accesso si torna alla pagina, mai al file di un\'immagine che ha trovato il gettone scaduto (#2985, G1)', function () {
    $this->get('/pagina', ['Sec-Fetch-Dest' => 'document']);
    $this->get('/pagina?x=1', ['Sec-Fetch-Dest' => 'image']);

    expect(Sessione::ritorno(url('/')))->toBe(url('/pagina'));
});

it('senza Sec-Fetch-Dest (un browser vecchio) una navigazione ricorda la pagina: Accept con HTML o senza Accept (#2985, G2, deve fallire se smette di ricordare)', function (?string $accept) {
    $this->get('/pagina', $accept === null ? [] : ['Accept' => $accept])->assertRedirect(INGRESSO);

    expect(session('url.intended'))->toBe(url('/pagina'));
})->with([null, 'text/html', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'application/xhtml+xml']);

it('senza Sec-Fetch-Dest un\'immagine (Accept di sole immagini) non ricorda niente (#2985, G1)', function () {
    $this->get('/pagina', ['Accept' => 'image/avif,image/webp,image/apng,image/*;q=0.8'])->assertRedirect(INGRESSO);

    expect(session()->has('url.intended'))->toBeFalse();
});

it('una visita di Inertia (un fetch con X-Inertia) ricorda la pagina anche con Sec-Fetch-Dest: empty (#2985, G1)', function () {
    $this->get('/pagina', ['X-Inertia' => 'true', 'Sec-Fetch-Dest' => 'empty', 'Accept' => 'text/html, application/xhtml+xml'])->assertStatus(409);

    expect(session('url.intended'))->toBe(url('/pagina'));
});
