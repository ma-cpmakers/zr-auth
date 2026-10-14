<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Manager;
use Zeiras\Auth\Http\Middleware\ConGettone;
use Zeiras\Auth\Sessione;

// #2044 (CS.7): una risposta lenta partita prima di un cambio di persona nello stesso browser non fa rivivere la sessione di prima.
// Laravel carica la sessione all'inizio di una richiesta e la riscrive, col suo cookie, a ogni risposta: se nel frattempo un'altra
// richiesta dello stesso browser ha fatto entrare un'altra persona (`Sessione::apri`/`entra` distruggono l'id di prima), la risposta
// tardiva ricrea la sessione di Ada e rimette nel browser il suo cookie. `->bloccaSessione()` lo evita solo sulle rotte che lo
// dichiarano; una pagina qualunque (la richiesta rimandata di Inertia) non lo ha. I test usano il driver `file`: ogni richiesta ha il
// suo store sullo stesso disco, come due processi PHP.

beforeEach(function () {
    $this->cartellaSessioni = sys_get_temp_dir().'/zr-auth-sessioni-'.bin2hex(random_bytes(6));
    mkdir($this->cartellaSessioni);
    config(['session.driver' => 'file', 'session.files' => $this->cartellaSessioni]);
    app('session')->forgetDrivers();

    Route::middleware('web')->get('apri-sessione', fn () => ['id' => session()->getId()])->withoutMiddleware(ConGettone::class);
    // Una persona entra: apre la sessione col suo accesso (anche sopra la sessione di un'altra, nello stesso browser).
    Route::middleware('web')->post('entra-persona', function (Request $richiesta) {
        Sessione::apri(['id' => (string) $richiesta->input('chi'), 'gettone' => accesso()['gettone']]);

        return ['id' => session()->getId()];
    })->withoutMiddleware(ConGettone::class);
    // La stessa persona entra in un workspace (Sessione::entra).
    Route::middleware('web')->post('entra-workspace', function () {
        Sessione::entra(gettoneDelWorkspace());

        return ['id' => session()->getId()];
    })->withoutMiddleware(ConGettone::class);
    Route::middleware('web')->post('esci-persona', function () {
        Sessione::chiudi();

        return ['ok' => true];
    })->withoutMiddleware(ConGettone::class);
});

afterEach(function () {
    foreach (glob($this->cartellaSessioni.'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($this->cartellaSessioni);
});

/** Il cookie di sessione come lo rimanda il browser (cifrato). */
function cookieDellaSessione($risposta): ?string
{
    return $risposta->getCookie(config('session.cookie'), false)?->getValue();
}

/**
 * Ada è entrata: la sua sessione (id e cookie) c'è sul disco.
 *
 * @return array{0: string, 1: string}
 */
function adaEntra(): array
{
    $risposta = test()->post('/entra-persona', ['chi' => 'accesso-di-ada']);

    return [$risposta->json('id'), cookieDellaSessione($risposta)];
}

/**
 * Registra la rotta `lenta`: mentre la richiesta è in volo un'altra richiesta dello stesso browser (col cookie di Ada) fa entrare
 * Grace. Ha il suo store, come un altro processo; quello della richiesta lenta resta quello che è. `$poi` è ciò che la richiesta lenta
 * fa dopo (la sua risposta, o un ingresso suo).
 *
 * @param  array{id: ?string, cookie: ?string}  $grace  si riempie con l'id e il cookie di Grace
 */
function registraLaRichiestaLenta(string &$cookieAda, array &$grace, ?Closure $poi = null): void
{
    Route::middleware('web')->get('lenta', function () use (&$cookieAda, &$grace, $poi) {
        $gestore = app('session');
        $drivers = new ReflectionProperty(Manager::class, 'drivers');
        $salvati = $drivers->getValue($gestore);
        $gestore->forgetDrivers();

        $risposta = app()->handle(Request::create('/entra-persona', 'POST', ['chi' => 'accesso-di-grace'], [config('session.cookie') => $cookieAda]));
        foreach ($risposta->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                $grace['cookie'] = $cookie->getValue();
            }
        }
        $grace['id'] = json_decode((string) $risposta->getContent(), true)['id'];

        $drivers->setValue($gestore, $salvati);

        return $poi ? $poi() : ['lenta' => true];
    })->withoutMiddleware(ConGettone::class);
}

it('una risposta lenta di Ada, finita dopo l\'ingresso di Grace, non ricrea la sessione di Ada né rimanda i suoi cookie: Grace resta Grace (CS.7)', function (array $intestazioni) {
    [$idAda, $cookieAda] = adaEntra();
    $grace = ['id' => null, 'cookie' => null];
    registraLaRichiestaLenta($cookieAda, $grace);

    $tardiva = $this->withUnencryptedCookie(config('session.cookie'), $cookieAda)->get('/lenta', $intestazioni);

    expect($tardiva->getStatusCode())->toBe(200)
        ->and($grace['id'])->not->toBe($idAda)
        // Il browser non riceve di nuovo né il cookie di Ada né un gettone CSRF di Ada: gli restano quelli di Grace.
        ->and(cookieDellaSessione($tardiva))->toBeNull()
        ->and($tardiva->getCookie('XSRF-TOKEN', false))->toBeNull()
        // La sessione di Ada non è tornata sul disco; quella di Grace c'è e col suo cookie il browser è ancora Grace.
        ->and(file_exists($this->cartellaSessioni.'/'.$idAda))->toBeFalse()
        ->and(file_exists($this->cartellaSessioni.'/'.$grace['id']))->toBeTrue()
        ->and($this->withUnencryptedCookie(config('session.cookie'), $grace['cookie'])->get('/apri-sessione')->json('id'))->toBe($grace['id']);
})->with([
    'pagina normale' => [[]],
    // Una visita di Inertia (anche una richiesta rimandata): nessuna rotta dichiara `->bloccaSessione()`, la chiusura vale per tutte.
    'visita Inertia' => [['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => 'abc']],
    'richiesta rimandata di Inertia' => [['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Pagina', 'X-Inertia-Partial-Data' => 'cornice', 'X-Requested-With' => 'XMLHttpRequest']],
]);

it('una richiesta lenta che cambia la sessione da sé (entra nel workspace) dopo l\'ingresso di Grace non porta al browser la sessione di Ada (CS.7)', function () {
    [$idAda, $cookieAda] = adaEntra();
    $grace = ['id' => null, 'cookie' => null];
    // La richiesta lenta di Ada, come `POST notifiche/letture` di zr-home, finisce con un ingresso suo nel workspace.
    registraLaRichiestaLenta($cookieAda, $grace, function () {
        Sessione::entra(gettoneDelWorkspace());

        return ['id' => session()->getId()];
    });

    $tardiva = $this->withUnencryptedCookie(config('session.cookie'), $cookieAda)->get('/lenta');
    $idNuovoDiAda = $tardiva->json('id');

    expect($idNuovoDiAda)->not->toBe($idAda)->not->toBe($grace['id'])
        ->and(cookieDellaSessione($tardiva))->toBeNull()
        ->and(file_exists($this->cartellaSessioni.'/'.$idNuovoDiAda))->toBeFalse()
        ->and(file_exists($this->cartellaSessioni.'/'.$idAda))->toBeFalse()
        ->and(file_exists($this->cartellaSessioni.'/'.$grace['id']))->toBeTrue();
});

it('l\'ingresso di una persona nel workspace tiene il suo cookie e la sua sessione: la chiusura riguarda l\'id di prima (CS.7)', function () {
    [$idAda, $cookieAda] = adaEntra();

    $risposta = $this->withUnencryptedCookie(config('session.cookie'), $cookieAda)->post('/entra-workspace');
    $nuovo = $risposta->json('id');

    expect($nuovo)->not->toBe($idAda)
        ->and(cookieDellaSessione($risposta))->not->toBeNull()
        ->and(file_exists($this->cartellaSessioni.'/'.$nuovo))->toBeTrue()
        ->and(file_exists($this->cartellaSessioni.'/'.$idAda))->toBeFalse()
        ->and(Sessione::eChiusa($idAda))->toBeTrue()
        ->and(Sessione::eChiusa($nuovo))->toBeFalse();
});

it('anche l\'uscita chiude l\'id di prima; una sessione anonima non lascia nessun segno (CS.7)', function () {
    // Ogni richiesta col suo store, come processi diversi: lo store in memoria di una richiesta non porta i dati di Ada nella sessione anonima.
    [$idAda, $cookieAda] = adaEntra();
    app('session')->forgetDrivers();
    $anonima = $this->get('/apri-sessione');
    $idAnonimo = $anonima->json('id');

    app('session')->forgetDrivers();
    $this->withUnencryptedCookie(config('session.cookie'), $cookieAda)->post('/esci-persona')->assertOk();
    app('session')->forgetDrivers();
    $this->withUnencryptedCookie(config('session.cookie'), cookieDellaSessione($anonima))->post('/entra-persona', ['chi' => 'accesso-di-bruno']);

    expect(Sessione::eChiusa($idAda))->toBeTrue()
        ->and(Sessione::eChiusa($idAnonimo))->toBeFalse();
});

it('il segno dura quanto una richiesta può restare in volo (zr-auth.sessione_chiusa_secondi) e poi scade da sé (CS.7)', function () {
    [$idAda, $cookieAda] = adaEntra();
    $this->withUnencryptedCookie(config('session.cookie'), $cookieAda)->post('/entra-workspace');

    expect(Sessione::eChiusa($idAda))->toBeTrue();

    $this->travel((int) config('zr-auth.sessione_chiusa_secondi') + 1)->seconds();

    expect(Sessione::eChiusa($idAda))->toBeFalse()
        ->and(config('zr-auth.sessione_chiusa_secondi'))->toBeLessThanOrEqual(600);
});

it('se la cache dei segni non risponde in lettura, una risposta con sessione è 503 e senza cookie: il lato prudente è rifiutare (CS.7)', function () {
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

it('se la cache dei segni non risponde in scrittura, o rifiuta di scrivere, l\'ingresso fallisce e la sessione resta com\'era (CS.7)', function (string $guasto) {
    [$idAda, $cookieAda] = adaEntra();
    Cache::extend('guasta-in-scrittura', fn () => Cache::repository(match ($guasto) {
        'lancia' => new class extends ArrayStore
        {
            public function put($key, $value, $seconds)
            {
                throw new RuntimeException('la cache non risponde');
            }
        },
        // Come phpredis davanti a un errore di Redis (memoria piena, sola lettura): torna falso e non lancia.
        'falso' => new class extends ArrayStore
        {
            public function put($key, $value, $seconds)
            {
                return false;
            }
        },
    }));
    config(['cache.stores.guasta-in-scrittura' => ['driver' => 'guasta-in-scrittura'], 'session.block_store' => 'guasta-in-scrittura']);
    $this->withoutExceptionHandling();

    expect(fn () => $this->withUnencryptedCookie(config('session.cookie'), $cookieAda)->post('/entra-workspace'))->toThrow(RuntimeException::class)
        ->and(file_exists($this->cartellaSessioni.'/'.$idAda))->toBeTrue();
})->with(['la scrittura lancia' => ['lancia'], 'la scrittura torna falso' => ['falso']]);
