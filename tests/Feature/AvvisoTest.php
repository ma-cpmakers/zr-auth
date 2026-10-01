<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Zeiras\Auth\Ingresso;
use Zeiras\Auth\Revoca;
use Zeiras\Auth\Testing\Rotte;

/*
 * Gli avvisi di zr-home (voce #979, T5.1-T5.5): un logout_token valido chiude, alla loro richiesta successiva, le sessioni
 * del modulo aperte prima che arrivasse — quella di una sessione di zr-home (`sid`), quelle di una persona in un workspace
 * (`sub` e `workspace`), quelle di un workspace (`workspace`). Un avviso non valido non chiude niente.
 */

/** Una sessione del modulo, aperta col giro intero da un ingresso coi claim `$altri`: torna ciò che il browser porta. */
function sessioneAperta(array $altri): array
{
    session()->forget(Ingresso::SESSIONE);
    entra($altri)->assertRedirect('/pagina');

    return session(Ingresso::SESSIONE);
}

/** La richiesta successiva del browser che ha quella sessione: una pagina, o una richiesta JSON. */
function richiestaCon(array $sessione, bool $json = false): TestResponse
{
    $test = test()->withSession([Ingresso::SESSIONE => $sessione]);

    return $json ? $test->getJson('/pagina') : $test->get('/pagina');
}

it('un avviso col sid chiude alla richiesta successiva la sessione di quel sid e l\'ingresso riparte; le altre restano (T5.1)', function () {
    $esce = sessioneAperta(['sid' => 'sid-che-esce']);
    $resta = sessioneAperta(['sid' => 'sid-di-un-altro-browser']);

    avvisa(['sid' => 'sid-che-esce', 'motivo' => 'uscita'])->assertOk();

    expect(Revoca::query()->sole()->only('sid', 'sub', 'workspace', 'motivo'))
        ->toBe(['sid' => 'sid-che-esce', 'sub' => null, 'workspace' => null, 'motivo' => 'uscita']);
    $ingresso = ingressoChiesto(richiestaCon($esce));
    expect($ingresso['prompt'] ?? null)->toBe('none')
        ->and($ingresso['workspace'] ?? null)->toBe('7')
        ->and(session()->has(Ingresso::SESSIONE))->toBeFalse();
    richiestaCon($esce, json: true)->assertUnauthorized();
    richiestaCon($resta)->assertOk()
        ->assertExactJson(['persona' => 42, 'workspace' => 7, 'nome' => 'Ventiquattro', 'ruolo' => 'admin']);
});

it('un avviso con sub e workspace chiude le sessioni della persona in quel workspace, non in un altro né quelle dei colleghi (T5.2)', function () {
    $qui = sessioneAperta(['sid' => 'sid-1']);
    $altrove = sessioneAperta(['sid' => 'sid-2', 'workspace' => ['id' => 8, 'name' => 'Ottavo']]);
    $collega = sessioneAperta(['sid' => 'sid-3', 'sub' => '43', 'email' => 'marco@esempio.it']);

    avvisa(['sub' => '42', 'workspace' => 7, 'motivo' => 'membro_rimosso'])->assertOk();

    ingressoChiesto(richiestaCon($qui));
    richiestaCon($altrove)->assertOk()->assertJson(['persona' => 42, 'workspace' => 8]);
    richiestaCon($collega)->assertOk()->assertJson(['persona' => 43, 'workspace' => 7]);
});

it('un avviso col solo workspace chiude tutte le sessioni di quel workspace, e solo quelle (T5.2)', function () {
    $qui = sessioneAperta(['sid' => 'sid-1']);
    $altrove = sessioneAperta(['sid' => 'sid-2', 'workspace' => ['id' => 8, 'name' => 'Ottavo']]);
    $collega = sessioneAperta(['sid' => 'sid-3', 'sub' => '43', 'email' => 'marco@esempio.it']);

    avvisa(['workspace' => 7, 'motivo' => 'app_disattivata'])->assertOk();

    ingressoChiesto(richiestaCon($qui));
    ingressoChiesto(richiestaCon($collega));
    richiestaCon($altrove)->assertOk()->assertJson(['persona' => 42, 'workspace' => 8]);
});

it('un avviso non valido risponde 400 e non chiude niente (T5.3)', function (Closure $token) {
    $sessione = sessioneAperta(['sid' => 'sid-che-esce']);

    avvisaCon($token())->assertStatus(400);

    expect(Revoca::query()->count())->toBe(0);
    richiestaCon($sessione)->assertOk()->assertJsonPath('persona', 42);
})->with([
    'firmato da un\'altra chiave' => [fn () => logoutToken(['sid' => 'sid-che-esce'], 'altra')],
    'per un altro client' => [fn () => logoutToken(['sid' => 'sid-che-esce', 'aud' => 'un-altro-modulo'])],
    'da un altro emittente' => [fn () => logoutToken(['sid' => 'sid-che-esce', 'iss' => 'https://altro.example'])],
    'firmato più di 5 minuti fa' => [fn () => logoutToken([
        'sid' => 'sid-che-esce', 'iat' => now()->subMinutes(5)->subSecond()->getTimestamp(),
    ])],
    'senza l\'evento del back-channel' => [fn () => logoutToken(['sid' => 'sid-che-esce', 'events' => null])],
    'con un altro evento' => [fn () => logoutToken([
        'sid' => 'sid-che-esce', 'events' => ['http://schemas.openid.net/event/altro' => new stdClass],
    ])],
    'col nonce' => [fn () => logoutToken(['sid' => 'sid-che-esce', 'nonce' => 'un-nonce'])],
    'senza sid, sub e workspace' => [fn () => logoutToken(['motivo' => 'uscita'])],
    'col sub senza il workspace' => [fn () => logoutToken(['sub' => '42', 'motivo' => 'membro_rimosso'])],
    'con un sid vuoto e il workspace' => [fn () => logoutToken(['sid' => '', 'workspace' => 7])],
    'scaduto' => [fn () => logoutToken([
        'sid' => 'sid-che-esce', 'iat' => now()->subMinutes(4)->getTimestamp(), 'exp' => now()->subMinutes(2)->getTimestamp(),
    ])],
    'con un typ che non è logout+jwt' => [fn () => logoutToken(['sid' => 'sid-che-esce'], tipo: 'JWT')],
    'l\'id_token di un ingresso' => [fn () => idToken(claims('un-nonce', ['sid' => 'sid-che-esce']))],
    'non un token' => [fn () => 'non-un-token'],
]);

it('il JWKS di zr-home resta in cache 10 minuti: due avvisi, una richiesta sola (review A3)', function () {
    avvisa(['sid' => 'sid-1'])->assertOk();
    avvisa(['sid' => 'sid-2'])->assertOk();
    expect(jwksChiesti())->toBe(1);

    $this->travel(11)->minutes();
    avvisa(['sid' => 'sid-3'])->assertOk();
    expect(jwksChiesti())->toBe(2);
});

it('zr-home che non risponde o un JWKS senza chiavi non restano in cache: l\'avviso è 400, mai un errore del server, e il successivo richiede il JWKS (review A3, A6)', function () {
    zrHomeCon('/oauth/jwks', Http::sequence()->pushFailedConnection()->push(['keys' => []])->push(jwks()));

    avvisa(['sid' => 'sid-1'])->assertStatus(400);
    avvisa(['sid' => 'sid-2'])->assertStatus(400);
    avvisa(['sid' => 'sid-3'])->assertOk();
    avvisa(['sid' => 'sid-4'])->assertOk();

    expect(jwksChiesti())->toBe(3)
        ->and(Revoca::query()->orderBy('id')->pluck('sid')->all())->toBe(['sid-3', 'sid-4']);
});

it('un avviso firmato 5 minuti fa vale ancora: si rifiutano solo i più vecchi (T5.3)', function () {
    $this->freezeSecond();
    $sessione = sessioneAperta(['sid' => 'sid-che-esce']);

    avvisa(['sid' => 'sid-che-esce', 'motivo' => 'uscita', 'iat' => now()->subMinutes(5)->getTimestamp()])->assertOk();

    ingressoChiesto(richiestaCon($sessione));
});

it('una sessione aperta dopo l\'avviso vale: chi rientra, anche nello stesso secondo, non viene buttato fuori (T5.4)', function (array $avviso) {
    $this->freezeSecond();
    sessioneAperta(['sid' => 'sid-che-esce']);
    avvisa($avviso)->assertOk();

    // Rientra con lo stesso sid, la stessa persona e lo stesso workspace: solo l'ordine d'arrivo lo distingue.
    $chiesto = ingressoChiesto($this->get('/pagina'));
    zrHomeFinto($chiesto, idToken(claims($chiesto['nonce'], ['sid' => 'sid-che-esce', 'role' => 'member'])));
    ritorno($chiesto)->assertRedirect('/pagina');

    $this->get('/pagina')->assertOk()->assertJson(['persona' => 42, 'workspace' => 7, 'ruolo' => 'member']);
    $this->travel(11)->hours();
    $this->get('/pagina')->assertOk();
})->with([
    'col sid' => [['sid' => 'sid-che-esce', 'motivo' => 'uscita']],
    'con sub e workspace' => [['sub' => '42', 'workspace' => 7, 'motivo' => 'ruolo_cambiato']],
    'col workspace' => [['workspace' => 7, 'motivo' => 'app_disattivata']],
]);

it('l\'avviso non vuole sessione né CSRF: è una delle tre eccezioni, e zr-home, senza cookie, riceve 200 (T5.5)', function () {
    $rotta = Route::getRoutes()->match(Request::create('/auth/avviso', 'POST'));

    expect($rotta->getName())->toBe('zr-auth.avviso')
        ->and($rotta->methods())->toBe(['POST'])
        ->and(Route::gatherRouteMiddleware($rotta))->toBe([])
        ->and(Rotte::ECCEZIONI)->toContain('POST auth/avviso')
        ->and(Rotte::senzaSessione())->toBe([]);

    $sessione = sessioneAperta(['sid' => 'sid-che-esce']);
    $risposta = avvisa(['sid' => 'sid-che-esce', 'motivo' => 'uscita'])->assertOk();

    expect($risposta->headers->get('Cache-Control'))->toContain('no-store');
    ingressoChiesto(richiestaCon($sessione));
});

it('le revoche più vecchie della durata di una sessione si tolgono all\'arrivo di un avviso nuovo, le altre restano', function () {
    $this->freezeSecond();
    avvisa(['sid' => 'sid-1', 'motivo' => 'uscita'])->assertOk();
    $this->travel(11)->hours();
    avvisa(['sid' => 'sid-2', 'motivo' => 'uscita'])->assertOk();

    expect(Revoca::query()->orderBy('id')->pluck('sid')->all())->toBe(['sid-1', 'sid-2']);

    $this->travel(2)->hours();
    avvisa(['sid' => 'sid-3', 'motivo' => 'uscita'])->assertOk();

    expect(Revoca::query()->orderBy('id')->pluck('sid')->all())->toBe(['sid-2', 'sid-3']);
});
