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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;
use Zeiras\Auth\Http\Controllers\EventiController;
use Zeiras\Auth\Http\Controllers\RicevitoreController;
use Zeiras\Auth\Http\Middleware\ConGettone;

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
                }
            }
            $kernel->addToMiddlewarePriorityBefore(SubstituteBindings::class, ConGettone::class);
        });
    }

    /**
     * Il blocco della sessione (#1477): una richiesta che cambia la sessione (l'uscita, l'ingresso nel workspace) non si
     * sovrappone a un'altra della stessa sessione, che a fine corsa riscriverebbe la sessione di prima. `->bloccaSessione()`
     * su una rotta è il `Route::block` di Laravel con i tempi di zr-auth. Oltre l'attesa Laravel lancia
     * LockTimeoutException, che senza una mano sarebbe un 500: qui diventa un 503 con Retry-After, solo per una rotta con il
     * blocco (il timeout di un altro lock del modulo resta com'è) e senza dire di chi è il blocco.
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

            $gestore->renderable(function (LockTimeoutException $errore, Request $richiesta): ?Response {
                if (! $richiesta->route() instanceof Rotta || ! $richiesta->route()->locksFor()) {
                    return null;
                }

                return response('Riprova tra un istante.', 503, ['Retry-After' => '1', 'Cache-Control' => 'no-store']);
            });
        });
    }
}
