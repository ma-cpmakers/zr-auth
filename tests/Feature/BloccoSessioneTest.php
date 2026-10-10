<?php

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Zeiras\Auth\Http\Middleware\ConGettone;
use Zeiras\Auth\Sessione;

// #1477 (T1.1-T1.4): il blocco della sessione sulle rotte che la cambiano. Una richiesta della stessa sessione ancora in
// volo si simula tenendo il lock `session:<id>` che Laravel prende (Route::block): con un TTL breve, la seconda richiesta
// aspetta che scada; con un TTL lungo, aspetta il tetto e riceve il 503.

beforeEach(function () {
    Route::middleware('web')->get('apri-sessione', fn () => ['id' => session()->getId()])->withoutMiddleware(ConGettone::class);
    Route::middleware('web')->post('esci', function () {
        Sessione::chiudi();

        return ['ok' => true];
    })->withoutMiddleware(ConGettone::class)->bloccaSessione();
    Route::middleware('web')->post('esci-senza-blocco', function () {
        Sessione::chiudi();

        return ['ok' => true];
    })->withoutMiddleware(ConGettone::class);
});

/** Apre una sessione e ne torna l'id: il cookie delle richieste dopo. */
function sessioneAperta(): string
{
    return test()->get('/apri-sessione')->json('id');
}

/** @return array{0: float, 1: TestResponse} i secondi passati e la risposta. */
function misura(string $metodo, string $percorso, string $sessione): array
{
    $inizio = hrtime(true);
    $risposta = test()->withCookie(config('session.cookie'), $sessione)->call($metodo, $percorso);

    return [(hrtime(true) - $inizio) / 1e9, $risposta];
}

it('il ricevitore del codice prende il blocco: aspetta chi tiene la sessione, poi prosegue (T1.1)', function () {
    $sessione = sessioneAperta();
    $altra = Cache::lock('session:'.$sessione, 1);
    expect($altra->get())->toBeTrue();

    [$secondi, $risposta] = misura('GET', config('zr-auth.ricevitore'), $sessione);

    // Il blocco di un secondo scade da sé: la richiesta ha aspettato e poi ha dato la sua risposta di sempre (la pagina d'errore).
    expect($secondi)->toBeGreaterThan(0.8)->toBeLessThan(2.5)
        ->and($risposta->getStatusCode())->toBe(302);
});

it('una rotta che cambia la sessione non si sovrappone a una richiesta in volo, e a richieste finite la sessione è chiusa (T1.2)', function () {
    $sessione = sessioneAperta();
    session()->setId($sessione);
    session()->put('di prima', true);
    session()->save();
    expect(Cache::lock('session:'.$sessione, 1)->get())->toBeTrue();

    [$secondi, $risposta] = misura('POST', '/esci', $sessione);

    expect($secondi)->toBeGreaterThan(0.8)
        ->and($risposta->getStatusCode())->toBe(200)
        ->and(session()->getHandler()->read($sessione))->toBe('');
});

it('senza il blocco la stessa richiesta non aspetta: è il test che prova il blocco (T1.2, controprova)', function () {
    $sessione = sessioneAperta();
    expect(Cache::lock('session:'.$sessione, 1)->get())->toBeTrue();

    [$secondi, $risposta] = misura('POST', '/esci-senza-blocco', $sessione);

    expect($secondi)->toBeLessThan(0.5)
        ->and($risposta->getStatusCode())->toBe(200);
});

it('->bloccaSessione() mette sulla rotta 10 secondi di tenuta e 3 di attesa, e il ricevitore ce li ha (T1.3)', function () {
    $rotta = Route::getRoutes()->match(Request::create('/esci', 'POST'));
    $ricevitore = Route::getRoutes()->getByName('zr-auth.ricevitore');

    expect(Route::hasMacro('bloccaSessione'))->toBeTrue()
        ->and([$rotta->locksFor(), $rotta->waitsFor()])->toBe([10, 3])
        ->and([$ricevitore->locksFor(), $ricevitore->waitsFor()])->toBe([10, 3])
        ->and(Sessione::BLOCCO_TENUTA)->toBe(10)
        ->and(Sessione::BLOCCO_ATTESA)->toBe(3);
});

it('oltre l\'attesa risponde 503 con Retry-After, mai 500, entro il tetto e senza dire di chi è il blocco (T1.4)', function () {
    $sessione = sessioneAperta();
    $altra = Cache::lock('session:'.$sessione, 10);
    expect($altra->get())->toBeTrue();

    [$secondi, $risposta] = misura('POST', '/esci', $sessione);

    expect($risposta->getStatusCode())->toBe(503)
        ->and($risposta->headers->get('Retry-After'))->toBe('1')
        ->and($risposta->getContent())->not->toContain($sessione)
        ->and($secondi)->toBeGreaterThan(2.5)->toBeLessThan(4.5)
        // Non è andata avanti senza il blocco: la sessione non è stata chiusa.
        ->and(session()->getHandler()->read($sessione))->not->toBe('');

    $altra->release();
});

it('un timeout di un lock che non è quello della sessione non diventa un 503 di zr-auth (T1.4, revisione)', function () {
    Route::middleware('web')->get('lock-altrui', function () {
        throw new LockTimeoutException;
    })->withoutMiddleware(ConGettone::class);

    $this->get('/lock-altrui')->assertStatus(500);
});
