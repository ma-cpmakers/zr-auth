<?php

use Illuminate\Support\Carbon;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Errori\GettoneRifiutato;
use Zeiras\Auth\Testing\BackofficeFinto;

// #3259 (F3.2) e #3390: `staff.workspace.app.elenca`, `staff.workspace.app.modifica` e `staff.impersonificazioni.elenca` del finto, per il frontend dello staff (zr-superadmin). Che forme ed errori siano quelli
// del backoffice lo prova il FintoTest del backoffice; qui si prova che il finto tiene le regole: il ruolo, il perimetro, l'ordine degli errori, il cambio che scrive e quello che non scrive, lo stato e il cursore.

beforeEach(function () {
    Carbon::setTestNow('2026-11-06 10:00:00');
    config(['zr-auth.cliente' => BackofficeFinto::CLIENTE, 'zr-auth.segreto' => BackofficeFinto::SEGRETO_DEL_CLIENTE]);
    request()->server->set('REMOTE_ADDR', '203.0.113.7');
    $this->finto = BackofficeFinto::attiva()->ritorno('pm', 'https://board.zeiras.com/ingresso/ritorno');
});

/** Lo stato e il codice di un errore di /v1, o ['ok', null] se riesce. */
function esitoAppEAssistenze(Closure $chiamata): array
{
    try {
        $chiamata();
    } catch (ErroreApi $errore) {
        return [$errore->stato, $errore->codice];
    } catch (GettoneRifiutato $rifiutato) {
        return [$rifiutato->errore?->stato, $rifiutato->errore?->codice];
    }

    return ['ok', null];
}

/** Una persona dello staff già entrata: il suo gettone. */
function gettoneAppEAssistenze(BackofficeFinto $finto, string $email, string $ruolo = 'superadmin', bool $soloUat = false): string
{
    $persona = $finto->persona($email, PASSWORD, nome: ucfirst(strstr($email, '@', true)));
    $staff = $finto->staffDi($persona, $ruolo, true, $soloUat);
    // Una persona di prova (`solo_uat`) entra con la password e il codice; le altre con il login normale di Zeiras (#3445).
    if (! $soloUat) {
        return $finto->ingressoDelloStaff($staff);
    }

    $sfida = Api::staff()->post('/v1/staff/accessi', ['email' => $staff['email'], 'password' => PASSWORD])['data']['sfida']['id'];

    return Api::staff()->post('/v1/staff/accessi/verifica', ['sfida' => $sfida, 'codice' => BackofficeFinto::CODICE_TOTP_DELLO_STAFF])['data']['gettone'];
}

it('le app di un workspace: le disponibili in ordine di codice, accese o no; il supporto legge, la fatturazione no; un id che non c\'è è 404 (#3259)', function () {
    $gettone = gettoneAppEAssistenze($this->finto, 'sara@lucianocastro.com');
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $workspace = $this->finto->workspace('Studio uno', $anna);
    $this->finto->attivaApp($workspace, 'pm');

    $elenco = Api::staff($gettone)->get("/v1/staff/workspace/{$workspace['id']}/app");

    expect($elenco)->toBe(['data' => [['codice' => 'crm', 'attiva' => false], ['codice' => 'flow', 'attiva' => false], ['codice' => 'pm', 'attiva' => true]], 'successivo' => null]);
    expect(esitoAppEAssistenze(fn () => Api::staff(gettoneAppEAssistenze($this->finto, 'sup@lucianocastro.com', 'supporto'))->get("/v1/staff/workspace/{$workspace['id']}/app")))->toBe(['ok', null])
        ->and(esitoAppEAssistenze(fn () => Api::staff(gettoneAppEAssistenze($this->finto, 'fat@lucianocastro.com', 'fatturazione'))->get("/v1/staff/workspace/{$workspace['id']}/app")))->toBe([403, 'permesso_negato'])
        ->and(esitoAppEAssistenze(fn () => Api::staff($gettone)->get('/v1/staff/workspace/01k8x5c9y7f2r6q0s4t8v2w6z0/app')))->toBe([404, 'non_trovato']);
});

it('accendere o spegnere un\'app: solo il superadmin, una riga di registro per un cambio vero e nessuna per lo stato che c\'è già (#3259)', function () {
    $gettone = gettoneAppEAssistenze($this->finto, 'sara@lucianocastro.com');
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $workspace = $this->finto->workspace('Studio uno', $anna);
    $percorso = "/v1/staff/workspace/{$workspace['id']}/app/pm";
    $registro = count($this->finto->registroDelloStaff());

    expect(Api::staff($gettone)->patch($percorso, ['attiva' => true]))->toBe(['data' => ['codice' => 'pm', 'attiva' => true]])
        ->and(count($this->finto->registroDelloStaff()))->toBe($registro + 1)
        ->and(Api::staff($gettone)->get("/v1/staff/workspace/{$workspace['id']}/app")['data'][2])->toBe(['codice' => 'pm', 'attiva' => true]);

    Api::staff($gettone)->patch($percorso, ['attiva' => true]);
    expect(count($this->finto->registroDelloStaff()))->toBe($registro + 1);

    Api::staff($gettone)->patch($percorso, ['attiva' => false]);
    expect(count($this->finto->registroDelloStaff()))->toBe($registro + 2)
        ->and(Api::staff($gettone)->get("/v1/staff/workspace/{$workspace['id']}/app")['data'][1]['attiva'])->toBeFalse()
        ->and(esitoAppEAssistenze(fn () => Api::staff(gettoneAppEAssistenze($this->finto, 'sup@lucianocastro.com', 'supporto'))->patch($percorso, ['attiva' => true])))->toBe([403, 'permesso_negato'])
        ->and(esitoAppEAssistenze(fn () => Api::staff($gettone)->patch($percorso, ['attiva' => 'si'])))->toBe([422, 'dati_non_validi'])
        ->and(esitoAppEAssistenze(fn () => Api::staff($gettone)->patch("/v1/staff/workspace/{$workspace['id']}/app/altra", ['attiva' => true])))->toBe([404, 'non_trovato'])
        ->and(esitoAppEAssistenze(fn () => Api::staff($gettone)->patch("/v1/staff/workspace/{$workspace['id']}/app/reports", ['attiva' => true])))->toBe([409, 'app_in_arrivo']);
});

it('le assistenze: aperte di base, dal più recente, i soli campi dichiarati; chiuse e tutte con stato; un altro stato è 422; non scrive nel registro (#3390)', function () {
    $gettone = gettoneAppEAssistenze($this->finto, 'sara@lucianocastro.com');
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $workspace = $this->finto->workspace('Studio uno', $anna);
    $this->finto->attivaApp($workspace, 'pm');
    entraNelFinto('anna@example.com');
    $prima = Api::staff($gettone)->post("/v1/staff/utenti/{$anna['id']}/impersonificazioni", ['app' => 'pm'])['data']['impersonificazione_id'];
    Carbon::setTestNow('2026-11-06 10:05:00');
    $seconda = Api::staff($gettone)->post("/v1/staff/utenti/{$anna['id']}/impersonificazioni", ['app' => 'pm'])['data']['impersonificazione_id'];
    Api::staff($gettone)->delete("/v1/staff/impersonificazioni/{$prima}");
    $registro = count($this->finto->registroDelloStaff());

    $aperte = Api::staff($gettone)->get('/v1/staff/impersonificazioni');
    $riga = $aperte['data'][0];

    expect(array_column($aperte['data'], 'id'))->toBe([$seconda])
        ->and(array_keys($riga))->toBe(['id', 'staff', 'utente', 'app', 'scade_il'])
        ->and($riga['staff'])->toBe(['nome' => 'Sara', 'cognome' => null])->and($riga['utente'])->toBe(['id' => $anna['id'], 'nome' => 'Anna', 'cognome' => null])
        ->and($riga['app'])->toBe('pm')->and($riga['scade_il'])->toBe('2026-11-06T14:05:00.000Z')
        ->and(array_column(Api::staff($gettone)->get('/v1/staff/impersonificazioni', ['stato' => 'chiuse'])['data'], 'id'))->toBe([$prima])
        ->and(array_column(Api::staff($gettone)->get('/v1/staff/impersonificazioni', ['stato' => 'tutte'])['data'], 'id'))->toBe([$seconda, $prima])
        ->and(esitoAppEAssistenze(fn () => Api::staff($gettone)->get('/v1/staff/impersonificazioni', ['stato' => 'altre'])))->toBe([422, 'dati_non_validi'])
        ->and(count($this->finto->registroDelloStaff()))->toBe($registro);

    Carbon::setTestNow('2026-11-06 14:06:00');
    expect(Api::staff(gettoneAppEAssistenze($this->finto, 'sup@lucianocastro.com', 'supporto'))->get('/v1/staff/impersonificazioni')['data'])->toBe([]);
});

it('le assistenze: il cursore, il ruolo e il perimetro solo_uat (#3390)', function () {
    $gettone = gettoneAppEAssistenze($this->finto, 'sara@lucianocastro.com');
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $uat = $this->finto->persona('uat+prova@castroandpartners.com', PASSWORD, nome: 'Uat');
    foreach ([$anna, $uat] as $persona) {
        $this->finto->attivaApp($this->finto->workspace('Studio '.$persona['nome'], $persona), 'pm');
        entraNelFinto($persona['email']);
    }

    $ids = [];
    foreach ([$anna, $uat, $anna] as $i => $persona) {
        Carbon::setTestNow('2026-11-06 10:0'.$i.':00');
        $ids[] = Api::staff($gettone)->post("/v1/staff/utenti/{$persona['id']}/impersonificazioni", ['app' => 'pm'])['data']['impersonificazione_id'];
    }

    $prima = Api::staff($gettone)->get('/v1/staff/impersonificazioni', ['limite' => 2]);
    $seconda = Api::staff($gettone)->get('/v1/staff/impersonificazioni', ['limite' => 2, 'cursore' => $prima['successivo']]);

    expect(array_column($prima['data'], 'id'))->toBe([$ids[2], $ids[1]])->and(array_column($seconda['data'], 'id'))->toBe([$ids[0]])->and($seconda['successivo'])->toBeNull()
        ->and(esitoAppEAssistenze(fn () => Api::staff($gettone)->get('/v1/staff/impersonificazioni', ['stato' => 'tutte', 'limite' => 2, 'cursore' => $prima['successivo']])))->toBe([422, 'dati_non_validi'])
        ->and(esitoAppEAssistenze(fn () => Api::staff($gettone)->get('/v1/staff/impersonificazioni', ['limite' => 51])))->toBe([422, 'dati_non_validi'])
        ->and(esitoAppEAssistenze(fn () => Api::staff(gettoneAppEAssistenze($this->finto, 'fat@lucianocastro.com', 'fatturazione'))->get('/v1/staff/impersonificazioni')))->toBe([403, 'permesso_negato'])
        ->and(array_column(Api::staff(gettoneAppEAssistenze($this->finto, 'uat+staff@castroandpartners.com', 'supporto', true))->get('/v1/staff/impersonificazioni')['data'], 'id'))->toBe([$ids[1]]);
});
