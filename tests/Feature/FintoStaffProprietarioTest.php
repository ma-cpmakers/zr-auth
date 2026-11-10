<?php

use Illuminate\Support\Carbon;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Errori\GettoneRifiutato;
use Zeiras\Auth\Testing\BackofficeFinto;

// #3260 (F3f): `staff.workspace.proprietario.modifica` del finto, per il passaggio di proprietà di zr-superadmin. Che forme ed errori siano quelli del backoffice lo prova il FintoTest del
// backoffice; qui si prova che il finto tiene le regole: il motivo dell'elenco chiuso, l'ordine degli errori, un proprietario solo, la riga di registro col motivo, l'eliminazione programmata.

beforeEach(function () {
    Carbon::setTestNow('2026-11-08 10:00:00');
    config(['zr-auth.cliente' => BackofficeFinto::CLIENTE, 'zr-auth.segreto' => BackofficeFinto::SEGRETO_DEL_CLIENTE]);
    request()->server->set('REMOTE_ADDR', '203.0.113.7');
    $this->finto = BackofficeFinto::attiva()->ritorno('pm', 'https://board.zeiras.com/ingresso/ritorno');
});

/** @return array{0: int|null, 1: string|null, 2: string|null} lo stato, il codice e il primo `pointer` di un errore di /v1, o [null, null, null] se riesce. */
function esitoProprietario(Closure $chiamata): array
{
    try {
        $chiamata();
    } catch (ErroreApi $errore) {
        return [$errore->stato, $errore->codice, $errore->errori[0]['pointer'] ?? null];
    } catch (GettoneRifiutato $rifiutato) {
        return [$rifiutato->errore?->stato, $rifiutato->errore?->codice, null];
    }

    return [null, null, null];
}

function gettoneProprietario(BackofficeFinto $finto, string $email, string $ruolo = 'superadmin'): string
{
    $persona = $finto->persona($email, PASSWORD, nome: ucfirst(strstr($email, '@', true)));

    return $finto->ingressoDelloStaff($finto->staffDi($persona, $ruolo, false, false));
}

/** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>} il workspace, Anna proprietaria, Bea amministratrice e Carlo membro */
function workspaceDelPassaggio(BackofficeFinto $finto): array
{
    $anna = $finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $workspace = $finto->workspace('Studio uno', $anna);
    $bea = $finto->persona('bea@example.com', PASSWORD, nome: 'Bea');
    $carlo = $finto->persona('carlo@example.com', PASSWORD, nome: 'Carlo');
    $finto->membro($workspace, $bea, 'amministratore');
    $finto->membro($workspace, $carlo, 'membro');

    return [$workspace, $anna, $bea, $carlo];
}

it('passa la proprietà: 200 col nuovo proprietario, il vecchio diventa amministratore, una riga di registro col motivo e i campi (#3260)', function () {
    $gettone = gettoneProprietario($this->finto, 'sara@lucianocastro.com');
    [$workspace, $anna, , $carlo] = workspaceDelPassaggio($this->finto);
    $registro = count($this->finto->registroDelloStaff());
    $percorso = "/v1/staff/workspace/{$workspace['id']}/proprietario";

    $risposta = Api::staff($gettone)->patch($percorso, ['utente' => $carlo['id'], 'motivo' => 'assistenza']);

    expect(array_keys($risposta['data']))->toBe(['utente', 'ruolo', 'creato_il'])
        ->and($risposta['data']['utente']['id'])->toBe($carlo['id'])->and($risposta['data']['ruolo'])->toBe('proprietario');
    $ruoli = array_column(Api::staff($gettone)->get("/v1/staff/workspace/{$workspace['id']}/membri")['data'], 'ruolo', 'utente.id');
    $perId = [];
    foreach (Api::staff($gettone)->get("/v1/staff/workspace/{$workspace['id']}/membri")['data'] as $voce) {
        $perId[$voce['utente']['id']] = $voce['ruolo'];
    }
    $riga = $this->finto->registroDelloStaff()[$registro];

    expect($ruoli)->not->toBeEmpty()
        ->and($perId[$anna['id']])->toBe('amministratore')->and($perId[$carlo['id']])->toBe('proprietario')
        ->and(array_count_values($perId)['proprietario'])->toBe(1)
        ->and($riga['azione'])->toBe('staff.workspace.proprietario.modifica')->and($riga['campi'])->toBe(['proprietario'])->and($riga['motivo'])->toBe('assistenza');
});

it('il motivo è dell\'elenco chiuso e obbligatorio, l\'utente pure: 422 dati_non_validi e niente scritto; un campo estraneo è 422 (#3260)', function () {
    $gettone = gettoneProprietario($this->finto, 'sara@lucianocastro.com');
    [$workspace, , , $carlo] = workspaceDelPassaggio($this->finto);
    $percorso = "/v1/staff/workspace/{$workspace['id']}/proprietario";
    $registro = count($this->finto->registroDelloStaff());

    expect(esitoProprietario(fn () => Api::staff($gettone)->patch($percorso, ['utente' => $carlo['id']])))->toBe([422, 'dati_non_validi', '#/motivo'])
        ->and(esitoProprietario(fn () => Api::staff($gettone)->patch($percorso, ['utente' => $carlo['id'], 'motivo' => 'perché sì'])))->toBe([422, 'dati_non_validi', '#/motivo'])
        ->and(esitoProprietario(fn () => Api::staff($gettone)->patch($percorso, ['motivo' => 'assistenza'])))->toBe([422, 'dati_non_validi', '#/utente'])
        ->and(esitoProprietario(fn () => Api::staff($gettone)->patch($percorso, ['utente' => $carlo['id'], 'motivo' => 'assistenza', 'ruolo' => 'x']))[0])->toBe(422)
        ->and(count($this->finto->registroDelloStaff()))->toBe($registro);
});

it('chi non è membro (inventato, di un altro workspace, con la richiesta di eliminare l\'account) è 422 su #/utente; chi è già proprietario pure, anche al secondo colpo (#3260)', function () {
    $gettone = gettoneProprietario($this->finto, 'sara@lucianocastro.com');
    [$workspace, $anna, , $carlo] = workspaceDelPassaggio($this->finto);
    $estraneo = $this->finto->persona('dora@example.com', PASSWORD, nome: 'Dora');
    $this->finto->workspace('Studio due', $estraneo);
    $percorso = "/v1/staff/workspace/{$workspace['id']}/proprietario";
    $registro = count($this->finto->registroDelloStaff());
    $chiama = fn (string $utente) => esitoProprietario(fn () => Api::staff($gettone)->patch($percorso, ['utente' => $utente, 'motivo' => 'sicurezza']));

    expect($chiama('01k8x5c9y7f2r6q0s4t8v2w6zz'))->toBe([422, 'dati_non_validi', '#/utente'])
        ->and($chiama($estraneo['id']))->toBe([422, 'dati_non_validi', '#/utente'])
        ->and($chiama($anna['id']))->toBe([422, 'dati_non_validi', '#/utente'])
        ->and(count($this->finto->registroDelloStaff()))->toBe($registro);

    Api::staff($gettone)->patch($percorso, ['utente' => $carlo['id'], 'motivo' => 'sicurezza']);

    expect($chiama($carlo['id']))->toBe([422, 'dati_non_validi', '#/utente']);
});

it('un workspace con l\'eliminazione programmata è 409 workspace_in_eliminazione; un workspace che non c\'è è 404 (#3260)', function () {
    $gettone = gettoneProprietario($this->finto, 'sara@lucianocastro.com');
    [$workspace, $anna, , $carlo] = workspaceDelPassaggio($this->finto);
    // Anna ha un altro workspace: lasciare l'ultimo è 409 ultimo_workspace.
    $this->finto->workspace('Resta', $anna);
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];
    $gettoneAnna = alFinto('POST', '/v1/gettoni', ['workspace_id' => $workspace['id']], $accesso)->json('data.gettone');
    expect(alFinto('DELETE', '/v1/workspace', gettone: $gettoneAnna)->status())->toBe(202);
    $corpo = ['utente' => $carlo['id'], 'motivo' => 'assistenza'];

    expect(esitoProprietario(fn () => Api::staff($gettone)->patch("/v1/staff/workspace/{$workspace['id']}/proprietario", $corpo)))->toBe([409, 'workspace_in_eliminazione', null])
        ->and(esitoProprietario(fn () => Api::staff($gettone)->patch('/v1/staff/workspace/01k8x5c9y7f2r6q0s4t8v2w6z0/proprietario', $corpo))[0])->toBe(404);
});

it('è del solo superadmin: supporto e fatturazione sono 403 permesso_negato e niente cambia (#3260)', function () {
    [$workspace, , , $carlo] = workspaceDelPassaggio($this->finto);
    $gettoni = [];
    foreach (['supporto', 'fatturazione'] as $ruolo) {
        $gettoni[$ruolo] = gettoneProprietario($this->finto, "{$ruolo}@lucianocastro.com", $ruolo);
    }
    $registro = count($this->finto->registroDelloStaff());
    $percorso = "/v1/staff/workspace/{$workspace['id']}/proprietario";

    foreach ($gettoni as $ruolo => $gettone) {
        expect(esitoProprietario(fn () => Api::staff($gettone)->patch($percorso, ['utente' => $carlo['id'], 'motivo' => 'assistenza'])))->toBe([403, 'permesso_negato', null]);
    }

    expect(count($this->finto->registroDelloStaff()))->toBe($registro);
});

it('un ospite non diventa proprietario: 422 su #/utente, col testo dell\'ospite, e i ruoli e il registro restano com\'erano (#3366)', function () {
    $gettone = gettoneProprietario($this->finto, 'sara@lucianocastro.com');
    [$workspace] = workspaceDelPassaggio($this->finto);
    $olga = $this->finto->persona('olga@example.com', PASSWORD, nome: 'Olga');
    $this->finto->membro($workspace, $olga, 'ospite');
    $registro = count($this->finto->registroDelloStaff());

    try {
        Api::staff($gettone)->patch("/v1/staff/workspace/{$workspace['id']}/proprietario", ['utente' => $olga['id'], 'motivo' => 'sicurezza']);
        $errore = null;
    } catch (ErroreApi $e) {
        $errore = $e;
    }

    expect($errore)->not->toBeNull()
        ->and([$errore->stato, $errore->codice, $errore->errori[0]['pointer'], $errore->errori[0]['regola']])->toBe([422, 'dati_non_validi', '#/utente', 'valore_non_ammesso'])
        ->and($errore->errori[0]['detail'])->toContain('A guest is view-only')
        ->and(count($this->finto->registroDelloStaff()))->toBe($registro);
});
