<?php

namespace Zeiras\Auth;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as Rotta;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Zeiras\Auth\Http\Controllers\EventiController;
use Zeiras\Auth\Http\Controllers\RicevitoreController;
use Zeiras\Auth\Http\Middleware\ConGettone;
use Zeiras\Auth\Http\Middleware\PrecedenteSenzaQuery;
use Zeiras\Auth\Http\Middleware\RicordaLIdDellaSessione;
use Zeiras\Auth\Http\Middleware\SessioneChiusa;
use Zeiras\Auth\Http\Middleware\SoloDallaPersona;

final class ZrAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/zr-auth.php', 'zr-auth');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/zr-auth.php' => config_path('zr-auth.php')], 'zr-auth-config');

        $this->bloccoDellaSessione();
        $this->avvisaSeSpento();

        // Ogni pagina e ogni API del frontend vuole la sessione col gettone: ConGettone entra nei gruppi `web` e `api`, e
        // gira prima dei binding delle rotte. Dal kernel, non dal router: il kernel ricopia i suoi gruppi nel router, e
        // cancellerebbe un middleware messo solo lì. Una pagina pubblica se lo toglie con withoutMiddleware, e il test del
        // frontend la nomina (Testing\Rotte::senzaGuardia).
        // Il ricevitore del codice dell'ingresso è l'unica rotta di zr-auth, ed è pubblica: arriva da zr-home prima della
        // sessione. Il test del frontend la nomina fra le pubbliche (Testing\Rotte::senzaGuardia(['GET ingresso/ritorno'])).
        if (! $this->app->routesAreCached() && is_string(config('zr-auth.ricevitore')) && config('zr-auth.ricevitore') !== '') {
            Route::middleware('web')->get(config('zr-auth.ricevitore'), RicevitoreController::class)
                ->name('zr-auth.ricevitore')
                ->bloccaSessione()
                ->withoutMiddleware(ConGettone::class);
        }

        // Gli eventi del backoffice, solo se il modulo ha scelto un percorso: una consegna firmata da un server, nel gruppo
        // `api` (nel gruppo `web` un POST senza il cookie sarebbe un 419). Senza la guardia: chi chiama è il backoffice, e la
        // firma fa da guardia. Il test del frontend la nomina fra le pubbliche (Testing\Rotte::senzaGuardia(['POST <percorso>'])).
        if (! $this->app->routesAreCached() && is_string(config('zr-auth.eventi.percorso')) && config('zr-auth.eventi.percorso') !== '') {
            Route::middleware('api')->post(config('zr-auth.eventi.percorso'), EventiController::class)
                ->name('zr-auth.eventi')
                ->withoutMiddleware(ConGettone::class);
        }

        $this->callAfterResolving(HttpKernel::class, function (HttpKernel $kernel): void {
            if (! $kernel instanceof Kernel) {
                return;
            }
            foreach (['web', 'api'] as $gruppo) {
                if (array_key_exists($gruppo, $kernel->getMiddlewareGroups())) {
                    $kernel->appendMiddlewareToGroup($gruppo, ConGettone::class);
                    // Subito dopo la guardia; gira salvo `zr-auth.persona.controllo` spento (#2136, #2146).
                    $kernel->appendMiddlewareToGroup($gruppo, SoloDallaPersona::class);
                }
            }
            // Per primo nel gruppo `web`, fuori da StartSession: vede la risposta con la sessione già riscritta (#2044).
            if (array_key_exists('web', $kernel->getMiddlewareGroups())) {
                $kernel->prependMiddlewareToGroup('web', SessioneChiusa::class);
                // Dopo StartSession (e prima delle rotte): ricorda l'id con cui la sessione è partita.
                $kernel->appendMiddlewareToGroup('web', RicordaLIdDellaSessione::class);
                // Subito dopo StartSession e prima di ConGettone, che con un rifiuto non chiama il resto: il suo codice dopo `$next` gira
                // prima che StartSession scriva `_previous.url`, anche per chi viene mandato all'ingresso.
                $web = array_values(array_filter($kernel->getMiddlewareGroups()['web'], fn ($m): bool => $m !== PrecedenteSenzaQuery::class));
                $posizione = array_search(StartSession::class, $web, true);
                $posizione = $posizione !== false ? $posizione + 1 : (array_search(ConGettone::class, $web, true) ?: count($web));
                array_splice($web, $posizione, 0, [PrecedenteSenzaQuery::class]);
                $kernel->setMiddlewareGroups([...$kernel->getMiddlewareGroups(), 'web' => $web]);
            }
            $kernel->addToMiddlewarePriorityBefore(SubstituteBindings::class, ConGettone::class);
            $kernel->addToMiddlewarePriorityAfter(ConGettone::class, SoloDallaPersona::class);
        });
    }

    /**
     * Il controllo «Resta poi Salva» spento (`ZR_AUTH_PERSONA_CONTROLLO=false`) lascia la difesa spenta senza che nessuno lo veda (#2146):
     * il pacchetto lo scrive nel log, un avviso e mai un dato della persona o della richiesta. L'avvio di un processo PHP-FPM è ogni richiesta,
     * quindi la riga la ferma la cache (`add`: al più una all'ora per cache). Con una cache per processo (`array`) o che non risponde la
     * riga esce a ogni richiesta: il modulo ha scelto lui quella cache.
     */
    public function avvisaSeSpento(): void
    {
        if (filter_var(config('zr-auth.persona.controllo', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true) {
            return;
        }

        // Né la cache né il log che non rispondono devono rompere l'avvio: nel primo caso la riga esce a ogni richiesta, nel secondo non esce.
        try {
            if (! Cache::add('zr-auth:persona-controllo-spento', 1, 3600)) {
                return;
            }
        } catch (Throwable) {
        }

        try {
            Log::warning('zr-auth: il controllo «Resta poi Salva» è spento (ZR_AUTH_PERSONA_CONTROLLO=false): una scrittura dalla scheda di un\'altra persona passa.');
        } catch (Throwable) {
        }
    }

    /**
     * Il blocco della sessione (#1477): una richiesta che cambia la sessione (l'uscita, l'ingresso nel workspace) non si
     * sovrappone a un'altra della stessa sessione, che a fine corsa riscriverebbe la sessione di prima. `->bloccaSessione()`
     * su una rotta è il `Route::block` di Laravel con i tempi di zr-auth. Oltre l'attesa Laravel lancia
     * LockTimeoutException, che senza una mano sarebbe un 500: qui diventa un 503 con Retry-After, solo per una rotta con il
     * blocco e prima che la sessione parta (il timeout di un altro lock del modulo, dentro il controller, resta com'è), e senza dire di
     * chi è il blocco.
     */
    private function bloccoDellaSessione(): void
    {
        if (! Rotta::hasMacro('bloccaSessione')) {
            Rotta::macro('bloccaSessione', function (): Rotta {
                /** @var Rotta $this */
                return $this->block(Sessione::BLOCCO_TENUTA, Sessione::BLOCCO_ATTESA);
            });
        }

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $gestore): void {
            if (! $gestore instanceof Handler) {
                return;
            }

            // Non si riporta: un timeout atteso, che chiunque con un cookie suo può provocare, non deve riempire il log del
            // modulo di errori con la traccia intera. Quello di un altro lock del modulo si riporta come sempre.
            $gestore->dontReportWhen(fn (Throwable $errore) => $errore instanceof LockTimeoutException && self::eIlBloccoDellaSessione(request()));

            $gestore->renderable(function (LockTimeoutException $errore, Request $richiesta): ?Response {
                if (! self::eIlBloccoDellaSessione($richiesta)) {
                    return null;
                }

                return response('Riprova tra un istante.', 503, ['Retry-After' => '1', 'Cache-Control' => 'no-store']);
            });
        });
    }

    /**
     * Il lock della sessione scade prima che la sessione parta (StartSession la mette sulla richiesta solo dopo averlo preso):
     * su una rotta con il blocco e senza una sessione sulla richiesta il timeout è suo. Con una sessione già sulla richiesta è
     * di un lock del modulo, dentro il controller, e resta suo.
     */
    private static function eIlBloccoDellaSessione(Request $richiesta): bool
    {
        return $richiesta->route() instanceof Rotta && (bool) $richiesta->route()->locksFor() && ! $richiesta->hasSession();
    }
}
