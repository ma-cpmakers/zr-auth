<?php

namespace Zeiras\Auth\Tests\Feature;

use Illuminate\Support\Facades\Route;
use LogicException;
use Zeiras\Auth\Ingresso;
use Zeiras\Auth\Testing\Rotte;
use Zeiras\Auth\Tests\TestCase;

/**
 * T4.6 (per zr-home): un frontend che l'ingresso lo dà e non lo riceve mette `zr-auth.ricevitore = null`, e zr-auth non
 * registra nessuna rotta. Il config si fissa prima che il pacchetto si avvii, quindi è un'app Testbench sua e non un test
 * Pest sull'app di tutti gli altri.
 */
final class RicevitoreAssenteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('zr-auth.ricevitore', null);
        $app['config']->set('zr-auth.app', null);
    }

    public function test_senza_ricevitore_il_pacchetto_non_registra_nessuna_rotta(): void
    {
        $this->assertFalse(Route::has('zr-auth.ricevitore'));
        $this->assertNotContains('GET ingresso/ritorno', Rotte::senzaGuardia());
        $this->get('/ingresso/ritorno')->assertNotFound();
    }

    public function test_senza_zr_app_il_pacchetto_si_avvia_e_solo_la_partenza_lo_vuole(): void
    {
        $this->assertNull(config('zr-auth.app'));
        $this->get('/pubblica')->assertOk();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ZR_APP');

        Ingresso::verso('studio-anna-k3x9q2');
    }
}
