<?php

namespace Zeiras\Auth\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Testbench;
use Zeiras\Auth\Api;
use Zeiras\Auth\Http\Middleware\ConGettone;
use Zeiras\Auth\Sessione;
use Zeiras\Auth\ZrAuthServiceProvider;

/**
 * Un frontend finto: zr-auth installato in un'app Laravel, con qualche pagina sua. Il backoffice non c'è: lo fanno le
 * risposte di Http::fake(), e nessuna richiesta esce.
 */
abstract class TestCase extends Testbench
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [ZrAuthServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Cookie e sessione cifrati, come nel frontend: la chiave nasce nel test, nessuna nel repo (G13).
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.url', FRONTEND);
        $app['config']->set('session.driver', 'array');
    }

    /** Le pagine del frontend, nel gruppo `web`: zr-auth ci mette la guardia da sé. */
    protected function defineWebRoutes($router): void
    {
        // Ciò che la sessione dice della persona: una pagina che lo mostra non deve mostrare il gettone (T1.1).
        $router->get('pagina', fn () => [
            'utente' => Sessione::utente(),
            'workspace' => Sessione::workspace(),
            'ruolo' => Sessione::ruolo(),
            'accesso' => Sessione::accesso(),
        ]);
        // Una pagina che legge dal backoffice col gettone del workspace (T1.4).
        $router->get('io', fn () => Api::workspace()->get('/v1/io'));
        // L'accesso del frontend, pubblico: apre la sessione coi dati di accessi.crea e, se ci sono, di gettoni.crea.
        $router->post('entra', function (Request $richiesta) {
            Sessione::apri($richiesta->input('accesso'));
            if ($richiesta->has('gettone')) {
                Sessione::entra($richiesta->input('gettone'));
            }

            return ['ok' => true];
        })->withoutMiddleware(ConGettone::class);
        $router->get('pubblica', fn () => 'pubblica')->withoutMiddleware(ConGettone::class);
    }

    /** Rotte fuori dai gruppi: il controllo di salute (un'eccezione per tutti) e una rotta scoperta. */
    protected function defineRoutes($router): void
    {
        $router->get('up', fn () => 'ok');
        $router->get('fuori', fn () => 'fuori');
    }
}
