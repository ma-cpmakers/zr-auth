<?php

use Illuminate\Support\Carbon;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Errori\GettoneRifiutato;
use Zeiras\Auth\Testing\BackofficeFinto;

// #3257 (F3c): `staff.workspace.modifica` del finto, per la scheda del workspace di zr-superadmin. Che forme ed errori siano quelli del backoffice lo prova il FintoTest del backoffice; qui si prova che il
// finto tiene le regole: il ruolo, l'ordine degli errori, il cambio che scrive e quello che non scrive, l'indirizzo lasciato che resta riservato e non si riassegna.

beforeEach(function () {
    Carbon::setTestNow('2026-11-07 10:00:00');
    config(['zr-auth.cliente' => BackofficeFinto::CLIENTE, 'zr-auth.segreto' => BackofficeFinto::SEGRETO_DEL_CLIENTE]);
    request()->server->set('REMOTE_ADDR', '203.0.113.7');
    $this->finto = BackofficeFinto::attiva()->ritorno('pm', 'https://board.zeiras.com/ingresso/ritorno');
});

/** Lo stato e il codice di un errore di /v1, o ['ok', null] se riesce. */
function esitoModifica(Closure $chiamata): array
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

function gettoneModifica(BackofficeFinto $finto, string $email, string $ruolo = 'superadmin'): string
{
    $persona = $finto->persona($email, PASSWORD, nome: ucfirst(strstr($email, '@', true)));

    return $finto->ingressoDelloStaff($finto->staffDi($persona, $ruolo, false, false));
}

/** @return array<string, mixed> un workspace con la sua proprietaria */
function workspaceDaModificare(BackofficeFinto $finto, string $nome = 'Studio uno', string $email = 'anna@example.com'): array
{
    return $finto->workspace($nome, $finto->persona($email, PASSWORD, nome: 'Anna'));
}

it('cambia nome e indirizzo, risponde con la scheda e scrive una riga di registro coi nomi dei campi; lo stesso valore non scrive niente (#3257)', function () {
    $gettone = gettoneModifica($this->finto, 'sara@lucianocastro.com');
    $workspace = workspaceDaModificare($this->finto);
    $registro = count($this->finto->registroDelloStaff());

    $risposta = Api::staff($gettone)->patch("/v1/staff/workspace/{$workspace['id']}", ['nome' => 'Studio Rossi', 'indirizzo' => 'studio-rossi'])['data'];

    expect($risposta['nome'])->toBe('Studio Rossi')->and($risposta['indirizzo'])->toBe('studio-rossi')->and($risposta)->toHaveKeys(['consumi', 'eliminazione', 'fatturazione'])
        ->and(count($this->finto->registroDelloStaff()))->toBe($registro + 1);

    Api::staff($gettone)->patch("/v1/staff/workspace/{$workspace['id']}", ['nome' => 'Studio Rossi', 'indirizzo' => 'studio-rossi']);
    expect(count($this->finto->registroDelloStaff()))->toBe($registro + 1);
});

it('solo il superadmin; un id che non c\'è è 404; il corpo è 422 se vuoto, estraneo, con un valore vuoto, null o di forma sbagliata (#3257)', function () {
    $gettone = gettoneModifica($this->finto, 'sara@lucianocastro.com');
    $workspace = workspaceDaModificare($this->finto);
    $percorso = "/v1/staff/workspace/{$workspace['id']}";

    expect(esitoModifica(fn () => Api::staff(gettoneModifica($this->finto, 'sup@lucianocastro.com', 'supporto'))->patch($percorso, ['nome' => 'X1'])))->toBe([403, 'permesso_negato'])
        ->and(esitoModifica(fn () => Api::staff(gettoneModifica($this->finto, 'fat@lucianocastro.com', 'fatturazione'))->patch($percorso, ['nome' => 'X1'])))->toBe([403, 'permesso_negato'])
        ->and(esitoModifica(fn () => Api::staff($gettone)->patch('/v1/staff/workspace/01k8x5c9y7f2r6q0s4t8v2w6z0', ['nome' => 'X1'])))->toBe([404, 'non_trovato']);

    foreach ([[], ['colore' => 'rosso'], ['nome' => ''], ['nome' => null], ['indirizzo' => null], ['indirizzo' => 'Maiuscole'], ['indirizzo' => 'ab'], ['indirizzo' => 'doppio--trattino'], ['indirizzo' => str_repeat('a', 48)]] as $corpo) {
        expect(esitoModifica(fn () => Api::staff($gettone)->patch($percorso, $corpo)))->toBe([422, 'dati_non_validi']);
    }
});

it('un indirizzo di un altro workspace è 409; quello lasciato resta riservato e non lo prende nessun altro, ma chi lo ha lasciato lo riprende (#3257)', function () {
    $gettone = gettoneModifica($this->finto, 'sara@lucianocastro.com');
    $uno = workspaceDaModificare($this->finto);
    $due = workspaceDaModificare($this->finto, 'Studio due', 'bea@example.com');

    expect(esitoModifica(fn () => Api::staff($gettone)->patch("/v1/staff/workspace/{$uno['id']}", ['indirizzo' => $due['slug']])))->toBe([409, 'indirizzo_gia_preso']);

    Api::staff($gettone)->patch("/v1/staff/workspace/{$uno['id']}", ['indirizzo' => 'nuovo-indirizzo']);

    expect(esitoModifica(fn () => Api::staff($gettone)->patch("/v1/staff/workspace/{$due['id']}", ['indirizzo' => $uno['slug']])))->toBe([409, 'indirizzo_gia_preso'])
        ->and(esitoModifica(fn () => Api::staff($gettone)->patch("/v1/staff/workspace/{$uno['id']}", ['indirizzo' => $uno['slug']])))->toBe(['ok', null]);
});
