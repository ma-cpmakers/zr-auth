<?php

namespace Zeiras\Auth\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Zeiras\Auth\Testing\Rotte;
use Zeiras\Auth\Tests\TestCase;

/**
 * RV7: un modulo che non riceve eventi non mette `zr-auth.eventi.percorso`, e zr-auth non registra nessuna rotta. Anche
 * con un segreto in configurazione: la rotta la apre il percorso, non il segreto.
 */
final class EventiAssentiTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('zr-auth.eventi.segreto', 'whsec_'.base64_encode(random_bytes(32)));
    }

    public function test_senza_percorso_il_pacchetto_non_registra_nessuna_rotta_degli_eventi(): void
    {
        $this->assertNull(config('zr-auth.eventi.percorso'));
        $this->assertFalse(Route::has('zr-auth.eventi'));
        $this->assertNotContains('POST webhook/backoffice', Rotte::senzaGuardia());
        $this->post('/webhook/backoffice')->assertNotFound();
        $this->post('/eventi')->assertNotFound();
    }

    public function test_senza_percorso_il_ricevitore_del_codice_c_e_ancora(): void
    {
        $this->assertTrue(Route::has('zr-auth.ricevitore'));
    }
}
