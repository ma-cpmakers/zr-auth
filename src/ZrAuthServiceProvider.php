<?php

namespace Zeiras\Auth;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\ServiceProvider;
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

        // Ogni pagina e ogni API del frontend vuole la sessione col gettone: ConGettone entra nei gruppi `web` e `api`, e
        // gira prima dei binding delle rotte. Dal kernel, non dal router: il kernel ricopia i suoi gruppi nel router, e
        // cancellerebbe un middleware messo solo lì. Una pagina pubblica se lo toglie con withoutMiddleware, e il test del
        // frontend la nomina (Testing\Rotte::senzaGuardia).
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
}
