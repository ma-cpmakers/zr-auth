<?php

use Illuminate\Support\Carbon;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Errori\GettoneRifiutato;
use Zeiras\Auth\Testing\BackofficeFinto;

// #3261: `staff.workspace.sospensione.crea` e `.elimina` del finto, per la sospensione di un workspace di zr-superadmin (F3.4). Che forme ed errori siano quelli del backoffice lo prova il FintoTest del
// backoffice; qui si prova che il finto tiene le regole: solo il superadmin, i motivi della persona, la nota, `gia_sospeso`, `404` se non è sospeso, lo stato e i conteggi, nessun gettone nuovo.

beforeEach(function () {
    Carbon::setTestNow('2026-11-14 10:00:00');
    config(['zr-auth.cliente' => BackofficeFinto::CLIENTE, 'zr-auth.segreto' => BackofficeFinto::SEGRETO_DEL_CLIENTE]);
    request()->server->set('REMOTE_ADDR', '203.0.113.7');
    $this->finto = BackofficeFinto::attiva()->ritorno('pm', 'https://board.zeiras.com/ingresso/ritorno');
});

/** @return array{0: int|null, 1: string|null} lo stato e il codice di un errore di /v1, o [null, null] se riesce. */
function esitoSospensioneWorkspace(Closure $chiamata): array
{
    try {
        $chiamata();
    } catch (ErroreApi $errore) {
        return [$errore->stato, $errore->codice];
    } catch (GettoneRifiutato $rifiutato) {
        return [$rifiutato->errore?->stato, $rifiutato->errore?->codice];
    }

    return [null, null];
}

function gettoneSospensioneWorkspace(BackofficeFinto $finto, string $email, string $ruolo = 'superadmin'): string
{
    $persona = $finto->persona($email, PASSWORD, nome: ucfirst(strstr($email, '@', true)));

    return $finto->ingressoDelloStaff($finto->staffDi($persona, $ruolo, false, false));
}

it('sospende e riattiva: 201 {motivo, nota, dal}, stato e sospensione nella scheda, nell\'elenco e nei conteggi; la nota non va nel registro (#3261)', function () {
    $staff = gettoneSospensioneWorkspace($this->finto, 'sara@lucianocastro.com');
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $uno = $this->finto->workspace('Studio uno', $anna);
    $this->finto->workspace('Studio due', $anna);

    $r = Api::staff($staff)->post("/v1/staff/workspace/{$uno['id']}/sospensione", ['motivo' => 'pagamento_non_riuscito', 'nota' => 'Fattura non pagata.']);

    expect($r['data'])->toBe(['motivo' => 'pagamento_non_riuscito', 'nota' => 'Fattura non pagata.', 'dal' => '2026-11-14T10:00:00.000Z'])
        ->and(Api::staff($staff)->get("/v1/staff/workspace/{$uno['id']}")['data'])->toMatchArray(['stato' => 'sospeso', 'sospensione' => ['motivo' => 'pagamento_non_riuscito', 'nota' => 'Fattura non pagata.', 'dal' => '2026-11-14T10:00:00.000Z']])
        ->and(array_column(Api::staff($staff)->get('/v1/staff/workspace', ['stato' => 'sospeso'])['data'], 'id'))->toBe([$uno['id']])
        ->and(Api::staff($staff)->get('/v1/staff/workspace/conteggi')['data'])->toBe(['tutti' => 2, 'attivo' => 1, 'sospeso' => 1, 'in_eliminazione' => 0]);

    $righe = array_values(array_filter($this->finto->registroDelloStaff(), fn (array $riga) => str_contains(json_encode($riga), 'staff.workspace.sospensione.crea')));
    expect($righe)->toHaveCount(1)->and(json_encode($righe))->toContain('pagamento_non_riuscito')->not->toContain('Fattura');

    Api::staff($staff)->delete("/v1/staff/workspace/{$uno['id']}/sospensione");
    expect(Api::staff($staff)->get("/v1/staff/workspace/{$uno['id']}")['data'])->toMatchArray(['stato' => 'attivo', 'sospensione' => null])
        ->and(Api::staff($staff)->get('/v1/staff/workspace/conteggi')['data'])->toBe(['tutti' => 2, 'attivo' => 2, 'sospeso' => 0, 'in_eliminazione' => 0]);
});

it('le regole: solo il superadmin, motivo e nota, già sospeso 409, non sospeso 404, workspace inesistente 404 (#3261)', function () {
    $staff = gettoneSospensioneWorkspace($this->finto, 'sara@lucianocastro.com');
    $supporto = gettoneSospensioneWorkspace($this->finto, 'sup@lucianocastro.com', 'supporto');
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $uno = $this->finto->workspace('Studio uno', $anna);
    $percorso = "/v1/staff/workspace/{$uno['id']}/sospensione";

    expect(esitoSospensioneWorkspace(fn () => Api::staff($supporto)->post($percorso, ['motivo' => 'altro'])))->toBe([403, 'permesso_negato'])
        ->and(esitoSospensioneWorkspace(fn () => Api::staff($supporto)->delete($percorso)))->toBe([403, 'permesso_negato'])
        ->and(esitoSospensioneWorkspace(fn () => Api::staff($staff)->post($percorso, [])))->toBe([422, 'dati_non_validi'])
        ->and(esitoSospensioneWorkspace(fn () => Api::staff($staff)->post($percorso, ['motivo' => 'perché'])))->toBe([422, 'dati_non_validi'])
        ->and(esitoSospensioneWorkspace(fn () => Api::staff($staff)->post($percorso, ['motivo' => 'altro', 'nota' => str_repeat('a', 501)])))->toBe([422, 'dati_non_validi'])
        ->and(esitoSospensioneWorkspace(fn () => Api::staff($staff)->delete($percorso)))->toBe([404, 'non_trovato']);

    Api::staff($staff)->post($percorso, ['motivo' => 'altro']);

    expect(esitoSospensioneWorkspace(fn () => Api::staff($staff)->post($percorso, ['motivo' => 'altro'])))->toBe([409, 'gia_sospeso'])
        ->and(esitoSospensioneWorkspace(fn () => Api::staff($staff)->post('/v1/staff/workspace/01k8x5c9y7f2r6q0s4t8v2w6z0/sospensione', ['motivo' => 'altro'])))->toBe([404, 'non_trovato']);
});

it('un workspace sospeso non fa nascere gettoni: gettoni.crea è 403 workspace_sospeso, e torna a farli nascere riattivato (#3261)', function () {
    $staff = gettoneSospensioneWorkspace($this->finto, 'sara@lucianocastro.com');
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $uno = $this->finto->workspace('Studio uno', $anna);
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];

    expect(alFinto('POST', '/v1/gettoni', ['workspace_id' => $uno['id']], $accesso)->status())->toBe(201);
    Api::staff($staff)->post("/v1/staff/workspace/{$uno['id']}/sospensione", ['motivo' => 'altro']);
    $negato = alFinto('POST', '/v1/gettoni', ['workspace_id' => $uno['id']], $accesso);
    expect($negato->status())->toBe(403)->and($negato->json('codice'))->toBe('workspace_sospeso');

    Api::staff($staff)->delete("/v1/staff/workspace/{$uno['id']}/sospensione");
    expect(alFinto('POST', '/v1/gettoni', ['workspace_id' => $uno['id']], $accesso)->status())->toBe(201);
});
