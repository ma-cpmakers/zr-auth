<?php

namespace Zeiras\Auth\Tests;

use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase as Testbench;
use Zeiras\Auth\Contesto;
use Zeiras\Auth\ZrAuthServiceProvider;

/**
 * Un modulo finto: zr-auth installato in un'app Laravel, con qualche pagina sua. zr-home non c'è: lo fanno le risposte
 * di Http::fake() (tests/Pest.php).
 */
abstract class TestCase extends Testbench
{
    protected function getPackageProviders($app): array
    {
        return [ZrAuthServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', MODULO);
        $app['config']->set('zr-auth.client_id', CLIENTE);
        $app['config']->set('zr-auth.client_secret', SEGRETO);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    /** Le pagine del modulo, nel gruppo `web`: zr-auth ci mette la sessione da sé. */
    protected function defineWebRoutes($router): void
    {
        $router->get('pagina', fn (Contesto $contesto) => [
            'persona' => $contesto->personaId(),
            'workspace' => $contesto->workspaceId(),
            'nome' => $contesto->workspaceNome(),
            'ruolo' => $contesto->ruolo(),
        ]);
        $router->get('note', fn () => Nota::query()->orderBy('id')->pluck('testo'));
        $router->get('note/{nota}', fn (Nota $nota) => ['testo' => $nota->testo]);
        $router->post('note', fn (Request $richiesta) => Nota::query()->create($richiesta->only('testo', 'workspace_id'))
            ->only('id', 'workspace_id'));
    }

    /** Un'API del modulo, nel gruppo `api`. */
    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->get('dati', fn () => ['dati' => true]);
    }
}
