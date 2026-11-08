<?php

use Illuminate\Support\Facades\Route;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\GettoneRifiutato;
use Zeiras\Auth\Errori\IndirizzoNonSicuro;
use Zeiras\Auth\Http\Middleware\ConGettone;
use Zeiras\Auth\Ingresso;
use Zeiras\Auth\Sessione;
use Zeiras\Auth\Testing\BackofficeFinto;

// A1.x di #3445 (per zr-superadmin): l'ingresso dello staff con il login normale di Zeiras, dal lato del modulo. `Ingresso::versoStaff()` manda a
// `ZR_HOME_URL/ingresso/staff`, il ritorno (lo stesso ricevitore degli altri ingressi) scambia il codice di `ingressi.staff.crea` col verificatore e apre
// la sessione dello staff: il gettone dello staff, mai quello di una persona.

beforeEach(function () {
    // Lo scambio del codice dello staff lo firma il client `superadmin` (#3445), col segreto che il finto dà a tutti i nomi.
    config(['zr-auth.home' => 'https://app.zeiras.com', 'zr-auth.app' => 'admin', 'zr-auth.cliente' => 'superadmin', 'zr-auth.segreto' => BackofficeFinto::SEGRETO_DEL_CLIENTE]);
    Route::middleware('web')->get('staff/entra', fn () => Ingresso::versoStaff())->withoutMiddleware(ConGettone::class);
});

/** La query di un indirizzo. @return array<string, string> */
function queryDelloStaff(string $indirizzo): array
{
    parse_str((string) parse_url($indirizzo, PHP_URL_QUERY), $query);

    return $query;
}

/** La sfida di un verificatore: base64url senza padding di SHA-256. */
function sfidaDelloStaff(string $verificatore): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verificatore, true)), '+/', '-_'), '=');
}

/** Il finto con il ritorno di `admin`, una persona dello staff (superadmin) e una persona qualunque. */
function fintoDelloStaffSso(): BackofficeFinto
{
    $finto = BackofficeFinto::attiva()->ritorno('admin', 'https://admin.zeiras.com/ingresso/ritorno');
    $finto->staffDi($finto->persona('sara@lucianocastro.com', PASSWORD, nome: 'Sara'), 'superadmin');
    $finto->persona('anna@example.com', PASSWORD);

    return $finto;
}

/**
 * Il giro di zr-home per lo staff: la persona entra (accessi.crea), chiede ingressi.staff.crea con la sfida della partenza e torna al `ritorno` che il
 * backoffice dà, con `codice` e `state`. Torna la risposta di ingressi.staff.crea e l'indirizzo a cui il browser va.
 *
 * @param  array<string, string>  $partenza  la query della partenza
 * @return array{Illuminate\Http\Client\Response, string}
 */
function giroDelloStaff(string $email, array $partenza): array
{
    $accesso = entraNelFinto($email)['gettone']['gettone'];
    $ingresso = alFinto('POST', '/v1/ingressi/staff', ['app' => $partenza['app'], 'sfida' => $partenza['sfida']], $accesso);

    return [$ingresso, $ingresso->status() === 201 ? $ingresso->json('data.ritorno').'?'.http_build_query(['codice' => $ingresso->json('data.codice'), 'state' => $partenza['state']]) : ''];
}

it('versoStaff manda a /ingresso/staff di zr-home con app admin, state e sfida, e il verificatore resta nella sessione (A1.1)', function () {
    $risposta = $this->get('/staff/entra');
    $location = (string) $risposta->headers->get('Location');
    $query = queryDelloStaff($location);
    $tenuto = session(Ingresso::CHIAVE);

    $risposta->assertStatus(302)->assertHeader('Referrer-Policy', 'no-referrer');
    expect($risposta->headers->get('Cache-Control'))->toContain('no-store')
        ->and(explode('?', $location)[0])->toBe('https://app.zeiras.com/ingresso/staff')
        ->and(array_keys($query))->toEqualCanonicalizing(['app', 'state', 'sfida'])
        ->and($query['app'])->toBe('admin')
        ->and($query['state'])->toBe($tenuto['state'])
        ->and($query['sfida'])->toBe(sfidaDelloStaff($tenuto['verificatore']))
        ->and($tenuto['staff'])->toBeTrue()
        ->and((string) $risposta->headers)->not->toContain($tenuto['verificatore']);
});

it('versoStaff è solo di admin e vuole un zr-home https (A1.1)', function () {
    config(['zr-auth.app' => 'board']);
    expect(fn () => Ingresso::versoStaff())->toThrow(LogicException::class);

    config(['zr-auth.app' => 'admin', 'zr-auth.home' => 'http://app.zeiras.com']);
    expect(fn () => Ingresso::versoStaff())->toThrow(IndirizzoNonSicuro::class);
});

it('il ritorno dello staff apre la sessione dello staff: il gettone dello staff, nessuna persona, nessun workspace (A1.2)', function () {
    fintoDelloStaffSso();
    $partenza = queryDelloStaff((string) $this->get('/staff/entra')->headers->get('Location'));
    [$ingresso, $ritorno] = giroDelloStaff('sara@lucianocastro.com', $partenza);
    expect($ingresso->status())->toBe(201)->and($ingresso->json('data.ritorno'))->toBe('https://admin.zeiras.com/ingresso/ritorno');

    $this->get($ritorno)->assertRedirect(url('/'));
    $staff = Sessione::staff();

    expect(Sessione::aperta())->toBeTrue()
        ->and($staff['ruolo'])->toBe('superadmin')
        ->and($staff['gettone'])->toStartWith('zr_')
        ->and(array_keys($staff))->toEqualCanonicalizing(['gettone', 'scade_il', 'inattivita_scade_il', 'id', 'nome', 'cognome', 'email', 'ruolo'])
        ->and(Sessione::utente())->toBeNull()
        ->and(Sessione::workspace())->toBeNull()
        ->and(Sessione::ruolo())->toBeNull()
        ->and(session(Ingresso::CHIAVE))->toBeNull()
        ->and(Api::staff($staff['gettone'])->get('/v1/staff/corrente')['data']['email'])->toBe('sara@lucianocastro.com')
        // Il gettone dello staff non è mai quello della persona né del workspace.
        ->and(fn () => Api::persona())->toThrow(GettoneRifiutato::class)
        ->and(fn () => Api::workspace())->toThrow(LogicException::class);
});

it('il ritorno dello staff cambia l\'id della sessione e il gettone CSRF, e scarta la sessione di prima (A1.2)', function () {
    fintoDelloStaffSso();
    $partenza = queryDelloStaff((string) $this->get('/staff/entra')->headers->get('Location'));
    $id = session()->getId();
    $csrf = csrf_token();
    [, $ritorno] = giroDelloStaff('sara@lucianocastro.com', $partenza);

    $this->get($ritorno);

    expect(session()->getId())->not->toBe($id)
        ->and(csrf_token())->not->toBe($csrf)
        ->and(Sessione::staff())->not->toBeNull();
});

it('chi non è nello staff e non ha un dominio ammesso è 403 permesso_negato, la stessa risposta per ogni motivo (A1.3)', function () {
    $finto = fintoDelloStaffSso();
    $finto->persona('semplice@lucianocastro.com', PASSWORD);
    $finto->staffDi($finto->persona('prova@lucianocastro.com', PASSWORD), 'supporto', true, true);
    $partenza = queryDelloStaff((string) $this->get('/staff/entra')->headers->get('Location'));

    foreach (['anna@example.com', 'semplice@lucianocastro.com', 'prova@lucianocastro.com'] as $email) {
        [$ingresso] = giroDelloStaff($email, $partenza);

        expect([$ingresso->status(), $ingresso->json('codice')])->toBe([403, 'permesso_negato']);
    }
});

it('chi ha un dominio ammesso riceve la riga di supporto alla prima volta, e la seconda non ne nasce un\'altra (A1.4)', function () {
    $finto = fintoDelloStaffSso()->staffDaiDomini(['lucianocastro.com']);
    $finto->persona('nuova@lucianocastro.com', PASSWORD, nome: 'Nuova');
    $finto->persona('fuori@example.com', PASSWORD);
    $partenza = queryDelloStaff((string) $this->get('/staff/entra')->headers->get('Location'));

    [$primo, $ritorno] = giroDelloStaff('nuova@lucianocastro.com', $partenza);
    [$secondo] = giroDelloStaff('nuova@lucianocastro.com', $partenza);
    [$fuori] = giroDelloStaff('fuori@example.com', $partenza);
    $this->get($ritorno);

    expect($primo->status())->toBe(201)->and($secondo->status())->toBe(201)->and($fuori->status())->toBe(403)
        ->and(Sessione::staff()['ruolo'])->toBe('supporto')
        ->and(count(Api::staff(Sessione::staff()['gettone'])->get('/v1/staff')['data']))->toBe(2);
});

it('il ritorno col verificatore sbagliato, lo state di un\'altra partenza o il codice già usato non apre niente (A1.5)', function () {
    fintoDelloStaffSso();
    $partenza = queryDelloStaff((string) $this->get('/staff/entra')->headers->get('Location'));
    [, $ritorno] = giroDelloStaff('sara@lucianocastro.com', $partenza);

    // Un altro state: la partenza è bruciata e il codice con lei.
    $this->get(preg_replace('/state=[^&]+/', 'state=altro', $ritorno))->assertRedirect(INGRESSO);
    expect(Sessione::staff())->toBeNull();

    // Una partenza nuova, il codice di prima (già bruciato, e con lo state di un'altra partenza) non vale.
    $this->get('/staff/entra');
    $this->get($ritorno)->assertRedirect(INGRESSO);
    expect(Sessione::staff())->toBeNull();

    // Una partenza intatta e un codice suo: entra.
    $terza = queryDelloStaff((string) $this->get('/staff/entra')->headers->get('Location'));
    [, $buono] = giroDelloStaff('sara@lucianocastro.com', $terza);
    $this->get($buono)->assertRedirect(url('/'));
    expect(Sessione::staff())->not->toBeNull();
});

it('il codice dello staff lo scambia solo il client superadmin: un altro client è la stessa pagina d\'errore (A1.6)', function () {
    fintoDelloStaffSso();
    $partenza = queryDelloStaff((string) $this->get('/staff/entra')->headers->get('Location'));
    [, $ritorno] = giroDelloStaff('sara@lucianocastro.com', $partenza);
    config(['zr-auth.cliente' => 'monitor']);

    $this->get($ritorno)->assertRedirect(INGRESSO);

    expect(Sessione::staff())->toBeNull()->and(Sessione::aperta())->toBeFalse();
});

it('Sessione::entraStaff vuole la forma dello staff e non scrive niente se manca un campo (A1.7)', function () {
    $incompleto = ['scade_il' => '2026-11-04T18:00:00.000Z', 'inattivita_scade_il' => '2026-11-04T10:00:00.000Z', 'id' => 'a', 'ruolo' => 'superadmin'];

    expect(fn () => Sessione::entraStaff($incompleto))->toThrow(InvalidArgumentException::class)
        ->and(Sessione::staff())->toBeNull();
});

it('il finto: i metodi del vecchio ingresso valgono solo per le persone di prova (#3445)', function () {
    $finto = fintoDelloStaffSso();
    $gettone = $finto->ingressoDelloStaff('sara@lucianocastro.com');
    $esito = function (Closure $chiamata) {
        try {
            $chiamata();
        } catch (Zeiras\Auth\Errori\ErroreApi $errore) {
            return [$errore->stato, $errore->codice];
        }

        return ['ok', null];
    };

    $sara = Api::staff($gettone)->get('/v1/staff')['data'][0];

    expect(array_key_exists('totp_confermato', $sara))->toBeFalse()
        ->and($esito(fn () => Api::staff($gettone)->post('/v1/staff/'.$sara['id'].'/totp/reimpostazione', ['motivo' => 'assistenza'])))->toBe([403, 'permesso_negato'])
        ->and($esito(fn () => Api::staff()->post('/v1/staff/inviti/accettazione', ['segreto' => str_repeat('a', 20), 'password' => PASSWORD])))->toBe([401, 'invito_non_valido'])
        ->and($esito(fn () => Api::staff()->post('/v1/staff/accessi/verifica', ['sfida' => str_repeat('a', 64), 'codice' => '000000'])))->toBe([401, 'secondo_fattore_non_valido']);
});

it('entraStaff tiene la pagina ricordata dalla guardia: chi scade su /staff e rientra torna a /staff (#3445)', function () {
    $finto = fintoDelloStaffSso();
    session()->put('url.intended', request()->getSchemeAndHttpHost().'/staff');
    $staff = $finto->ingressoDelloStaff($finto->staffDi($finto->persona('luca@lucianocastro.com', PASSWORD, nome: 'Luca'), 'supporto', false));
    $id = session()->getId();

    Sessione::entraStaff(['gettone' => $staff, 'scade_il' => '2026-11-07T18:00:00.000Z', 'inattivita_scade_il' => '2026-11-07T10:30:00.000Z', 'id' => '01k6r3a7c2e6g0j4m8p2s6v0x4', 'ruolo' => 'supporto']);

    expect(session()->getId())->not->toBe($id)
        ->and(Sessione::ritorno('/'))->toBe(request()->getSchemeAndHttpHost().'/staff');
});

it('su admin il ricevitore c\'è solo in GET: il POST di «Accedi come» non è registrato; negli altri frontend sì (#3445)', function () {
    $registra = function (string $app): array {
        config(['zr-auth.app' => $app]);
        app('router')->setRoutes(new Illuminate\Routing\RouteCollection);
        (new Zeiras\Auth\ZrAuthServiceProvider(app()))->boot();

        return collect(app('router')->getRoutes()->getRoutes())->map(fn ($r) => (string) $r->getName())->all();
    };

    expect($registra('admin'))->toContain('zr-auth.ricevitore')->not->toContain('zr-auth.ricevitore.assistenza')
        ->and($registra('bookings'))->toContain('zr-auth.ricevitore', 'zr-auth.ricevitore.assistenza');
});
