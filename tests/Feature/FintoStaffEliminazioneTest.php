<?php

use Illuminate\Support\Carbon;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Testing\BackofficeFinto;

// #3263 (F3i): `staff.workspace.eliminazione.crea` e `.elimina` del finto, per l'eliminazione di un workspace di zr-superadmin. Che forme ed errori siano quelli del backoffice lo prova il FintoTest
// del backoffice; qui si prova che il finto tiene le regole: il motivo dell'elenco chiuso, 202 con la data a 30 giorni, la seconda chiamata che non scrive, l'annullamento, la riga di registro.

beforeEach(function () {
    Carbon::setTestNow('2026-11-14 10:00:00');
    config(['zr-auth.cliente' => BackofficeFinto::CLIENTE, 'zr-auth.segreto' => BackofficeFinto::SEGRETO_DEL_CLIENTE]);
    request()->server->set('REMOTE_ADDR', '203.0.113.7');
    $this->finto = BackofficeFinto::attiva()->ritorno('pm', 'https://board.zeiras.com/ingresso/ritorno');
});

/** @return array{0: int|null, 1: string|null, 2: string|null} lo stato, il codice e il primo `pointer` di un errore di /v1, o [null, null, null] se riesce. */
function esitoEliminazione(Closure $chiamata): array
{
    try {
        $chiamata();
    } catch (ErroreApi $errore) {
        return [$errore->stato, $errore->codice, $errore->errori[0]['pointer'] ?? null];
    }

    return [null, null, null];
}

function gettoneEliminazione(BackofficeFinto $finto, string $ruolo = 'superadmin', string $email = 'sara@lucianocastro.com'): string
{
    $persona = $finto->persona($email, PASSWORD, nome: ucfirst(strstr($email, '@', true)));

    return $finto->ingressoDelloStaff($finto->staffDi($persona, $ruolo, false, false));
}

/** @return array<string, mixed> un workspace con la sua unica proprietaria */
function workspaceDaEliminare(BackofficeFinto $finto): array
{
    return $finto->workspace('Studio uno', $finto->persona('anna@example.com', PASSWORD, nome: 'Anna'));
}

it('pianifica a 30 giorni: 202 con la data, la scheda dice in_eliminazione, una riga di registro col motivo; la seconda chiamata risponde uguale e non scrive (#3263)', function () {
    $gettone = gettoneEliminazione($this->finto);
    $workspace = workspaceDaEliminare($this->finto);
    $percorso = "/v1/staff/workspace/{$workspace['id']}/eliminazione";
    $registro = count($this->finto->registroDelloStaff());

    $risposta = Api::staff($gettone)->post($percorso, ['motivo' => 'assistenza']);

    expect($risposta)->toBe(['data' => ['eliminazione_il' => '2026-12-14T10:00:00.000Z']]);
    $riga = $this->finto->registroDelloStaff()[$registro];
    expect($riga['azione'])->toBe('staff.workspace.eliminazione.crea')->and($riga['motivo'])->toBe('assistenza')->and($riga['bersaglio_id'])->toBe($workspace['id'])
        ->and(Api::staff($gettone)->get("/v1/staff/workspace/{$workspace['id']}")['data']['stato'])->toBe('in_eliminazione');

    Carbon::setTestNow('2026-11-14 10:10:00');
    $dopo = count($this->finto->registroDelloStaff());

    expect(Api::staff($gettone)->post($percorso, ['motivo' => 'sicurezza']))->toBe($risposta)
        ->and(count($this->finto->registroDelloStaff()))->toBe($dopo);
});

it('annulla: 204, il workspace torna attivo, una riga di registro; senza eliminazione è 404, anche la seconda volta; si può pianificare di nuovo (#3263)', function () {
    $gettone = gettoneEliminazione($this->finto);
    $workspace = workspaceDaEliminare($this->finto);
    $percorso = "/v1/staff/workspace/{$workspace['id']}/eliminazione";

    expect(esitoEliminazione(fn () => Api::staff($gettone)->delete($percorso))[0])->toBe(404);
    Api::staff($gettone)->post($percorso, ['motivo' => 'assistenza']);
    $registro = count($this->finto->registroDelloStaff());
    Api::staff($gettone)->delete($percorso);

    expect($this->finto->registroDelloStaff()[$registro]['azione'])->toBe('staff.workspace.eliminazione.elimina')
        ->and(Api::staff($gettone)->get("/v1/staff/workspace/{$workspace['id']}")['data']['stato'])->toBe('attivo')
        ->and(esitoEliminazione(fn () => Api::staff($gettone)->delete($percorso))[0])->toBe(404)
        ->and(Api::staff($gettone)->post($percorso, ['motivo' => 'assistenza'])['data']['eliminazione_il'])->toBe('2026-12-14T10:00:00.000Z');
});

it('il motivo è dell\'elenco chiuso e obbligatorio: 422 dati_non_validi su #/motivo e niente scritto; un campo estraneo è 422; un workspace che non c\'è è 404 (#3263)', function () {
    $gettone = gettoneEliminazione($this->finto);
    $workspace = workspaceDaEliminare($this->finto);
    $percorso = "/v1/staff/workspace/{$workspace['id']}/eliminazione";
    $registro = count($this->finto->registroDelloStaff());

    expect(esitoEliminazione(fn () => Api::staff($gettone)->post($percorso, [])))->toBe([422, 'dati_non_validi', '#/motivo'])
        ->and(esitoEliminazione(fn () => Api::staff($gettone)->post($percorso, ['motivo' => 'perché sì'])))->toBe([422, 'dati_non_validi', '#/motivo'])
        ->and(esitoEliminazione(fn () => Api::staff($gettone)->post($percorso, ['motivo' => 'assistenza', 'altro' => 1]))[0])->toBe(422)
        ->and(esitoEliminazione(fn () => Api::staff($gettone)->post('/v1/staff/workspace/01k8x5c9y7f2r6q0s4t8v2w6z0/eliminazione', ['motivo' => 'assistenza']))[0])->toBe(404)
        ->and(count($this->finto->registroDelloStaff()))->toBe($registro);
});

it('solo il superadmin: supporto e fatturazione sono 403 permesso_negato, senza gettone 401 (#3263)', function () {
    $workspace = workspaceDaEliminare($this->finto);
    $percorso = "/v1/staff/workspace/{$workspace['id']}/eliminazione";

    foreach (['supporto' => 'supp@lucianocastro.com', 'fatturazione' => 'fatt@lucianocastro.com'] as $ruolo => $email) {
        $gettone = gettoneEliminazione($this->finto, $ruolo, $email);

        expect(esitoEliminazione(fn () => Api::staff($gettone)->post($percorso, ['motivo' => 'assistenza'])))->toBe([403, 'permesso_negato', null])
            ->and(esitoEliminazione(fn () => Api::staff($gettone)->delete($percorso))[0])->toBe(403);
    }
});

it('conta come azione sensibile: oltre 30 all\'ora è 429 senza la riga di registro (#3263)', function () {
    $gettone = gettoneEliminazione($this->finto);
    $workspace = workspaceDaEliminare($this->finto);
    $percorso = "/v1/staff/workspace/{$workspace['id']}/eliminazione";

    for ($i = 0; $i < 15; $i++) {
        Api::staff($gettone)->post($percorso, ['motivo' => 'assistenza']);
        Api::staff($gettone)->delete($percorso);
    }

    $registro = count($this->finto->registroDelloStaff());

    expect(esitoEliminazione(fn () => Api::staff($gettone)->post($percorso, ['motivo' => 'assistenza']))[0])->toBe(429)
        ->and(count($this->finto->registroDelloStaff()))->toBe($registro);
});
