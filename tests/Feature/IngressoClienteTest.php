<?php

use Illuminate\Support\Facades\Route;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\IndirizzoNonSicuro;
use Zeiras\Auth\Http\Middleware\ConGettone;
use Zeiras\Auth\Ingresso;
use Zeiras\Auth\Sessione;
use Zeiras\Auth\Testing\BackofficeFinto;

// D1.1-D1.4 di #3324 (per zr-bookings #3251 e zr-home): l'ingresso del cliente che non è membro di nessun workspace, dal lato del
// modulo. `Ingresso::versoCliente()` manda a `ZR_HOME_URL/ingresso/cliente`, il ritorno (lo stesso ricevitore del workspace) scambia il
// codice di `ingressi.clienti.crea` e apre la sessione del cliente: la persona, il gettone ristretto, mai un workspace o un ruolo.

beforeEach(function () {
    config(['zr-auth.home' => 'https://app.zeiras.com', 'zr-auth.app' => 'bookings']);
    Route::middleware('web')->get('cliente/entra', fn () => Ingresso::versoCliente())->withoutMiddleware(ConGettone::class);
    Route::middleware(['web', ConGettone::class])->get('cliente/pagina', fn () => response('pagina del cliente'));
});

/** La query di un indirizzo. @return array<string, string> */
function queryDelCliente(string $indirizzo): array
{
    parse_str((string) parse_url($indirizzo, PHP_URL_QUERY), $query);

    return $query;
}

/** La sfida di un verificatore: base64url senza padding di SHA-256. */
function sfidaDelCliente(string $verificatore): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verificatore, true)), '+/', '-_'), '=');
}

/** Il finto con la persona del cliente (nessun workspace) e il ritorno di `bookings` nel modulo di prova. */
function fintoDelCliente(): BackofficeFinto
{
    $finto = BackofficeFinto::attiva()->ritorno('bookings', FRONTEND.'/ingresso/ritorno');
    $finto->persona('cliente@example.com', PASSWORD);

    return $finto;
}

/**
 * Il giro di zr-home per il cliente: la persona entra (accessi.crea), chiede ingressi.clienti.crea con la sfida della partenza e
 * torna al `ritorno` che il backoffice dà, con `codice` e `state`. L'indirizzo a cui il browser va.
 *
 * @param  array<string, string>  $partenza  la query della partenza
 */
function ritornoDelCliente(array $partenza): string
{
    $accesso = entraNelFinto('cliente@example.com')['gettone']['gettone'];
    $ingresso = alFinto('POST', '/v1/ingressi/clienti', ['app' => $partenza['app'], 'sfida' => $partenza['sfida']], $accesso);
    expect($ingresso->status())->toBe(201);

    return $ingresso->json('data.ritorno').'?'.http_build_query(['codice' => $ingresso->json('data.codice'), 'state' => $partenza['state']]);
}

// D1.1

it('versoCliente manda a /ingresso/cliente di zr-home con app, state e sfida, e il verificatore resta nella sessione (D1.1)', function () {
    $risposta = $this->get('/cliente/entra');
    $location = (string) $risposta->headers->get('Location');
    $query = queryDelCliente($location);
    $tenuto = session(Ingresso::CHIAVE);

    $risposta->assertStatus(302)->assertHeader('Referrer-Policy', 'no-referrer');
    expect($risposta->headers->get('Cache-Control'))->toContain('no-store')
        ->and(explode('?', $location)[0])->toBe('https://app.zeiras.com/ingresso/cliente')
        ->and(array_keys($query))->toEqualCanonicalizing(['app', 'state', 'sfida'])
        ->and($query['app'])->toBe('bookings')
        ->and($query['state'])->toBe($tenuto['state'])
        ->and($query['sfida'])->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($query['sfida'])->toBe(sfidaDelCliente($tenuto['verificatore']));
});

it('il verificatore del cliente non compare né nell\'indirizzo né in un header, e non porta la scelta del modulo nel ritorno (D1.1)', function () {
    $risposta = $this->get('/cliente/entra?scelta=sala&pagina=x');
    $verificatore = session(Ingresso::CHIAVE)['verificatore'];

    expect((string) $risposta->headers)->not->toContain($verificatore)
        ->and((string) $risposta->getContent())->not->toContain($verificatore)
        ->and($risposta->headers->get('Location'))->not->toContain('scelta')->not->toContain('sala')->not->toContain('pagina');
});

it('ogni partenza del cliente ha il suo state e il suo verificatore, e la nuova prende il posto della vecchia (D1.1)', function () {
    $prima = queryDelCliente((string) $this->get('/cliente/entra')->headers->get('Location'));
    $dopo = queryDelCliente((string) $this->get('/cliente/entra')->headers->get('Location'));

    expect($dopo['state'])->not->toBe($prima['state'])
        ->and(session(Ingresso::CHIAVE)['state'])->toBe($dopo['state']);
});

it('versoCliente vuole il codice dell\'app e un zr-home https, come verso (D1.1)', function () {
    config(['zr-auth.app' => null]);
    expect(fn () => Ingresso::versoCliente())->toThrow(LogicException::class);

    config(['zr-auth.app' => 'bookings', 'zr-auth.home' => 'http://app.zeiras.com']);
    expect(fn () => Ingresso::versoCliente())->toThrow(IndirizzoNonSicuro::class);
});

// D1.2

it('il ritorno del cliente apre una sessione con la persona e senza workspace né ruolo (D1.2)', function () {
    fintoDelCliente();
    $partenza = queryDelCliente((string) $this->get('/cliente/entra')->headers->get('Location'));

    $this->get(ritornoDelCliente($partenza))->assertRedirect(url('/'));

    expect(Sessione::aperta())->toBeTrue()
        ->and(Sessione::utente()['email'])->toBe('cliente@example.com')
        ->and(Sessione::workspace())->toBeNull()
        ->and(Sessione::ruolo())->toBeNull()
        ->and(Sessione::ospite())->toBeNull()
        ->and(session(Ingresso::CHIAVE))->toBeNull();
});

it('il gettone ristretto del cliente sta nella sessione e vale per i metodi del cliente, non per quelli del workspace (D1.2)', function () {
    fintoDelCliente();
    $partenza = queryDelCliente((string) $this->get('/cliente/entra')->headers->get('Location'));
    $this->get(ritornoDelCliente($partenza));

    expect(Api::persona()->get('/v1/io')['data']['utente']['email'])->toBe('cliente@example.com')
        ->and(fn () => Api::workspace())->toThrow(LogicException::class);
});

it('il ritorno del cliente cambia l\'id della sessione e il gettone CSRF (D1.2)', function () {
    fintoDelCliente();
    $partenza = queryDelCliente((string) $this->get('/cliente/entra')->headers->get('Location'));
    $id = session()->getId();
    $csrf = csrf_token();

    $this->get(ritornoDelCliente($partenza));

    expect(session()->getId())->not->toBe($id)
        ->and(csrf_token())->not->toBe($csrf);
});

it('il ritorno di una partenza del cliente con il codice di un workspace non apre niente e va alla pagina d\'errore (D1.2)', function () {
    $finto = fintoDelCliente()->ritorno('pm', FRONTEND.'/ingresso/ritorno');
    $studio = $finto->workspace('Studio', $finto->persona('anna@example.com', PASSWORD));
    $finto->attivaApp($studio, 'pm');
    $partenza = queryDelCliente((string) $this->get('/cliente/entra')->headers->get('Location'));

    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso)->json('data.gettone');
    $ingresso = alFinto('POST', '/v1/ingressi', ['app' => 'pm', 'sfida' => $partenza['sfida']], $gettone);
    expect($ingresso->status())->toBe(201);

    $this->get($ingresso->json('data.ritorno').'?'.http_build_query(['codice' => $ingresso->json('data.codice'), 'state' => $partenza['state']]))
        ->assertRedirect(INGRESSO);

    expect(Sessione::aperta())->toBeFalse();
});

it('il ritorno di una partenza del workspace con il codice di un cliente non apre niente e va alla pagina d\'errore (D1.2)', function () {
    fintoDelCliente();
    Route::middleware('web')->get('parti/{workspace}', fn (string $workspace) => Ingresso::verso($workspace))->withoutMiddleware(ConGettone::class);
    $partenza = queryDelCliente((string) $this->get('/parti/studio')->headers->get('Location'));

    $this->get(ritornoDelCliente($partenza))->assertRedirect(INGRESSO);

    expect(Sessione::aperta())->toBeFalse();
});

it('lo state del cliente vale una volta: un secondo ritorno con lo stesso codice va alla pagina d\'errore (D1.2)', function () {
    fintoDelCliente();
    $partenza = queryDelCliente((string) $this->get('/cliente/entra')->headers->get('Location'));
    $indirizzo = ritornoDelCliente($partenza);

    $this->get($indirizzo)->assertRedirect(url('/'));
    $this->get($indirizzo)->assertRedirect(INGRESSO);
});

// D1.3

it('la guardia lascia passare il cliente senza workspace e non lo rimanda a un workspace (D1.3)', function () {
    fintoDelCliente();
    $partenza = queryDelCliente((string) $this->get('/cliente/entra')->headers->get('Location'));
    $this->get(ritornoDelCliente($partenza));

    $this->get('/cliente/pagina')->assertOk()->assertSee('pagina del cliente');
});

it('il finto risponde a ingressi.clienti.crea e allo scambio del cliente (D1.3)', function () {
    fintoDelCliente();
    $partenza = queryDelCliente((string) $this->get('/cliente/entra')->headers->get('Location'));
    $accesso = entraNelFinto('cliente@example.com')['gettone']['gettone'];

    $ingresso = alFinto('POST', '/v1/ingressi/clienti', ['app' => 'bookings', 'sfida' => $partenza['sfida']], $accesso);

    expect($ingresso->status())->toBe(201)
        ->and($ingresso->json('data.ritorno'))->toBe(FRONTEND.'/ingresso/ritorno');
});

it('entraCliente rifiuta una forma che non è quella di un cliente e non scrive niente (D1.2)', function (array $modifica) {
    $dati = [
        'gettone' => 'zr_prova', 'scade_il' => now()->addHour()->toIso8601String(), 'utente' => ['id' => 'u1', 'email' => 'cliente@example.com'],
        'workspace' => null, 'ruolo' => null,
    ];

    expect(fn () => Sessione::entraCliente($modifica + $dati))->toThrow(InvalidArgumentException::class)
        ->and(Sessione::aperta())->toBeFalse();
})->with([
    'un workspace' => [['workspace' => ['id' => 'w1']]],
    'un ruolo' => [['ruolo' => 'membro']],
    'senza gettone' => [['gettone' => '']],
    'senza scadenza' => [['scade_il' => null]],
    'senza persona' => [['utente' => null]],
]);
