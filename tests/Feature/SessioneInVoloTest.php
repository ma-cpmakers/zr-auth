<?php

use Illuminate\Http\Request;
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

it('una risposta lenta di Ada, finita dopo l\'ingresso di Grace, non ricrea la sessione di Ada né rimanda il suo cookie (CS.7)', function () {
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

    $tardiva = $this->call('GET', '/lenta', [], [$nome => $cookieAda]);

    expect($tardiva->getStatusCode())->toBe(200)
        ->and($idGrace)->not->toBe($idAda)
        // Il browser non riceve di nuovo il cookie di Ada: gli resta quello di Grace.
        ->and(cookieDellaSessione($tardiva))->toBeNull()
        // E la sessione di Ada non è tornata sul disco; quella di Grace c'è.
        ->and(file_exists($this->cartellaSessioni.'/'.$idAda))->toBeFalse()
        ->and(file_exists($this->cartellaSessioni.'/'.$idGrace))->toBeTrue();
});
