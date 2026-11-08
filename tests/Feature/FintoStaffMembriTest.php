<?php

use Illuminate\Support\Carbon;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Errori\GettoneRifiutato;
use Zeiras\Auth\Testing\BackofficeFinto;

// #3258 (F3.2): `staff.workspace.membri.elenca`, `.modifica` e `.elimina` del finto, per la tab «Membri» di zr-superadmin. Che forme ed errori siano quelli del backoffice lo prova il FintoTest del
// backoffice; qui si prova che il finto tiene le regole: il ruolo, l'ordine degli errori, il proprietario che non si tocca, il cambio che scrive e quello che non scrive, la riga di registro dell'elenco.

beforeEach(function () {
    Carbon::setTestNow('2026-11-07 10:00:00');
    config(['zr-auth.cliente' => BackofficeFinto::CLIENTE, 'zr-auth.segreto' => BackofficeFinto::SEGRETO_DEL_CLIENTE]);
    request()->server->set('REMOTE_ADDR', '203.0.113.7');
    $this->finto = BackofficeFinto::attiva()->ritorno('pm', 'https://board.zeiras.com/ingresso/ritorno');
});

/** Lo stato e il codice di un errore di /v1, o ['ok', null] se riesce. */
function esitoMembri(Closure $chiamata): array
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

function gettoneMembri(BackofficeFinto $finto, string $email, string $ruolo = 'superadmin'): string
{
    $persona = $finto->persona($email, PASSWORD, nome: ucfirst(strstr($email, '@', true)));
    // Dal login normale di Zeiras (#3445) lo staff non entra più con la password e il codice.
    return $finto->ingressoDelloStaff($finto->staffDi($persona, $ruolo, false, false));
}

/** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>} il workspace, Anna proprietaria, Bea amministratrice e Carlo membro */
function workspaceConMembri(BackofficeFinto $finto): array
{
    $anna = $finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $workspace = $finto->workspace('Studio uno', $anna);
    $bea = $finto->persona('bea@example.com', PASSWORD, nome: 'Bea');
    $carlo = $finto->persona('carlo@example.com', PASSWORD, nome: 'Carlo');
    $finto->membro($workspace, $bea, 'amministratore');
    $finto->membro($workspace, $carlo, 'membro');

    return [$workspace, $anna, $bea, $carlo];
}

it('i membri di un workspace: persona, ruolo e creato_il, solo i campi dichiarati; scrive una riga di registro; il supporto legge, la fatturazione no; un id che non c\'è è 404 e non scrive (#3258)', function () {
    $gettone = gettoneMembri($this->finto, 'sara@lucianocastro.com');
    [$workspace, $anna, $bea] = workspaceConMembri($this->finto);
    $registro = count($this->finto->registroDelloStaff());

    $elenco = Api::staff($gettone)->get("/v1/staff/workspace/{$workspace['id']}/membri");

    expect(array_column(array_column($elenco['data'], 'utente'), 'id'))->toContain($anna['id'], $bea['id'])
        ->and(array_keys($elenco['data'][0]))->toBe(['utente', 'ruolo', 'creato_il'])
        ->and(array_keys($elenco['data'][0]['utente']))->toBe(['id', 'nome', 'cognome', 'email'])
        ->and(array_column($elenco['data'], 'ruolo'))->toContain('proprietario', 'amministratore', 'membro')
        ->and($elenco['successivo'])->toBeNull()
        ->and(count($this->finto->registroDelloStaff()))->toBe($registro + 1);
    expect(esitoMembri(fn () => Api::staff(gettoneMembri($this->finto, 'sup@lucianocastro.com', 'supporto'))->get("/v1/staff/workspace/{$workspace['id']}/membri")))->toBe(['ok', null])
        ->and(esitoMembri(fn () => Api::staff(gettoneMembri($this->finto, 'fat@lucianocastro.com', 'fatturazione'))->get("/v1/staff/workspace/{$workspace['id']}/membri")))->toBe([403, 'permesso_negato']);

    $dopo = count($this->finto->registroDelloStaff());
    expect(esitoMembri(fn () => Api::staff($gettone)->get('/v1/staff/workspace/01k8x5c9y7f2r6q0s4t8v2w6z0/membri')))->toBe([404, 'non_trovato'])
        ->and(count($this->finto->registroDelloStaff()))->toBe($dopo);
});

it('l\'elenco è a cursore: due pagine senza righe saltate, e un limite oltre il tetto è 422 (#3258)', function () {
    $gettone = gettoneMembri($this->finto, 'sara@lucianocastro.com');
    [$workspace] = workspaceConMembri($this->finto);

    $prima = Api::staff($gettone)->get("/v1/staff/workspace/{$workspace['id']}/membri", ['limite' => 2]);
    $seconda = Api::staff($gettone)->get("/v1/staff/workspace/{$workspace['id']}/membri", ['limite' => 2, 'cursore' => $prima['successivo']]);

    expect($prima['data'])->toHaveCount(2)->and($seconda['data'])->toHaveCount(1)->and($seconda['successivo'])->toBeNull();
    expect(esitoMembri(fn () => Api::staff($gettone)->get("/v1/staff/workspace/{$workspace['id']}/membri", ['limite' => 51])))->toBe([422, 'dati_non_validi']);
});

it('cambiare il ruolo: solo il superadmin, una riga di registro per un cambio vero e nessuna per lo stesso ruolo; il proprietario è 409 e proprietario nel corpo è 422 (#3258)', function () {
    $gettone = gettoneMembri($this->finto, 'sara@lucianocastro.com');
    [$workspace, $anna, , $carlo] = workspaceConMembri($this->finto);
    $percorso = "/v1/staff/workspace/{$workspace['id']}/membri/{$carlo['id']}";
    $registro = count($this->finto->registroDelloStaff());

    expect(Api::staff($gettone)->patch($percorso, ['ruolo' => 'amministratore'])['data']['ruolo'])->toBe('amministratore')
        ->and(count($this->finto->registroDelloStaff()))->toBe($registro + 1);

    Api::staff($gettone)->patch($percorso, ['ruolo' => 'amministratore']);
    expect(count($this->finto->registroDelloStaff()))->toBe($registro + 1)
        ->and(esitoMembri(fn () => Api::staff(gettoneMembri($this->finto, 'sup@lucianocastro.com', 'supporto'))->patch($percorso, ['ruolo' => 'membro'])))->toBe([403, 'permesso_negato'])
        ->and(esitoMembri(fn () => Api::staff($gettone)->patch($percorso, ['ruolo' => 'proprietario'])))->toBe([422, 'dati_non_validi'])
        ->and(esitoMembri(fn () => Api::staff($gettone)->patch("/v1/staff/workspace/{$workspace['id']}/membri/{$anna['id']}", ['ruolo' => 'membro'])))->toBe([409, 'proprieta_da_trasferire'])
        ->and(esitoMembri(fn () => Api::staff($gettone)->patch("/v1/staff/workspace/{$workspace['id']}/membri/01k8x5c9y7f2r6q0s4t8v2w6z0", ['ruolo' => 'membro'])))->toBe([404, 'non_trovato']);
});

it('togliere un membro: 204, sparisce dall\'elenco, il secondo DELETE è 404, il proprietario è 409 e il supporto 403 (#3258)', function () {
    $gettone = gettoneMembri($this->finto, 'sara@lucianocastro.com');
    [$workspace, $anna, , $carlo] = workspaceConMembri($this->finto);
    $percorso = "/v1/staff/workspace/{$workspace['id']}/membri/{$carlo['id']}";

    expect(esitoMembri(fn () => Api::staff(gettoneMembri($this->finto, 'sup@lucianocastro.com', 'supporto'))->delete($percorso)))->toBe([403, 'permesso_negato'])
        ->and(esitoMembri(fn () => Api::staff($gettone)->delete("/v1/staff/workspace/{$workspace['id']}/membri/{$anna['id']}")))->toBe([409, 'proprieta_da_trasferire'])
        ->and(esitoMembri(fn () => Api::staff($gettone)->delete($percorso)))->toBe(['ok', null])
        ->and(array_column(array_column(Api::staff($gettone)->get("/v1/staff/workspace/{$workspace['id']}/membri")['data'], 'utente'), 'id'))->not->toContain($carlo['id'])
        ->and(esitoMembri(fn () => Api::staff($gettone)->delete($percorso)))->toBe([404, 'non_trovato']);
});
