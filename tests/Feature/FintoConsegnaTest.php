<?php

namespace Zeiras\Auth\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use LogicException;
use Zeiras\Auth\Eventi\EventoDelBackoffice;
use Zeiras\Auth\Eventi\Firma;
use Zeiras\Auth\Testing\BackofficeFinto;
use Zeiras\Auth\Tests\TestCase;

/**
 * RV4 (#1368): il finto fa la consegna di un evento come il backoffice (Forme::evento, Consegna::manda): i tre header, il
 * corpo, la firma di Standard Webhooks col segreto di `zr-auth.eventi.segreto`. Il modulo la manda alla sua rotta e prova
 * il suo ascoltatore. Segreti e header sono variabili: la guardia dei segreti ferma un valore letterale accanto a un nome così.
 */
final class FintoConsegnaTest extends TestCase
{
    private const PERCORSO = '/webhook/backoffice';

    private string $segreto;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $this->segreto = 'whsec_'.base64_encode(random_bytes(32));
        $app['config']->set('zr-auth.eventi.percorso', self::PERCORSO);
        $app['config']->set('zr-auth.eventi.segreto', $this->segreto);
    }

    /** Manda la consegna del finto alla rotta del ricevitore, come fa il backoffice. */
    private function recapita(array $consegna): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/cloudevents+json; charset=utf-8'];
        foreach ($consegna['intestazioni'] as $nome => $valore) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $nome))] = $valore;
        }

        return $this->call('POST', self::PERCORSO, [], [], [], $server, $consegna['corpo']);
    }

    public function test_rv4_la_consegna_ha_i_tre_header_e_il_corpo_nella_forma_di_forme_evento(): void
    {
        $finto = BackofficeFinto::attiva();

        $consegna = $finto->consegna('board.scheda.creata', 'scheda/01k6r3b8d3f7h1k5m9n3q7r1s5', ['titolo' => 'Prima']);

        $this->assertSame(['intestazioni', 'corpo'], array_keys($consegna));
        $this->assertSame(['webhook-id', 'webhook-timestamp', 'webhook-signature'], array_keys($consegna['intestazioni']));
        $this->assertIsString($consegna['corpo']);

        $corpo = json_decode($consegna['corpo'], true, flags: JSON_THROW_ON_ERROR);
        // Le chiavi di Forme::evento del backoffice, nel suo ordine.
        $this->assertSame(['specversion', 'id', 'source', 'type', 'subject', 'time', 'sequence', 'autore', 'causa', 'datacontenttype', 'data'], array_keys($corpo));
        $this->assertSame('1.0', $corpo['specversion']);
        $this->assertSame('board.scheda.creata', $corpo['type']);
        $this->assertSame('scheda/01k6r3b8d3f7h1k5m9n3q7r1s5', $corpo['subject']);
        $this->assertSame('application/json', $corpo['datacontenttype']);
        $this->assertSame(['titolo' => 'Prima'], $corpo['data']);
        $this->assertStringStartsWith('https://api.zeiras.com/workspace/', $corpo['source']);
        $this->assertSame($corpo['id'], $consegna['intestazioni']['webhook-id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $corpo['time']);
    }

    public function test_rv4_un_data_vuoto_e_un_oggetto_non_una_lista(): void
    {
        $consegna = BackofficeFinto::attiva()->consegna('board.scheda.creata', 'scheda/x');

        $this->assertStringContainsString('"data":{}', $consegna['corpo']);
    }

    public function test_rv4_l_id_e_un_ulid_nuovo_a_ogni_chiamata_e_la_sequenza_cresce_a_dodici_cifre(): void
    {
        $finto = BackofficeFinto::attiva();

        $primo = json_decode($finto->consegna('a.b', 's')['corpo'], true);
        $secondo = json_decode($finto->consegna('a.b', 's')['corpo'], true);
        $terzo = json_decode($finto->consegna('a.b', 's')['corpo'], true);

        foreach ([$primo, $secondo, $terzo] as $corpo) {
            $this->assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/', $corpo['id']);
            $this->assertMatchesRegularExpression('/^\d{12}$/', $corpo['sequence']);
        }
        $this->assertCount(3, array_unique([$primo['id'], $secondo['id'], $terzo['id']]));
        $this->assertTrue($primo['sequence'] < $secondo['sequence'] && $secondo['sequence'] < $terzo['sequence']);
        $this->assertSame([1, 2, 3], [(int) $primo['sequence'], (int) $secondo['sequence'], (int) $terzo['sequence']]);
    }

    public function test_rv4_la_firma_e_quella_di_firma_calcola_sul_corpo_che_manda(): void
    {
        $consegna = BackofficeFinto::attiva()->consegna('board.scheda.creata', 'scheda/x', ['a' => 1], 1760000000);

        $intestazioni = $consegna['intestazioni'];
        $chiave = Firma::chiave($this->segreto);

        $this->assertSame('1760000000', $intestazioni['webhook-timestamp']);
        $this->assertSame(Firma::calcola($chiave, $intestazioni['webhook-id'], 1760000000, $consegna['corpo']), $intestazioni['webhook-signature']);
        // E la verifica del ricevitore, sulla stessa terna: il finto non firma un corpo diverso da quello che manda.
        $this->assertTrue(Firma::verifica($chiave, $intestazioni['webhook-id'], 1760000000, $consegna['corpo'], $intestazioni['webhook-signature']));
        $this->assertFalse(Firma::verifica($chiave, $intestazioni['webhook-id'], 1760000000, $consegna['corpo'].' ', $intestazioni['webhook-signature']));
    }

    public function test_rv4_il_timestamp_e_l_istante_dell_evento_e_di_default_adesso(): void
    {
        $finto = BackofficeFinto::attiva();
        $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00:00.123', 'UTC'));

        $adesso = $finto->consegna('a.b', 's');
        $this->assertSame((string) CarbonImmutable::now()->getTimestamp(), $adesso['intestazioni']['webhook-timestamp']);

        $prima = $finto->consegna('a.b', 's', [], 1760000000);
        $this->assertSame('2025-10-09T08:53:20.000Z', json_decode($prima['corpo'], true)['time']);
    }

    public function test_rv4_il_ricevitore_la_prende_e_l_ascoltatore_vede_l_evento(): void
    {
        $visti = [];
        Event::listen(EventoDelBackoffice::class, function (EventoDelBackoffice $evento) use (&$visti): void {
            $visti[] = $evento;
        });
        $finto = BackofficeFinto::attiva();

        $consegna = $finto->consegna('board.scheda.creata', 'scheda/x', ['titolo' => 'Prima']);
        $this->recapita($consegna)->assertNoContent();

        $this->assertCount(1, $visti);
        $this->assertSame('board.scheda.creata', $visti[0]->type);
        $this->assertSame('scheda/x', $visti[0]->subject);
        $this->assertSame(['titolo' => 'Prima'], $visti[0]->data);
        $this->assertSame('000000000001', $visti[0]->sequence);

        // La stessa consegna una seconda volta è un doppione; una nuova arriva.
        $this->recapita($consegna)->assertNoContent();
        $this->recapita($finto->consegna('board.scheda.eliminata', 'scheda/id-sconosciuto'))->assertNoContent();
        $this->assertCount(2, $visti);
    }

    public function test_rv4_senza_segreto_in_configurazione_lancia_con_il_nome_della_variabile(): void
    {
        $finto = BackofficeFinto::attiva();

        foreach ([null, '', 'non-un-segreto'] as $segreto) {
            config()->set('zr-auth.eventi.segreto', $segreto);

            try {
                $finto->consegna('a.b', 's');
                $this->fail('la consegna senza un segreto valido deve lanciare');
            } catch (LogicException $errore) {
                $this->assertStringContainsString('ZR_EVENTI_SEGRETO', $errore->getMessage());
            }
        }
    }
}
