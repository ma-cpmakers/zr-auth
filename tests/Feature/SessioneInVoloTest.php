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
    // Come `entra-persona`, ma con l'email scelta dal test (#2157: la riga di log dice se la persona è un'identità di prova).
    Route::middleware('web')->post('entra-persona-email', function (Request $richiesta) {
        $accesso = accesso();
        $accesso['gettone']['utente']['email'] = (string) $richiesta->input('email');
        Sessione::apri($accesso);

        return ['id' => session()->getId()];
    })->withoutMiddleware(ConGettone::class);
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
    Route::middleware('web')->get('lenta', function (Request $lenta) use (&$cookieAda, &$grace, $poi) {
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
        // La richiesta annidata ha preso il posto di `request()`: la lenta riprende la sua, come in un processo a parte (TARDIVA, MIE).
        app()->instance('request', $lenta);

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
        ->and(Sessione::eChiusa($nuovo))->toBeFalse()
        // Il segno non porta l'id in chiaro: la chiave ha il suo sha256.
        ->and(Cache::store(config('session.block_store'))->has('zr-auth:sessione-chiusa:'.hash('sha256', $idAda)))->toBeTrue()
        ->and(Cache::store(config('session.block_store'))->has('zr-auth:sessione-chiusa:'.$idAda))->toBeFalse();
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


/**
 * La risposta tardiva di una persona con quell'email (#2157): il contesto della riga di log «scartata la risposta tardiva».
 *
 * @return array<string, mixed>
 */
function contestoDellaRigaScartata(string $email): array
{
    $risposta = test()->post('/entra-persona-email', ['email' => $email]);
    $cookie = cookieDellaSessione($risposta);
    $grace = ['id' => null, 'cookie' => null];
    registraLaRichiestaLenta($cookie, $grace);
    $gestore = Illuminate\Support\Facades\Log::channel('prova')->getLogger()->getHandlers()[0];
    $gestore->clear();

    test()->withUnencryptedCookie(config('session.cookie'), $cookie)->get('/lenta')->assertOk();

    $righe = array_values(array_filter($gestore->getRecords(), fn ($riga) => str_contains($riga->message, 'scartata la risposta tardiva')));

    expect($righe)->toHaveCount(1);

    return $righe[0]->context;
}

// LP.1, LP.2 (#2157)
it('la riga della risposta scartata dice `prova: true` per uat+ e uat- (anche in maiuscolo), `false` per una persona vera, e non porta altro', function (string $email, bool $prova) {
    $contesto = contestoDellaRigaScartata($email);

    expect($contesto)->toBe(['metodo' => 'GET', 'rotta' => 'lenta', 'prova' => $prova])
        ->and(json_encode($contesto))->not->toContain($email)->not->toContain('@');
})->with([
    'uat+' => ['uat+zr-backoffice-37-1@castroandpartners.com', true],
    'uat-' => ['uat-board-3@lucianocastro.com', true],
    'maiuscolo' => ['UAT+Board-2@Example.com', true],
    'persona vera' => ['anna@example.com', false],
    'uat in mezzo' => ['anna.uat+x@example.com', false],
]);

it('i prefissi di una persona di prova stanno in `zr-auth.prova.email_prefissi`', function () {
    config(['zr-auth.prova.email_prefissi' => ['test.']]);

    expect(contestoDellaRigaScartata('test.anna@example.com')['prova'])->toBeTrue();
});

it('la persona è quella con cui la richiesta è partita: una lenta che chiude la sessione, o ne cambia la persona, non cambia `prova` (#2157)', function (string $come, bool $atteso) {
    $risposta = test()->post('/entra-persona-email', ['email' => 'uat+ada@example.com']);
    $cookie = cookieDellaSessione($risposta);
    $grace = ['id' => null, 'cookie' => null];
    registraLaRichiestaLenta($cookie, $grace, function () use ($come) {
        if ($come === 'chiude') {
            Sessione::chiudi();
        }

        return ['lenta' => true];
    });
    $gestore = Illuminate\Support\Facades\Log::channel('prova')->getLogger()->getHandlers()[0];
    $gestore->clear();

    test()->withUnencryptedCookie(config('session.cookie'), $cookie)->get('/lenta')->assertOk();

    $righe = array_values(array_filter($gestore->getRecords(), fn ($riga) => str_contains($riga->message, 'scartata la risposta tardiva')));
    expect($righe)->toHaveCount(1)->and($righe[0]->context['prova'])->toBe($atteso);
})->with(['la lenta chiude la sessione' => ['chiude', true], 'la lenta non fa altro' => ['niente', true]]);

/**
 * La riga della risposta scartata di una richiesta lenta partita dalla persona `$iniziale` che, a fine corsa, fa entrare `$entra` (se c'è):
 * come un POST d'ingresso, dove la sessione di partenza è un'altra e la persona che conta è quella che entra (#2241).
 */
function contestoDiUnIngressoTardivo(string $iniziale, ?string $entra): array
{
    $cookie = cookieDellaSessione(test()->post('/entra-persona-email', ['email' => $iniziale]));
    $grace = ['id' => null, 'cookie' => null];
    registraLaRichiestaLenta($cookie, $grace, function () use ($entra) {
        if ($entra !== null) {
            $accesso = accesso();
            $accesso['gettone']['utente']['email'] = $entra;
            Sessione::apri($accesso);
        }

        return ['lenta' => true];
    });
    $gestore = Illuminate\Support\Facades\Log::channel('prova')->getLogger()->getHandlers()[0];
    $gestore->clear();

    test()->withUnencryptedCookie(config('session.cookie'), $cookie)->get('/lenta')->assertOk();

    $righe = array_values(array_filter($gestore->getRecords(), fn ($riga) => str_contains($riga->message, 'scartata la risposta tardiva')));

    expect($righe)->toHaveCount(1);

    return $righe[0]->context;
}

// P.1 (#2241)
it('`prova` è vero se è di prova la persona che si chiude o quella che la richiesta fa entrare: basta una delle due', function (string $iniziale, ?string $entra, bool $prova) {
    expect(contestoDiUnIngressoTardivo($iniziale, $entra))->toBe(['metodo' => 'GET', 'rotta' => 'lenta', 'prova' => $prova]);
})->with([
    'entra una prova, partiva una persona vera' => ['anna@example.com', 'uat+zr-home-1@example.com', true],
    'entra una persona vera, partiva una prova' => ['uat+ada@example.com', 'anna@example.com', true],
    'entrano e partono due prove' => ['uat-a@example.com', 'uat+b@example.com', true],
    'né l\'una né l\'altra' => ['anna@example.com', 'bruno@example.com', false],
    'nessuno entra, parte una persona vera' => ['anna@example.com', null, false],
]);

// P.2 (#2241)
dataset('account condivisi di prova', [
    'nell\'elenco' => ['uat@esempio.test', true],
    'maiuscole' => ['UAT@Esempio.TEST', true],
    'voce con spazi e maiuscole' => ['prova.condivisa@esempio.test', true],
    'una parte dell\'indirizzo' => ['xuat@esempio.test', false],
    'l\'indirizzo con un suffisso' => ['uat@esempio.test.evil', false],
    'un altro dominio' => ['uat@altro.test', false],
]);

it('gli account condivisi di prova stanno in `zr-auth.prova.email`: la persona che si chiude, indirizzo intero, senza maiuscole né spazi ai bordi, mai una parte', function (string $email, bool $prova) {
    config(['zr-auth.prova.email' => ['uat@esempio.test', ' Prova.Condivisa@Esempio.test ']]);

    expect(contestoDiUnIngressoTardivo($email, null)['prova'])->toBe($prova);
})->with('account condivisi di prova');

it('gli account condivisi di prova contano anche quando sono quelli che la richiesta fa entrare', function (string $email, bool $prova) {
    config(['zr-auth.prova.email' => ['uat@esempio.test', ' Prova.Condivisa@Esempio.test ']]);

    expect(contestoDiUnIngressoTardivo('anna@example.com', $email)['prova'])->toBe($prova);
})->with('account condivisi di prova');

it('senza elenco in configurazione nessun indirizzo senza prefisso conta come prova, e il default è vuoto', function () {
    expect(config('zr-auth.prova.email'))->toBe([])
        ->and(contestoDiUnIngressoTardivo('anna@example.com', 'uat@castroandpartners.com')['prova'])->toBeFalse();
});

// LP.4
it('il README e la config dicono `prova` e i prefissi; la riga di log ha i soli tre nomi', function () {
    $readme = file_get_contents(__DIR__.'/../../README.md');
    $config = file_get_contents(__DIR__.'/../../config/zr-auth.php');

    expect($readme)->toContain('zr-auth.prova.email_prefissi')->toContain('`prova`')
        ->and($config)->toContain('email_prefissi')->toContain('uat+')->toContain('uat-')
        ->and(require __DIR__.'/../../config/zr-auth.php')->toHaveKey('prova.email_prefissi', ['uat+', 'uat-'])->toHaveKey('prova.email')
        ->and($readme)->toContain('ZR_AUTH_PROVA_EMAIL')->toContain('zr-auth.prova.email`')
        ->and($config)->toContain('ZR_AUTH_PROVA_EMAIL')->not->toContain('castroandpartners');
});
