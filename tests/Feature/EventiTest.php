<?php

namespace Zeiras\Auth\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Zeiras\Auth\Eventi\EventoDelBackoffice;
use Zeiras\Auth\Eventi\Firma;
use Zeiras\Auth\Testing\Rotte;
use Zeiras\Auth\Tests\TestCase;

/**
 * Il ricevitore degli eventi del backoffice (#1368, RV1-RV3, RV6, RV7): la rotta che un modulo apre con
 * `zr-auth.eventi.percorso`. Il percorso si fissa prima che il pacchetto si avvii, quindi è un'app Testbench sua. Gli
 * header e i segreti sono sempre variabili: un valore letterale accanto a un nome così è ciò che la guardia dei segreti
 * (.github/nessun-segreto.sh) ferma.
 */
final class EventiTest extends TestCase
{
    private const PERCORSO = '/webhook/backoffice';

    private const ID = 'evt_01k6r2t5b9d3f7h1k5m9n3q7r1';

    private string $segreto;

    /** @var list<EventoDelBackoffice> */
    private array $visti = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $this->segreto = 'whsec_'.base64_encode(random_bytes(32));
        $app['config']->set('zr-auth.eventi.percorso', self::PERCORSO);
        $app['config']->set('zr-auth.eventi.segreto', $this->segreto);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-09 10:00:00', 'UTC'));
        Event::listen(EventoDelBackoffice::class, function (EventoDelBackoffice $evento): void {
            $this->visti[] = $evento;
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Un evento come lo scrive il backoffice (Forme::evento), in JSON come lo manda la consegna. */
    private function corpo(array $cambia = []): string
    {
        return json_encode(array_replace([
            'specversion' => '1.0',
            'id' => self::ID,
            'source' => 'https://api.zeiras.com/workspace/01k6r3a7c2e6g0j4m8p2s6v0x4',
            'type' => 'board.scheda.creata',
            'subject' => 'scheda/01k6r3b8d3f7h1k5m9n3q7r1s5',
            'time' => '2026-10-09T09:59:58.123Z',
            'sequence' => '000000000012',
            'autore' => '01k6r2t5b9d3f7h1k5m9n3q7r1',
            'causa' => null,
            'datacontenttype' => 'application/json',
            'data' => ['titolo' => 'Prima scheda', 'etichette' => ['a', 'b'], 'extra' => ['x' => null]],
        ], $cambia), JSON_UNESCAPED_SLASHES);
    }

    /**
     * Una consegna firmata come fa il backoffice. `$cambia` sostituisce un header (null = tolto), `$firma` la firma
     * (default: quella giusta sul corpo e sull'istante).
     *
     * @param  array<string, string|null>  $cambia
     */
    private function manda(?string $corpo = null, array $cambia = [], ?string $firma = null, ?int $istante = null, ?string $segreto = null): TestResponse
    {
        $corpo ??= $this->corpo();
        $istante ??= Carbon::now()->getTimestamp();
        $id = array_key_exists('id', $cambia) ? $cambia['id'] : self::ID;
        $chiave = Firma::chiave($segreto ?? $this->segreto) ?? 'nessuna chiave';
        $header = [
            'HTTP_WEBHOOK_ID' => $id,
            'HTTP_WEBHOOK_TIMESTAMP' => array_key_exists('istante', $cambia) ? $cambia['istante'] : (string) $istante,
            'HTTP_WEBHOOK_SIGNATURE' => array_key_exists('firma', $cambia) ? $cambia['firma'] : ($firma ?? Firma::calcola($chiave, (string) $id, $istante, $corpo)),
            'CONTENT_TYPE' => 'application/cloudevents+json; charset=utf-8',
        ];

        return $this->call('POST', self::PERCORSO, [], [], [], array_filter($header, fn ($valore) => $valore !== null), $corpo);
    }

    private function assertRifiutata(TestResponse $risposta): void
    {
        $risposta->assertStatus(401);
        $this->assertSame('{"status":401}', $risposta->getContent());
        $this->assertSame('application/problem+json', $risposta->headers->get('Content-Type'));
    }

    // --- RV1 -----------------------------------------------------------------------------------------------------

    public function test_rv1_una_firma_giusta_da_204_senza_corpo(): void
    {
        $risposta = $this->manda();

        $risposta->assertNoContent();
        $this->assertSame('', $risposta->getContent());
        $this->assertCount(1, $this->visti);
    }

    public function test_rv1_si_firma_il_corpo_ricevuto_non_quello_ricodificato(): void
    {
        // Spazi, ordine delle chiavi e slash che json_encode non scriverebbe così: la firma è sui byte arrivati.
        $corpo = '{ "type" : "board.scheda.creata",'."\n".'  "id":"'.self::ID.'", "data": {"z":1, "a":"è http:\/\/x"} }';

        $this->manda($corpo)->assertNoContent();
        $this->assertCount(1, $this->visti);
        $this->assertSame(['z' => 1, 'a' => 'è http://x'], $this->visti[0]->data);

        // La stessa firma su un corpo ricodificato (un byte di meno) non vale.
        Cache::flush();
        $this->assertRifiutata($this->manda($corpo, firma: Firma::calcola(Firma::chiave($this->segreto), self::ID, Carbon::now()->getTimestamp(), (string) json_encode(json_decode($corpo)))));
    }

    public function test_rv1_il_confine_del_timestamp_e_300_secondi_nei_due_versi(): void
    {
        $adesso = Carbon::now()->getTimestamp();

        $this->manda(istante: $adesso - 300, cambia: ['id' => 'evt_passato'])->assertNoContent();
        $this->manda(istante: $adesso + 300, cambia: ['id' => 'evt_futuro'])->assertNoContent();
        $this->assertRifiutata($this->manda(istante: $adesso - 301, cambia: ['id' => 'evt_vecchio']));
        $this->assertRifiutata($this->manda(istante: $adesso + 301, cambia: ['id' => 'evt_lontano']));
        $this->assertCount(2, $this->visti);
    }

    public function test_rv1_ogni_rifiuto_e_identico_nel_corpo_nel_tipo_e_negli_header(): void
    {
        $adesso = Carbon::now()->getTimestamp();
        $chiave = Firma::chiave($this->segreto);
        $altroCorpo = $this->corpo(['type' => 'altro.tipo']);

        $casi = [
            'firma sbagliata' => fn () => $this->manda(firma: 'v1,'.base64_encode(random_bytes(32))),
            'firma di un altro corpo' => fn () => $this->manda(firma: Firma::calcola($chiave, self::ID, $adesso, $altroCorpo)),
            'firma di un altro id' => fn () => $this->manda(firma: Firma::calcola($chiave, 'evt_altro', $adesso, $this->corpo())),
            'firma di un altro istante' => fn () => $this->manda(firma: Firma::calcola($chiave, self::ID, $adesso - 5, $this->corpo())),
            'firma con un’altra versione' => fn () => $this->manda(cambia: ['firma' => 'v2,'.substr(Firma::calcola($chiave, self::ID, $adesso, $this->corpo()), 3)]),
            'id mancante' => fn () => $this->manda(cambia: ['id' => null]),
            'id vuoto' => fn () => $this->manda(cambia: ['id' => '']),
            'istante mancante' => fn () => $this->manda(cambia: ['istante' => null]),
            'istante vuoto' => fn () => $this->manda(cambia: ['istante' => '']),
            'firma mancante' => fn () => $this->manda(cambia: ['firma' => null]),
            'firma vuota' => fn () => $this->manda(cambia: ['firma' => '']),
            'istante non numerico' => fn () => $this->manda(cambia: ['istante' => 'ieri']),
            'istante con un suffisso' => fn () => $this->manda(cambia: ['istante' => $adesso.'abc']),
            'istante negativo' => fn () => $this->manda(cambia: ['istante' => '-'.$adesso]),
            'istante decimale' => fn () => $this->manda(cambia: ['istante' => $adesso.'.5']),
            'istante vecchio di 301 secondi' => fn () => $this->manda(istante: $adesso - 301),
            'istante di 301 secondi dopo' => fn () => $this->manda(istante: $adesso + 301),
        ];

        $viste = [];
        foreach ($casi as $nome => $caso) {
            $risposta = $caso();
            $this->assertRifiutata($risposta);
            $header = array_keys($risposta->headers->allPreserveCase());
            $viste[$nome] = [$risposta->getContent(), $risposta->headers->get('Content-Type'), array_values(array_diff($header, ['Date']))];
        }

        $this->assertCount(1, array_unique(array_map('serialize', $viste)), 'i rifiuti si distinguono: '.json_encode($viste));
        $this->assertSame([], $this->visti);
    }

    public function test_rv1_un_segreto_assente_o_non_valido_rifiuta_come_ogni_altro_caso(): void
    {
        $altro = 'whsec_'.base64_encode(str_repeat('a', 32));

        foreach ([null, '', 'whsec_', 'non-un-segreto', 'whsec_'.base64_encode('troppo corto'), 'whsec_'.base64_encode(str_repeat('a', 65))] as $segreto) {
            config()->set('zr-auth.eventi.segreto', $segreto);

            // Firmato con un altro segreto, valido: senza una chiave valida in configurazione non c'è firma che valga.
            $this->assertRifiutata($this->manda(segreto: $altro));
        }
        $this->assertSame([], $this->visti);
    }

    public function test_rv1_fra_piu_firme_ne_basta_una_e_una_di_un_altra_versione_non_conta(): void
    {
        $adesso = Carbon::now()->getTimestamp();
        $giusta = Firma::calcola(Firma::chiave($this->segreto), self::ID, $adesso, $this->corpo());
        $altra = Firma::calcola(random_bytes(32), self::ID, $adesso, $this->corpo());

        $this->manda(cambia: ['firma' => "v2,qualcosa {$altra} {$giusta}"])->assertNoContent();

        Cache::flush();
        $this->assertRifiutata($this->manda(cambia: ['firma' => 'v2,'.substr($giusta, 3)]));
    }

    public function test_rv1_il_ricevitore_non_ricodifica_il_corpo_prima_di_verificarlo(): void
    {
        $sorgente = file_get_contents(__DIR__.'/../../src/Http/Controllers/EventiController.php');

        $this->assertStringNotContainsString('json_encode', $sorgente);
        $this->assertStringContainsString('getContent()', $sorgente);
        $this->assertStringNotContainsString('->all()', $sorgente);
        $this->assertStringNotContainsString('->json(', $sorgente);
    }

    // --- RV2 -----------------------------------------------------------------------------------------------------

    public function test_rv2_lo_stesso_evento_due_volte_arriva_una_volta_sola(): void
    {
        $this->manda()->assertNoContent();
        $this->manda()->assertNoContent();

        $this->assertCount(1, $this->visti);
    }

    public function test_rv2_la_chiave_ha_un_tempo_e_dopo_300_secondi_l_evento_si_riceve_di_nuovo(): void
    {
        $this->manda()->assertNoContent();

        Carbon::setTestNow(Carbon::now()->addSeconds(299));
        $this->manda()->assertNoContent();
        $this->assertCount(1, $this->visti, 'a 299 secondi il doppione è ancora ricordato');

        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $this->manda()->assertNoContent();
        $this->assertCount(2, $this->visti, 'a 301 secondi la chiave è scaduta: aveva un TTL');
    }

    public function test_rv2_la_chiave_sta_nella_cache_del_modulo_sotto_zr_auth_evento(): void
    {
        $this->manda()->assertNoContent();

        $this->assertTrue(Cache::has('zr-auth:evento:'.self::ID));
    }

    public function test_rv2_una_firma_sbagliata_con_l_id_di_un_evento_vero_non_lo_brucia(): void
    {
        $this->assertRifiutata($this->manda(firma: 'v1,'.base64_encode(random_bytes(32))));
        $this->assertFalse(Cache::has('zr-auth:evento:'.self::ID));

        $this->manda()->assertNoContent();
        $this->assertCount(1, $this->visti);
    }

    public function test_rv2_un_webhook_id_che_non_ha_la_forma_giusta_e_un_rifiuto_come_gli_altri(): void
    {
        foreach (['evt con spazio', 'evt.punto', 'evt/barra', str_repeat('a', 129), "evt\nacapo"] as $id) {
            $this->assertRifiutata($this->manda(cambia: ['id' => $id]));
        }
        $this->manda(cambia: ['id' => str_repeat('a', 128)])->assertNoContent();
        $this->manda(cambia: ['id' => 'A-b_9'])->assertNoContent();
        $this->assertCount(2, $this->visti);
    }

    // --- RV3 -----------------------------------------------------------------------------------------------------

    public function test_rv3_l_evento_arriva_con_le_sue_proprieta_e_il_corpo_intero(): void
    {
        $this->manda()->assertNoContent();

        $evento = $this->visti[0];
        $this->assertSame(self::ID, $evento->id);
        $this->assertSame('board.scheda.creata', $evento->type);
        $this->assertSame('scheda/01k6r3b8d3f7h1k5m9n3q7r1s5', $evento->subject);
        $this->assertSame('000000000012', $evento->sequence);
        $this->assertSame('2026-10-09T09:59:58.123Z', $evento->time);
        $this->assertSame(['titolo' => 'Prima scheda', 'etichette' => ['a', 'b'], 'extra' => ['x' => null]], $evento->data);
        $this->assertSame('01k6r2t5b9d3f7h1k5m9n3q7r1', $evento->corpo['autore']);
        $this->assertArrayHasKey('causa', $evento->corpo);
        $this->assertSame('1.0', $evento->corpo['specversion']);
        $this->assertSame($evento->data, $evento->corpo['data']);
    }

    public function test_rv3_le_proprieta_sono_di_sola_lettura(): void
    {
        $this->manda()->assertNoContent();

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('readonly');
        $this->visti[0]->type = 'altro';
    }

    public function test_rv3_un_tipo_mai_visto_e_un_campo_che_il_pacchetto_non_conosce_passano_invariati(): void
    {
        $corpo = $this->corpo(['type' => 'cosa.mai.vista.eliminata', 'campo_nuovo' => ['x' => [1, 2]], 'data' => ['id' => 'id-che-non-esiste']]);

        $this->manda($corpo)->assertNoContent();

        $this->assertSame('cosa.mai.vista.eliminata', $this->visti[0]->type);
        $this->assertSame(['x' => [1, 2]], $this->visti[0]->corpo['campo_nuovo']);
        $this->assertSame(['id' => 'id-che-non-esiste'], $this->visti[0]->data);
    }

    public function test_rv3_data_e_un_array_anche_vuoto_e_sequence_resta_una_stringa(): void
    {
        $this->manda($this->corpo(['data' => new \stdClass, 'sequence' => '000000000001']))->assertNoContent();

        $this->assertSame([], $this->visti[0]->data);
        $this->assertIsString($this->visti[0]->sequence);
        $this->assertSame('000000000001', $this->visti[0]->sequence);
    }

    // --- RV6 -----------------------------------------------------------------------------------------------------

    public function test_rv6_un_corpo_firmato_bene_che_non_e_un_evento_e_un_400_e_non_brucia_l_id(): void
    {
        $storti = [
            'non json' => 'questo non è json',
            'una lista' => '[1,2,3]',
            'una stringa' => '"evento"',
            'senza type' => json_encode(['id' => self::ID, 'data' => new \stdClass]),
            'type numerico' => json_encode(['id' => self::ID, 'type' => 7, 'data' => new \stdClass]),
            'senza id' => json_encode(['type' => 'a.b', 'data' => new \stdClass]),
            'id numerico' => json_encode(['id' => 7, 'type' => 'a.b', 'data' => new \stdClass]),
            'senza data' => json_encode(['id' => self::ID, 'type' => 'a.b']),
            'data stringa' => json_encode(['id' => self::ID, 'type' => 'a.b', 'data' => 'x']),
            'data lista' => json_encode(['id' => self::ID, 'type' => 'a.b', 'data' => [1, 2]]),
            'data nullo' => json_encode(['id' => self::ID, 'type' => 'a.b', 'data' => null]),
        ];

        foreach ($storti as $nome => $corpo) {
            $this->manda($corpo)->assertStatus(400);
            $this->assertFalse(Cache::has('zr-auth:evento:'.self::ID), "il 400 ({$nome}) ha scritto il marcatore");
        }

        $this->assertSame([], $this->visti);
        $this->manda()->assertNoContent();
        $this->assertCount(1, $this->visti);
    }

    public function test_rv6_un_ascoltatore_che_lancia_da_un_5xx_e_il_tentativo_dopo_riprova(): void
    {
        Event::forget(EventoDelBackoffice::class);
        $guasto = true;
        Event::listen(EventoDelBackoffice::class, function (EventoDelBackoffice $evento) use (&$guasto): void {
            if ($guasto) {
                throw new RuntimeException('il modulo ha avuto un guasto');
            }
            $this->visti[] = $evento;
        });

        $this->manda()->assertStatus(500);
        $this->assertFalse(Cache::has('zr-auth:evento:'.self::ID), 'il marcatore è rimasto: il tentativo dopo sarebbe un 204 e l’evento perso');

        $guasto = false;
        $this->manda()->assertNoContent();
        $this->assertCount(1, $this->visti);
    }

    // --- RV7 -----------------------------------------------------------------------------------------------------

    public function test_rv7_con_un_percorso_la_rotta_e_nominata_e_risponde_solo_a_post(): void
    {
        $this->assertTrue(Route::has('zr-auth.eventi'));
        $this->assertSame(['POST'], Route::getRoutes()->getByName('zr-auth.eventi')->methods());
        $this->get(self::PERCORSO)->assertStatus(405);
        $this->put(self::PERCORSO)->assertStatus(405);
    }

    public function test_rv7_la_rotta_e_pubblica_per_la_guardia_e_il_frontend_la_nomina(): void
    {
        $this->assertContains('POST webhook/backoffice', Rotte::senzaGuardia());
        $this->assertNotContains('POST webhook/backoffice', Rotte::senzaGuardia(['POST webhook/backoffice']));
    }

    public function test_rv7_la_rotta_non_ha_sessione_ne_csrf(): void
    {
        $risposta = $this->manda();

        $risposta->assertNoContent();
        $this->assertSame([], $risposta->headers->getCookies());
    }
}
