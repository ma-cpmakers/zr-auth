<?php

use Illuminate\Support\Carbon;
use Zeiras\Auth\Api;
use Zeiras\Auth\Testing\BackofficeFinto;

// #3773 e #3774: staff.registro.ricerca.crea, `totale` su staff.registro.elenca e staff.utenti.registro.elenca, `aziende` nella scheda di staff.utenti.mostra, e Elenco::$totale.
// Che le risposte siano quelle del backoffice lo prova il FintoTest del backoffice; qui si provano le regole che il finto tiene.

beforeEach(function () {
    Carbon::setTestNow('2026-11-14 10:00:00');
    config(['zr-auth.cliente' => BackofficeFinto::CLIENTE, 'zr-auth.segreto' => BackofficeFinto::SEGRETO_DEL_CLIENTE]);
    request()->server->set('REMOTE_ADDR', '203.0.113.7');
    $this->finto = BackofficeFinto::attiva();
});

function ricercaDelRegistro(string $gettone, array $corpo): array
{
    return Api::staff($gettone)->post('/v1/staff/registro/ricerca', $corpo);
}

it('totale è delle righe che i filtri lasciano, non della pagina: su staff.registro.elenca e sul registro di un utente (#3773)', function () {
    $gettone = gettoneDelloStaffDi($this->finto, 'sara@lucianocastro.com');
    $giulia = $this->finto->persona('giulia@example.com', PASSWORD, nome: 'Giulia');

    foreach ([1, 2, 3] as $i) {
        Api::staff($gettone)->get('/v1/staff/utenti/'.$giulia['id']);
    }

    $pagina = Api::staff($gettone)->get('/v1/staff/registro', ['limite' => 1]);
    $filtrata = Api::staff($gettone)->get('/v1/staff/registro', ['azione' => 'staff.utenti.mostra', 'limite' => 1]);
    $dellUtente = Api::staff($gettone)->get('/v1/staff/utenti/'.$giulia['id'].'/registro', ['limite' => 1]);

    expect(array_keys($pagina))->toBe(['data', 'successivo', 'totale'])->and($pagina['data'])->toHaveCount(1)->and($pagina['totale'])->toBeGreaterThanOrEqual(4)
        ->and($filtrata['totale'])->toBe(3)->and($dellUtente['totale'])->toBe(3)
        ->and(Api::staff($gettone)->get('/v1/staff/registro', ['azione' => 'staff.inesistente'])['totale'])->toBe(0);
});

it('q cerca in azione, nome di chi ha agito, nome del bersaglio e nome e azienda di un workspace, mai nell\'email; parole in qualsiasi ordine, senza maiuscole né accenti (#3773)', function () {
    $gettone = gettoneDelloStaffDi($this->finto, 'sara@lucianocastro.com');
    $giulia = $this->finto->persona('giulia@example.com', PASSWORD, nome: 'Giulia');
    $workspace = $this->finto->workspace('Studio Rossi', $giulia);
    Api::staff($gettone)->get('/v1/staff/utenti/'.$giulia['id']);
    Api::staff($gettone)->get('/v1/staff/workspace/'.$workspace['id']);

    $azioni = fn (array $corpo) => array_column(ricercaDelRegistro($gettone, $corpo)['data'], 'azione');

    expect($azioni(['q' => 'utenti.mostra']))->toBe(['staff.utenti.mostra'])
        ->and($azioni(['q' => 'GIULIA']))->toBe(['staff.utenti.mostra'])
        ->and($azioni(['q' => 'rossi studio']))->toBe(['staff.workspace.mostra'])
        ->and($azioni(['q' => 'sara']))->toHaveCount(ricercaDelRegistro($gettone, ['q' => 'sara'])['totale'])
        ->and($azioni(['q' => 'example.com']))->toBe([])
        ->and($azioni(['q' => 'giulia inesistente']))->toBe([])
        ->and(ricercaDelRegistro($gettone, ['q' => 'giulia', 'azione' => 'staff.workspace.mostra'])['totale'])->toBe(0);
});

it('q è obbligatoria da 2 a 100 caratteri, un campo estraneo è 422, e il cursore è di questa ricerca e di questo q (#3773)', function () {
    $gettone = gettoneDelloStaffDi($this->finto, 'sara@lucianocastro.com');
    $giulia = $this->finto->persona('giulia@example.com', PASSWORD, nome: 'Giulia');

    foreach (range(1, 3) as $i) {
        Api::staff($gettone)->get('/v1/staff/utenti/'.$giulia['id']);
    }

    foreach ([[], ['q' => 'a'], ['q' => str_repeat('a', 101)], ['q' => 'ab', 'colore' => 'rosso'], ['q' => 'ab', 'limite' => 51]] as $corpo) {
        expect(esitoDelloStaff(fn () => ricercaDelRegistro($gettone, $corpo)))->toBe([422, 'dati_non_validi']);
    }

    $prima = ricercaDelRegistro($gettone, ['q' => 'giulia', 'limite' => 1]);
    $seconda = ricercaDelRegistro($gettone, ['q' => 'giulia', 'limite' => 1, 'cursore' => $prima['successivo']]);

    expect($prima['totale'])->toBe(3)->and($seconda['totale'])->toBe(3)->and($seconda['data'][0]['id'])->not->toBe($prima['data'][0]['id'])
        ->and(esitoDelloStaff(fn () => ricercaDelRegistro($gettone, ['q' => 'sara', 'limite' => 1, 'cursore' => $prima['successivo']])))->toBe([422, 'dati_non_validi'])
        ->and(esitoDelloStaff(fn () => Api::staff($gettone)->get('/v1/staff/registro', ['limite' => 1, 'cursore' => $prima['successivo']])))->toBe([422, 'dati_non_validi']);
});

it('supporto e fatturazione cercano e contano le sole proprie righe, e non scrivono nel registro (#3773)', function () {
    $capo = gettoneDelloStaffDi($this->finto, 'sara@lucianocastro.com');
    $giulia = $this->finto->persona('giulia@example.com', PASSWORD, nome: 'Giulia');
    Api::staff($capo)->get('/v1/staff/utenti/'.$giulia['id']);
    Carbon::setTestNow('2026-11-14 10:05:00');
    $supporto = gettoneDelloStaffDi($this->finto, 'dora@lucianocastro.com', 'supporto');
    Api::staff($supporto)->get('/v1/staff/utenti/'.$giulia['id']);
    $prima = count($this->finto->registroDelloStaff());

    $mie = ricercaDelRegistro($supporto, ['q' => 'giulia']);

    expect($mie['totale'])->toBe(1)->and(array_unique(array_column($mie['data'], 'ruolo')))->toBe(['supporto'])
        ->and(Api::staff($supporto)->get('/v1/staff/registro')['totale'])->toBe(count(array_filter($this->finto->registroDelloStaff(), fn (array $riga) => $riga['staff_id'] === $mie['data'][0]['staff_id'])))
        ->and(count($this->finto->registroDelloStaff()))->toBe($prima);
});

it('la scheda ha aziende: {id, nome} per nome, una volta sola, vuota senza workspace (#3774)', function () {
    $gettone = gettoneDelloStaffDi($this->finto, 'sara@lucianocastro.com');
    $giulia = $this->finto->persona('giulia@example.com', PASSWORD, nome: 'Giulia');
    $marco = $this->finto->persona('marco@example.com', PASSWORD, nome: 'Marco');
    $zeta = $this->finto->workspace('Zeta', $giulia);
    $alfa = $this->finto->workspace('Alfa', $giulia);
    $this->finto->workspace('Zeta bis', $giulia, $zeta['azienda_id']);

    $scheda = Api::staff($gettone)->get('/v1/staff/utenti/'.$giulia['id'])['data'];
    $senza = Api::staff($gettone)->get('/v1/staff/utenti/'.$marco['id'])['data'];

    expect(array_keys($scheda))->toContain('aziende')->and(array_search('aziende', array_keys($scheda)))->toBe(array_search('workspace', array_keys($scheda)) + 1)
        ->and($scheda['aziende'])->toBe([['id' => $alfa['azienda_id'], 'nome' => 'Alfa'], ['id' => $zeta['azienda_id'], 'nome' => 'Zeta']])
        ->and($senza['aziende'])->toBe([]);
});

it('Elenco porta totale se la risposta lo ha (un intero ≥ 0, dall\'ultima pagina), altrimenti null (#3773)', function () {
    $gettone = gettoneDelloStaffDi($this->finto, 'sara@lucianocastro.com');
    $giulia = $this->finto->persona('giulia@example.com', PASSWORD, nome: 'Giulia');
    foreach (range(1, 3) as $i) {
        Api::staff($gettone)->get('/v1/staff/utenti/'.$giulia['id']);
    }

    $registro = Api::staff($gettone)->elenco('/v1/staff/registro', ['limite' => 1], 2);
    $staff = Api::staff($gettone)->elenco('/v1/staff', ['limite' => 50]);

    expect($registro->totale)->toBeInt()->toBeGreaterThanOrEqual(4)->and($registro->tagliato)->toBeTrue()->and($registro->elementi)->toHaveCount(2)
        ->and($staff->totale)->toBeNull();
});
