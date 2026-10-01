<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Zeiras\Auth\Contesto;
use Zeiras\Auth\Persona;

/*
 * L'ingresso di un modulo (voce #978, T3.1-T3.5): senza sessione si va a zr-home, al ritorno si verifica l'id_token e si
 * apre una sessione di un workspace solo, che vale al massimo 12 ore.
 */

it('senza sessione ogni pagina rimanda all\'ingresso di zr-home, con PKCE S256, state e nonce (T3.1)', function () {
    foreach (['/pagina', '/note', '/note/1'] as $pagina) {
        $chiesto = ingressoChiesto($this->get($pagina)->assertRedirect());

        expect(array_keys($chiesto))->toEqualCanonicalizing([
            'client_id', 'redirect_uri', 'response_type', 'scope', 'state', 'nonce', 'code_challenge', 'code_challenge_method',
        ])->and($chiesto)->toMatchArray([
            'client_id' => CLIENTE,
            'redirect_uri' => MODULO.'/auth/callback',
            'response_type' => 'code',
            'scope' => 'openid profile email workspace',
            'code_challenge_method' => 'S256',
        ])->and(strlen($chiesto['state']))->toBeGreaterThanOrEqual(40)
            ->and(strlen($chiesto['nonce']))->toBeGreaterThanOrEqual(40)
            ->and($chiesto['code_challenge'])->toMatch('/^[A-Za-z0-9_-]{43}$/');
    }
});

it('l\'indirizzo che porta il workspace lo passa all\'ingresso (T3.1)', function () {
    expect(ingressoChiesto($this->get('/pagina?workspace=8'))['workspace'] ?? null)->toBe('8');
});

it('senza sessione una richiesta JSON e un\'API rispondono 401, una visita di Inertia 409 verso zr-home (T3.1)', function () {
    $this->getJson('/pagina')->assertUnauthorized();
    $this->getJson('/api/dati')->assertUnauthorized();
    $this->get('/api/dati')->assertUnauthorized();

    ingressoChiesto($this->get('/pagina', ['X-Inertia' => 'true'])->assertStatus(409));
});

it('un\'API del gruppo api vuole la sessione di Laravel: con StartSession (statefulApi di Sanctum) risponde a chi è entrato, senza resta 401 (T3.1, review A2)', function () {
    Route::middleware(['api', StartSession::class])->prefix('api')
        ->get('con-sessione', fn (Contesto $contesto) => ['workspace' => $contesto->workspaceId()]);

    $this->getJson('/api/con-sessione')->assertUnauthorized();
    entra()->assertRedirect('/pagina');

    $this->getJson('/api/con-sessione')->assertOk()->assertExactJson(['workspace' => 7]);
    $this->getJson('/api/dati')->assertUnauthorized();
});

it('al ritorno il modulo scambia il codice, verifica l\'id_token e apre la sessione: persona, workspace e ruolo, poi la pagina chiesta (T3.2)', function () {
    entra(pagina: '/note?ordine=1')->assertRedirect('/note?ordine=1');

    expect(Persona::query()->find(42)?->only('email', 'name', 'locale'))
        ->toBe(['email' => 'giulia@esempio.it', 'name' => 'Giulia Rossi', 'locale' => 'it']);
    $this->get('/pagina')->assertOk()
        ->assertExactJson(['persona' => 42, 'workspace' => 7, 'nome' => 'Ventiquattro', 'ruolo' => 'admin']);
    expect(scambi())->toBe(1);
});

it('l\'id_token non apre la sessione se è di un altro client, con un altro nonce, scaduto, firmato da un\'altra chiave, di un altro emittente o con un sub che non è un id (T3.2, prova 1)', function (Closure $token) {
    $chiesto = ingressoChiesto($this->get('/pagina'));
    zrHomeFinto($chiesto, $token($chiesto['nonce']));

    ritorno($chiesto)->assertForbidden();

    expect(session()->has('zr-auth.sessione'))->toBeFalse()
        ->and(Persona::query()->count())->toBe(0);
    ingressoChiesto($this->get('/pagina'));
})->with([
    'un altro client' => [fn (string $nonce) => idToken(claims($nonce, ['aud' => 'un-altro-modulo']))],
    'un altro nonce' => [fn (string $nonce) => idToken(claims('un-altro-nonce'))],
    'scaduto' => [fn (string $nonce) => idToken(claims($nonce, [
        'iat' => now()->subHours(2)->getTimestamp(), 'exp' => now()->subMinutes(5)->getTimestamp(),
    ]))],
    'senza la scadenza' => [fn (string $nonce) => idToken(claims($nonce, ['exp' => null]))],
    'un\'altra chiave' => [fn (string $nonce) => idToken(claims($nonce), 'altra')],
    'un altro emittente' => [fn (string $nonce) => idToken(claims($nonce, ['iss' => 'https://altro.example']))],
    'un sub zero' => [fn (string $nonce) => idToken(claims($nonce, ['sub' => '0']))],
    'un sub con gli zeri davanti' => [fn (string $nonce) => idToken(claims($nonce, ['sub' => '007']))],
]);

it('se zr-home non risponde, allo scambio del codice o col JWKS, il ritorno è 403 e non un errore del server, e il log lo dice (review A6)', function (string $percorso) {
    $avvisi = [];
    Log::listen(function (MessageLogged $messaggio) use (&$avvisi) {
        if ($messaggio->level === 'warning' && str_starts_with($messaggio->message, 'zr-auth:')) {
            $avvisi[] = $messaggio->message;
        }
    });
    zrHomeCon($percorso, Http::failedConnection());

    entra()->assertForbidden();

    expect(session()->has('zr-auth.sessione'))->toBeFalse()
        ->and(Persona::query()->count())->toBe(0)
        ->and($avvisi)->toHaveCount(1)
        ->and($avvisi[0])->toContain('zr-home non risponde');
})->with(['lo scambio del codice' => '/oauth/token', 'il JWKS' => '/oauth/jwks']);

it('lo stesso ritorno usato due volte, o con uno state sconosciuto, risponde 403 e non apre la sessione (T3.3)', function () {
    $chiesto = ingressoChiesto($this->get('/pagina'));
    zrHomeFinto($chiesto, idToken(claims($chiesto['nonce'])));
    ritorno($chiesto)->assertRedirect('/pagina');
    session()->forget('zr-auth.sessione');

    ritorno($chiesto)->assertForbidden();
    expect(session()->has('zr-auth.sessione'))->toBeFalse();

    $this->get('/auth/callback?'.http_build_query(['code' => CODICE, 'state' => str_repeat('x', 40)]))->assertForbidden();
    expect(session()->has('zr-auth.sessione'))->toBeFalse()
        ->and(scambi())->toBe(1);
});

it('dopo 12 ore la sessione non vale più: l\'ingresso si rifà in silenzio, e se zr-home chiede l\'accesso riparte con l\'accesso (T3.4)', function () {
    $this->freezeSecond();
    $inizio = now();
    entra()->assertRedirect('/pagina');

    $this->travelTo($inizio->copy()->addHours(12)->subMinute());
    $this->get('/pagina')->assertOk();

    $this->travelTo($inizio->copy()->addHours(12)->addMinute());
    $silenzioso = ingressoChiesto($this->get('/pagina'));
    expect($silenzioso['prompt'] ?? null)->toBe('none')
        ->and($silenzioso['workspace'] ?? null)->toBe('7');

    $conAccesso = ingressoChiesto($this->get('/auth/callback?'.http_build_query([
        'error' => 'login_required', 'state' => $silenzioso['state'],
    ])));
    expect(array_key_exists('prompt', $conAccesso))->toBeFalse()
        ->and($conAccesso['workspace'] ?? null)->toBe('7')
        ->and($conAccesso['state'])->not->toBe($silenzioso['state']);
});

it('dal silenzioso l\'ingresso riparte con l\'accesso per ogni errore con cui zr-home vuole la persona davanti (T3.4, review A7)', function (string $errore) {
    entra()->assertRedirect('/pagina');
    $silenzioso = ingressoChiesto($this->get('/pagina?workspace=8'));
    expect($silenzioso['prompt'] ?? null)->toBe('none');

    $conAccesso = ingressoChiesto($this->get('/auth/callback?'.http_build_query(['error' => $errore, 'state' => $silenzioso['state']])));
    expect(array_key_exists('prompt', $conAccesso))->toBeFalse()
        ->and($conAccesso['workspace'] ?? null)->toBe('8');
})->with(['login_required', 'interaction_required', 'consent_required', 'account_selection_required']);

it('un altro errore dal silenzioso, o un errore dell\'ingresso con l\'accesso, risponde 403 e non riparte (T3.4, review A7)', function () {
    entra()->assertRedirect('/pagina');
    $silenzioso = ingressoChiesto($this->get('/pagina?workspace=8'));
    $this->get('/auth/callback?'.http_build_query(['error' => 'access_denied', 'state' => $silenzioso['state']]))->assertForbidden();

    $conAccesso = ingressoChiesto($this->get('/pagina'));
    expect(array_key_exists('prompt', $conAccesso))->toBeFalse();
    $this->get('/auth/callback?'.http_build_query(['error' => 'interaction_required', 'state' => $conAccesso['state']]))->assertForbidden();
    expect(session()->has('zr-auth.sessione'))->toBeFalse();
});

it('un id_token di un workspace diverso da quello che l\'ingresso chiedeva non apre la sessione (T3.5, review A8)', function () {
    $chiesto = ingressoChiesto($this->get('/pagina?workspace=8'));
    zrHomeFinto($chiesto, idToken(claims($chiesto['nonce'])));

    ritorno($chiesto)->assertForbidden();
    expect(session()->has('zr-auth.sessione'))->toBeFalse();
});

it('la persona che un\'altra richiesta registra mentre questa entra non fa fallire l\'ingresso: si aggiorna (review A5)', function () {
    // L'altra richiesta scrive la persona fra la lettura di questa e la sua scrittura.
    $scritta = false;
    DB::listen(function (QueryExecuted $query) use (&$scritta) {
        if (! $scritta && str_contains($query->sql, 'from "zr_persone"')) {
            $scritta = true;
            DB::table('zr_persone')->insert(['id' => 42, 'email' => 'prima@esempio.it', 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    entra()->assertRedirect('/pagina');

    expect($scritta)->toBeTrue()
        ->and(Persona::query()->count())->toBe(1)
        ->and(Persona::query()->find(42)?->only('email', 'name'))->toBe(['email' => 'giulia@esempio.it', 'name' => 'Giulia Rossi']);
});

it('il ritorno ha un freno per indirizzo: 30 al minuto, poi 429 (review R-SA1)', function () {
    foreach (range(1, 30) as $volta) {
        $this->get('/auth/callback?state=sconosciuto')->assertForbidden();
    }

    $this->get('/auth/callback?state=sconosciuto')->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->get('/auth/callback?state=sconosciuto')->assertForbidden();
});

it('il freno del ritorno conta un IPv6 per il suo /64: un indirizzo nuovo dello stesso /64 non lo azzera (review N4, R-SA1)', function () {
    foreach (range(1, 30) as $volta) {
        $this->withServerVariables(['REMOTE_ADDR' => "2001:db8:1:2::{$volta}"])->get('/auth/callback?state=sconosciuto')->assertForbidden();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::ff'])->get('/auth/callback?state=sconosciuto')->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:3::1'])->get('/auth/callback?state=sconosciuto')->assertForbidden();
});

it('un indirizzo con un altro workspace rifà l\'ingresso in silenzio per quello: la sessione è di un workspace solo (T3.5)', function () {
    entra()->assertRedirect('/pagina');
    $this->get('/pagina?workspace=7')->assertOk()->assertJsonPath('workspace', 7);

    $chiesto = ingressoChiesto($this->get('/pagina?workspace=8'));
    expect($chiesto['prompt'] ?? null)->toBe('none')
        ->and($chiesto['workspace'] ?? null)->toBe('8');

    zrHomeFinto($chiesto, idToken(claims($chiesto['nonce'], [
        'workspace' => ['id' => 8, 'name' => 'Ottavo'], 'role' => 'member',
    ])));
    ritorno($chiesto)->assertRedirect('/pagina?workspace=8');

    $this->get('/pagina')->assertOk()
        ->assertExactJson(['persona' => 42, 'workspace' => 8, 'nome' => 'Ottavo', 'ruolo' => 'member']);
    expect(session('zr-auth.sessione.workspace'))->toBe(['id' => 8, 'nome' => 'Ottavo']);
    ingressoChiesto($this->get('/pagina?workspace=7'));
});
