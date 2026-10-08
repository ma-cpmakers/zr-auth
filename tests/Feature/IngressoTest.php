<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Zeiras\Auth\Errori\BackofficeNonRisponde;
use Zeiras\Auth\Http\Middleware\ConGettone;
use Zeiras\Auth\Ingresso;
use Zeiras\Auth\Sessione;
use Zeiras\Auth\Testing\BackofficeFinto;
use Zeiras\Auth\Testing\Rotte;

// T2.1-T2.5 dello sprint 10 (#1347; per zr-home #1210): l'ingresso nei moduli dal lato del modulo, cioè la partenza verso
// `ZR_HOME_URL/ingresso` e il ricevitore del codice. Il backoffice è il finto (T1): nessun Http::fake scritto a mano per
// `ingressi.scambio.crea` nel giro intero (T2.5); gli esiti che il finto non dà (429, 5xx, trasporto) li fa Http::fake.

const HOME_DI_PROVA = 'https://app.zeiras.com';
const RICEVITORE_DI_PROVA = '/ingresso/ritorno';

beforeEach(function () {
    config(['zr-auth.home' => HOME_DI_PROVA, 'zr-auth.app' => 'pm']);
    // La partenza la chiama una pagina del modulo: qui una rotta pubblica, `parti/{workspace}`.
    Route::middleware('web')->get('parti/{workspace}', fn (string $workspace) => Ingresso::verso($workspace))->withoutMiddleware(ConGettone::class);
});

/** La sfida di un verificatore: base64url senza padding di SHA-256. */
function sfidaDelVerificatore(string $verificatore): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verificatore, true)), '+/', '-_'), '=');
}

/** Il finto con una persona, un workspace suo con `pm` attiva e il ritorno di `pm` nel modulo di prova. [finto, workspace] */
function fintoDelModulo(): array
{
    $finto = BackofficeFinto::attiva()->ritorno('pm', FRONTEND.RICEVITORE_DI_PROVA);
    $studio = $finto->workspace('Studio', $finto->persona('anna@example.com', PASSWORD));
    $finto->attivaApp($studio, 'pm');

    return [$finto, $studio];
}

/** La query di un indirizzo. @return array<string, string> */
function queryDi(string $indirizzo): array
{
    parse_str((string) parse_url($indirizzo, PHP_URL_QUERY), $query);

    return $query;
}

/**
 * Il giro di zr-home: la persona entra nel workspace (gettoni.crea), chiede ingressi.crea con la sfida della partenza e
 * torna al `ritorno` che il backoffice dà, con `codice` e `state`. L'indirizzo a cui il browser va.
 *
 * @param  array<string, string>  $partenza  la query della partenza
 */
function ritornoDiHome(array $workspace, array $partenza): string
{
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $workspace['id']], $accesso)->json('data.gettone');
    $ingresso = alFinto('POST', '/v1/ingressi', ['app' => $partenza['app'], 'sfida' => $partenza['sfida']], $gettone);
    expect($ingresso->status())->toBe(201);

    return $ingresso->json('data.ritorno').'?'.http_build_query(['codice' => $ingresso->json('data.codice'), 'state' => $partenza['state']]);
}

/** Da qui il backoffice risponde come dice il test: il finto, che ha già fatto il suo giro, si toglie di mezzo. */
function ilBackofficeRisponde(mixed $risposta): void
{
    Http::swap(new Factory);
    Http::fake(['*' => $risposta]);
}

/** Le chiamate a ingressi.scambio.crea partite finora. */
function scambiPartiti(): int
{
    return Http::recorded(fn (Request $richiesta) => str_ends_with($richiesta->url(), '/v1/ingressi/scambio'))->count();
}

/** I verificatori che le chiamate a ingressi.scambio.crea hanno mandato finora. @return list<string> */
function verificatoriMandati(): array
{
    return Http::recorded(fn (Request $richiesta) => str_ends_with($richiesta->url(), '/v1/ingressi/scambio'))
        ->map(fn (array $coppia) => (string) $coppia[0]->data()['verificatore'])->values()->all();
}

// T2.1

it('la partenza manda a /ingresso di zr-home con app, workspace, state e sfida, e il verificatore resta nella sessione (T2.1)', function () {
    $risposta = $this->get('/parti/studio');
    $location = (string) $risposta->headers->get('Location');
    $query = queryDi($location);
    $tenuto = session(Ingresso::CHIAVE);

    $risposta->assertStatus(302)->assertHeader('Referrer-Policy', 'no-referrer');
    expect($risposta->headers->get('Cache-Control'))->toContain('no-store')
        ->and(explode('?', $location)[0])->toBe(HOME_DI_PROVA.'/ingresso')
        ->and(array_keys($query))->toEqualCanonicalizing(['app', 'workspace', 'state', 'sfida'])
        ->and($query['app'])->toBe('pm')
        ->and($query['workspace'])->toBe('studio')
        ->and($query['state'])->toMatch('/^[A-Za-z0-9._~-]{43}$/')
        ->and($query['state'])->toBe($tenuto['state'])
        ->and($tenuto['verificatore'])->toMatch('/^[A-Za-z0-9._~-]{64}$/')
        ->and($query['sfida'])->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($query['sfida'])->toBe(sfidaDelVerificatore($tenuto['verificatore']));
});

it('il verificatore non compare né nell\'indirizzo né in un header della partenza (T2.1)', function () {
    $risposta = $this->get('/parti/studio');
    $verificatore = session(Ingresso::CHIAVE)['verificatore'];

    expect((string) $risposta->headers)->not->toContain($verificatore)
        ->and($risposta->headers->get('Location'))->not->toContain($verificatore)
        ->and((string) $risposta->getContent())->not->toContain($verificatore);
});

it('ogni partenza ha il suo state e il suo verificatore, e la nuova prende il posto della vecchia (T2.1)', function () {
    $prima = queryDi((string) $this->get('/parti/studio')->headers->get('Location'));
    $dopo = queryDi((string) $this->get('/parti/studio')->headers->get('Location'));

    expect($dopo['state'])->not->toBe($prima['state'])
        ->and(session(Ingresso::CHIAVE)['state'])->toBe($dopo['state']);
});

// T2.2

it('il ricevitore è un GET e basta (T2.2)', function (string $metodo) {
    $this->call($metodo, RICEVITORE_DI_PROVA)->assertStatus(405);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('un ritorno da un altro sito non apre la sessione e va alla pagina d\'errore, e non tocca la partenza della persona (T2.2; #1359, T4.1)', function (array $header) {
    [, $studio] = fintoDelModulo();
    $partenza = queryDi((string) $this->get('/parti/'.$studio['slug'])->headers->get('Location'));
    $indirizzo = ritornoDiHome($studio, $partenza);
    $tenuta = session(Ingresso::CHIAVE);

    $this->get($indirizzo, $header)->assertRedirect(INGRESSO);

    // Lo scambio che parte è quello a vuoto, che brucia il codice (T4.1): mai il verificatore della partenza.
    expect(scambiPartiti())->toBe(1)
        ->and(verificatoriMandati())->not->toContain($tenuta['verificatore'])
        ->and(Sessione::aperta())->toBeFalse()
        ->and(session(Ingresso::CHIAVE))->toBe($tenuta);
})->with([
    'Sec-Fetch-Site cross-site' => [['Sec-Fetch-Site' => 'cross-site']],
    'Origin straniero' => [['Origin' => 'https://evil.example']],
]);

it('un ritorno dallo stesso sito, o scritto a mano nella barra, scambia (T2.2)', function (array $header) {
    [, $studio] = fintoDelModulo();
    $partenza = queryDi((string) $this->get('/parti/'.$studio['slug'])->headers->get('Location'));

    $this->get(ritornoDiHome($studio, $partenza), $header);

    expect(scambiPartiti())->toBe(1)
        ->and(Sessione::aperta())->toBeTrue();
})->with([
    'same-site' => [['Sec-Fetch-Site' => 'same-site']],
    'same-origin' => [['Sec-Fetch-Site' => 'same-origin']],
    'none' => [['Sec-Fetch-Site' => 'none']],
    'senza header' => [[]],
    'Origin di zr-home' => [['Origin' => HOME_DI_PROVA]],
]);

it('senza uno state in sessione, o con uno state diverso, il ricevitore non apre la sessione e brucia il codice (T2.2; #1359, T4.1)', function (bool $conPartenza) {
    [, $studio] = fintoDelModulo();
    $partenza = queryDi((string) $this->get('/parti/'.$studio['slug'])->headers->get('Location'));
    $indirizzo = ritornoDiHome($studio, $partenza);
    $verificatore = session(Ingresso::CHIAVE)['verificatore'];
    $altroState = str_replace('state='.urlencode($partenza['state']), 'state=uno-state-che-non-e-quello-della-partenza', $indirizzo);

    if (! $conPartenza) {
        session()->forget(Ingresso::CHIAVE);
    }

    $this->get($altroState)->assertRedirect(INGRESSO);

    expect(scambiPartiti())->toBe(1)
        ->and(verificatoriMandati())->not->toContain($verificatore)
        ->and(Sessione::aperta())->toBeFalse();

    // Il codice è bruciato: nemmeno chi ha il verificatore giusto lo scambia più.
    $codice = queryDi($indirizzo)['codice'];
    $scambio = alFinto('POST', '/v1/ingressi/scambio', ['codice' => $codice, 'verificatore' => $verificatore]);
    expect($scambio->status())->toBe(422)->and($scambio->json('codice'))->toBe('verifica_non_riuscita');
})->with(['con una partenza, state diverso' => [true], 'senza partenza' => [false]]);

// T2.3

it('con lo state giusto scambia il codice, apre la sessione e torna a un indirizzo pulito (T2.3)', function () {
    [, $studio] = fintoDelModulo();
    $partenza = queryDi((string) $this->get('/parti/'.$studio['slug'])->headers->get('Location'));
    $risposta = $this->get(ritornoDiHome($studio, $partenza));

    $risposta->assertStatus(302)->assertHeader('Referrer-Policy', 'no-referrer');
    expect($risposta->headers->get('Cache-Control'))->toContain('no-store')
        ->and($risposta->headers->get('Location'))->toBe(FRONTEND)
        ->and(Sessione::aperta())->toBeTrue()
        ->and(Sessione::workspace()['id'])->toBe($studio['id'])
        ->and(Sessione::ruolo())->toBe('proprietario')
        ->and(scambiPartiti())->toBe(1);
});

it('torna alla pagina che la guardia ricordava, se è del modulo, senza codice né state (T2.3)', function () {
    [, $studio] = fintoDelModulo();
    $partenza = queryDi((string) $this->get('/parti/'.$studio['slug'])->headers->get('Location'));
    session(['url.intended' => FRONTEND.'/schede/7']);

    $this->get(ritornoDiHome($studio, $partenza))->assertRedirect(FRONTEND.'/schede/7');
});

it('state e verificatore escono dalla sessione, e lo stesso state non vale due volte (T2.3)', function () {
    [, $studio] = fintoDelModulo();
    $partenza = queryDi((string) $this->get('/parti/'.$studio['slug'])->headers->get('Location'));
    $indirizzo = ritornoDiHome($studio, $partenza);

    $this->get($indirizzo)->assertRedirect(FRONTEND);

    expect(session()->has(Ingresso::CHIAVE))->toBeFalse();

    $this->get($indirizzo)->assertRedirect(INGRESSO);

    expect(scambiPartiti())->toBe(1);
});

it('un codice che il backoffice rifiuta o frena va alla pagina d\'errore, e state e verificatore escono lo stesso (T2.3)', function (string $codice, int $stato) {
    [, $studio] = fintoDelModulo();
    $partenza = queryDi((string) $this->get('/parti/'.$studio['slug'])->headers->get('Location'));
    $indirizzo = ritornoDiHome($studio, $partenza);
    ilBackofficeRisponde(problema($stato, $codice, header: $stato === 429 ? ['Retry-After' => '30'] : []));

    $this->get($indirizzo)->assertRedirect(INGRESSO);

    expect(session()->has(Ingresso::CHIAVE))->toBeFalse()
        ->and(Sessione::aperta())->toBeFalse();
})->with([
    'verifica_non_riuscita' => ['verifica_non_riuscita', 422],
    'troppe_richieste' => ['troppe_richieste', 429],
]);

it('un guasto del backoffice è BackofficeNonRisponde, mai una sessione a metà (T2.3)', function (Closure $risposta) {
    [, $studio] = fintoDelModulo();
    $partenza = queryDi((string) $this->get('/parti/'.$studio['slug'])->headers->get('Location'));
    $indirizzo = ritornoDiHome($studio, $partenza);
    ilBackofficeRisponde($risposta());
    $this->withoutExceptionHandling();

    expect(fn () => $this->get($indirizzo))->toThrow(BackofficeNonRisponde::class)
        ->and(session()->has(Ingresso::CHIAVE))->toBeFalse()
        ->and(Sessione::aperta())->toBeFalse();
})->with([
    'un 5xx col suo problema' => [fn () => fn () => problema(503, 'servizio_non_disponibile')],
    'un 5xx senza JSON' => [fn () => fn () => Http::response('Bad Gateway', 502)],
    'il trasporto che cade' => [fn () => fn () => throw new ConnectionException('cURL error 28: timeout')],
    'una risposta senza la forma' => [fn () => fn () => Http::response(['data' => ['gettone' => GETTONE_WORKSPACE]], 201)],
    'una risposta senza data' => [fn () => fn () => Http::response(['ok' => true], 201)],
]);

// T2.4

it('un codice che non ha la forma attesa non parte verso il backoffice, qualunque sia lo state (T2.4; #1359, T4.2)', function (array $query) {
    Http::fake();
    $this->get('/parti/studio');
    $state = session(Ingresso::CHIAVE)['state'];
    $query = array_map(fn (mixed $valore) => $valore === '{state}' ? $state : $valore, $query);

    $this->get(RICEVITORE_DI_PROVA.'?'.http_build_query($query))->assertRedirect(INGRESSO);

    Http::assertNothingSent();
})->with(fn () => [
    'un codice di 10 000 caratteri' => [['codice' => str_repeat('a', 10000), 'state' => '{state}']],
    'un codice di 10 000 caratteri e uno state sbagliato' => [['codice' => str_repeat('a', 10000), 'state' => 'sbagliato']],
    'un codice di 42 caratteri' => [['codice' => str_repeat('a', 42), 'state' => '{state}']],
    'un codice con un carattere fuori dall\'alfabeto' => [['codice' => str_repeat('a', 42).'+', 'state' => '{state}']],
    'un codice che è un array' => [['codice' => [str_repeat('a', 43)], 'state' => '{state}']],
    'senza codice' => [['state' => '{state}']],
    'senza codice e senza state' => [[]],
]);

it('uno state che non ha la forma attesa, con un codice che l\'ha, brucia il codice e non apre la sessione (T2.4; #1359, T4.1)', function (array $query) {
    Http::fake();
    $this->get('/parti/studio');
    $state = session(Ingresso::CHIAVE)['state'];
    $query = array_map(fn (mixed $valore) => $valore === '{state}' ? $state : $valore, $query);

    $this->get(RICEVITORE_DI_PROVA.'?'.http_build_query($query))->assertRedirect(INGRESSO);

    expect(scambiPartiti())->toBe(1)->and(Sessione::aperta())->toBeFalse();
})->with(fn () => [
    'senza state' => [['codice' => str_repeat('a', 43)]],
    'uno state di 513 caratteri' => [['codice' => str_repeat('a', 43), 'state' => str_repeat('s', 513)]],
    'uno state con un carattere fuori dall\'alfabeto' => [['codice' => str_repeat('a', 43), 'state' => 'uno state con spazi']],
]);

// #1359

it('lo scambio a vuoto porta un verificatore casuale di 64 caratteri, diverso a ogni ritorno rifiutato (T4.1)', function () {
    Http::fake();
    $this->get('/parti/studio');

    foreach (range(1, 3) as $n) {
        $this->get(RICEVITORE_DI_PROVA.'?'.http_build_query(['codice' => str_repeat('a', 43), 'state' => 'sbagliato']))->assertRedirect(INGRESSO);
    }

    $mandati = verificatoriMandati();
    expect($mandati)->toHaveCount(3)->and(array_unique($mandati))->toHaveCount(3);

    foreach ($mandati as $verificatore) {
        expect($verificatore)->toMatch('/^[0-9a-f]{64}$/');
    }
});

it('un guasto dello scambio a vuoto non cambia la risposta alla persona: la stessa pagina d\'errore, mai BackofficeNonRisponde (T4.3)', function (Closure $risposta) {
    [, $studio] = fintoDelModulo();
    $partenza = queryDi((string) $this->get('/parti/'.$studio['slug'])->headers->get('Location'));
    $indirizzo = ritornoDiHome($studio, $partenza);
    $tenuta = session(Ingresso::CHIAVE);
    ilBackofficeRisponde($risposta());
    $this->withoutExceptionHandling();

    $esito = $this->get($indirizzo, ['Sec-Fetch-Site' => 'cross-site']);

    $esito->assertRedirect(INGRESSO)->assertHeader('Referrer-Policy', 'no-referrer');
    expect($esito->headers->get('Cache-Control'))->toContain('no-store')
        ->and(scambiPartiti())->toBe(1)
        ->and(session(Ingresso::CHIAVE))->toBe($tenuta)
        ->and(Sessione::aperta())->toBeFalse();
})->with([
    'un 429 con Retry-After' => [fn () => fn () => problema(429, 'troppe_richieste', header: ['Retry-After' => '30'])],
    'un 5xx col suo problema' => [fn () => fn () => problema(503, 'servizio_non_disponibile')],
    'un 5xx senza JSON' => [fn () => fn () => Http::response('Bad Gateway', 502)],
    'il trasporto che cade' => [fn () => fn () => throw new ConnectionException('cURL error 28: timeout')],
]);

it('la pagina d\'errore di un ritorno rifiutato è la stessa di prima, con gli stessi header, con o senza il codice bruciato (T4.1)', function () {
    [, $studio] = fintoDelModulo();
    $partenza = queryDi((string) $this->get('/parti/'.$studio['slug'])->headers->get('Location'));
    $buono = $this->get(ritornoDiHome($studio, $partenza), ['Sec-Fetch-Site' => 'cross-site']);
    $malformato = $this->get(RICEVITORE_DI_PROVA.'?codice=x&state=y', ['Sec-Fetch-Site' => 'cross-site']);

    foreach (['Location', 'Referrer-Policy', 'Cache-Control'] as $header) {
        expect($buono->headers->get($header))->toBe($malformato->headers->get($header));
    }
});

// T2.5

it('partenza, ingressi.crea del finto, ricevitore: la sessione si apre col workspace e il ruolo giusti (T2.5)', function () {
    [, $studio] = fintoDelModulo();

    $partenza = queryDi((string) $this->get('/parti/'.$studio['slug'])->headers->get('Location'));
    $this->get(ritornoDiHome($studio, $partenza))->assertRedirect(FRONTEND);

    expect(Sessione::aperta())->toBeTrue()
        ->and(Sessione::utente()['email'])->toBe('anna@example.com')
        ->and(Sessione::workspace())->toMatchArray(['id' => $studio['id'], 'slug' => $studio['slug']])
        ->and(Sessione::ruolo())->toBe('proprietario');
});

it('la rotta del ricevitore è pubblica, e il test del frontend la nomina (T2.5)', function () {
    $scoperte = Rotte::senzaGuardia();

    expect($scoperte)->toContain('GET ingresso/ritorno')
        ->and(Rotte::senzaGuardia(['GET ingresso/ritorno']))->not->toContain('GET ingresso/ritorno');
});
