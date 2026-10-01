<?php

namespace Zeiras\Auth;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\ServiceProvider;
use Zeiras\Auth\Http\Middleware\Sessione;

final class ZrAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/zr-auth.php', 'zr-auth');
        $this->app->scoped(Contesto::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/zr-auth.php' => config_path('zr-auth.php')], 'zr-auth-config');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/zr-auth.php');

        // Ogni pagina e ogni API del modulo vuole la sessione: Sessione entra nei gruppi `web` e `api`, e gira prima dei
        // binding delle rotte, che leggono già il workspace della sessione (DelWorkspace). Dal kernel, non dal router: il
        // kernel ricopia i suoi gruppi nel router, e cancellerebbe un middleware messo solo lì.
        $this->callAfterResolving(HttpKernel::class, function (HttpKernel $kernel): void {
            if (! $kernel instanceof Kernel) {
                return;
            }
            foreach (['web', 'api'] as $gruppo) {
                if (array_key_exists($gruppo, $kernel->getMiddlewareGroups())) {
                    $kernel->appendMiddlewareToGroup($gruppo, Sessione::class);
                }
            }
            $kernel->addToMiddlewarePriorityBefore(SubstituteBindings::class, Sessione::class);
        });
    }
}
