<?php

use Illuminate\Http\Request;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Manager;
use Zeiras\Auth\Http\Middleware\ConGettone;
use Zeiras\Auth\Sessione;

// #2044 (CS.7): una risposta lenta partita prima di un cambio di persona nello stesso browser non fa rivivere la sessione di prima.
// Laravel carica la sessione all'inizio di una richiesta e la riscrive, col suo cookie, a ogni risposta: se nel frattempo un'altra
// richiesta dello stesso browser ha fatto entrare un'altra persona (`Sessione::apri`/`entra` distruggono l'id di prima), la risposta
// tardiva ricrea la sessione di Ada e rimette nel browser il suo cookie. `->bloccaSessione()` lo evita solo sulle rotte che lo
// dichiarano; una pagina qualunque (la richiesta rimandata di Inertia) non lo ha. Il test usa il driver `file`: ogni richiesta ha il suo
// store sullo stesso disco, come due processi PHP.

beforeEach(function () {
    $this->cartellaSessioni = sys_get_temp_dir().'/zr-auth-sessioni-'.bin2hex(random_bytes(6));
    mkdir($this->cartellaSessioni);
    config(['session.driver' => 'file', 'session.files' => $this->cartellaSessioni]);
    app('session')->forgetDrivers();

    Route::middleware('web')->get('apri-sessione', fn () => ['id' => session()->getId()])->withoutMiddleware(ConGettone::class);
    Route::middleware('web')->post('entra-grace', function () {
        Sessione::apri(['id' => 'accesso-di-grace', 'gettone' => ['gettone' => GETTONE_ACCESSO, 'scade_il' => '2030-01-01T00:00:00.000Z', 'utente' => utente()]]);

        return ['id' => session()->getId()];
    })->withoutMiddleware(ConGettone::class);
});

afterEach(function () {
    foreach (glob($this->cartellaSessioni.'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($this->cartellaSessioni);
});

/** Il cookie di sessione come lo rimanda il browser (cifrato) e l'id che ha dentro. */
function cookieDellaSessione($risposta): ?string
{
    $cookie = $risposta->getCookie(config('session.cookie'), false);

    return $cookie?->getValue();
}

it('una risposta lenta di Ada, finita dopo l\'ingresso di Grace, non ricrea la sessione di Ada né rimanda il suo cookie (CS.7)', function (array $intestazioni) {
    $apertura = $this->get('/apri-sessione');
    $idAda = $apertura->json('id');
    $cookieAda = cookieDellaSessione($apertura);
    $nome = config('session.cookie');
    $cookieGrace = null;
    $idGrace = null;

    Route::middleware('web')->get('lenta', function () use (&$cookieAda, &$cookieGrace, &$idGrace) {
        // Mentre la richiesta di Ada è in volo, un'altra richiesta dello stesso browser (col cookie di Ada) fa entrare Grace: ha il suo
        // store, come un altro processo; quello di Ada resta quello che è.
        $gestore = app('session');
        $drivers = new ReflectionProperty(Manager::class, 'drivers');
        $salvati = $drivers->getValue($gestore);
        $gestore->forgetDrivers();

        $grace = app()->handle(Request::create('/entra-grace', 'POST', [], [config('session.cookie') => $cookieAda]));
        foreach ($grace->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                $cookieGrace = $cookie->getValue();
            }
        }
        $idGrace = json_decode((string) $grace->getContent(), true)['id'];

        $drivers->setValue($gestore, $salvati);

        return ['lenta' => true];
    })->withoutMiddleware(ConGettone::class);

    $tardiva = $this->withHeaders($intestazioni)->call('GET', '/lenta', [], [$nome => $cookieAda]);

    expect($tardiva->getStatusCode())->toBe(200)
        ->and($idGrace)->not->toBe($idAda)
        // Il browser non riceve di nuovo il cookie di Ada: gli resta quello di Grace.
        ->and(cookieDellaSessione($tardiva))->toBeNull()
        // E la sessione di Ada non è tornata sul disco; quella di Grace c'è.
        ->and(file_exists($this->cartellaSessioni.'/'.$idAda))->toBeFalse()
        ->and(file_exists($this->cartellaSessioni.'/'.$idGrace))->toBeTrue();
})->with([
    'pagina normale' => [[]],
    // Una visita di Inertia (anche una richiesta rimandata): nessuna rotta dichiara `->bloccaSessione()`, la chiusura vale per tutte.
    'visita Inertia' => [['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => 'abc']],
    'richiesta rimandata di Inertia' => [['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Pagina', 'X-Inertia-Partial-Data' => 'cornice', 'X-Requested-With' => 'XMLHttpRequest']],
]);

it('una richiesta normale dopo un ingresso tiene il suo cookie: la chiusura riguarda solo l\'id di prima (CS.7)', function () {
    $apertura = $this->get('/apri-sessione');
    $cookieAda = cookieDellaSessione($apertura);
    $this->call('POST', '/entra-grace', [], [config('session.cookie') => $cookieAda]);

    $nuova = $this->get('/apri-sessione');

    expect(cookieDellaSessione($nuova))->not->toBeNull();
});

it('se la cache dei segni non risponde, una risposta con sessione è 503 e senza cookie; il lato prudente è rifiutare (CS.7)', function () {
    Cache::extend('guasta', fn () => Cache::repository(new class extends ArrayStore
    {
        public function get($key)
        {
            throw new RuntimeException('la cache non risponde');
        }
    }));
    config(['cache.stores.guasta' => ['driver' => 'guasta'], 'session.block_store' => 'guasta']);

    $risposta = $this->get('/apri-sessione');

    expect($risposta->getStatusCode())->toBe(503)
        ->and($risposta->headers->get('Retry-After'))->toBe('1')
        ->and(cookieDellaSessione($risposta))->toBeNull();
});

it('se la cache dei segni non risponde in scrittura, l\'ingresso fallisce e la sessione resta com\'era (CS.7)', function () {
    $apertura = $this->get('/apri-sessione');
    $idAda = $apertura->json('id');
    Cache::extend('guasta-in-scrittura', fn () => Cache::repository(new class extends ArrayStore
    {
        public function put($key, $value, $seconds)
        {
            throw new RuntimeException('la cache non risponde');
        }
    }));
    config(['cache.stores.guasta-in-scrittura' => ['driver' => 'guasta-in-scrittura'], 'session.block_store' => 'guasta-in-scrittura']);

    $this->withoutExceptionHandling();

    expect(fn () => $this->call('POST', '/entra-grace', [], [config('session.cookie') => cookieDellaSessione($apertura)]))->toThrow(RuntimeException::class)
        ->and(file_exists($this->cartellaSessioni.'/'.$idAda))->toBeTrue();
});
