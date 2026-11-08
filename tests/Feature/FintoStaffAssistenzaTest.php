<?php

use Illuminate\Support\Carbon;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Errori\GettoneRifiutato;
use Zeiras\Auth\Testing\BackofficeFinto;

// #2914 (F4): «Accedi come» nel finto, per il frontend dello staff (zr-superadmin) e per le app che lo ricevono. Che forme ed errori siano quelli del backoffice lo prova
// il FintoTest del backoffice; qui si prova che il finto tiene le regole: il codice senza verificatore e solo per il client dell'app, il gettone di assistenza che apre meno
// di una persona e si ferma dopo trenta minuti, l'utente che non ne sa niente, il registro dello staff con l'assistenza, e le due chiusure.

beforeEach(function () {
    Carbon::setTestNow('2026-11-06 10:00:00');
    config(['zr-auth.cliente' => BackofficeFinto::CLIENTE, 'zr-auth.segreto' => BackofficeFinto::SEGRETO_DEL_CLIENTE]);
    request()->server->set('REMOTE_ADDR', '203.0.113.7');
    $this->finto = BackofficeFinto::attiva()->ritorno('pm', 'https://board.zeiras.com/ingresso/ritorno');
});

/** Una persona già entrata, in un workspace con l'app `pm` accesa. @return array{persona: array<string, mixed>, workspace: array<string, mixed>, gettone: string} */
function giuliaDaAssistere(BackofficeFinto $finto, string $email = 'giulia@example.com'): array
{
    $persona = $finto->persona($email, PASSWORD, nome: 'Giulia');
    $workspace = $finto->workspace('Studio', $persona);
    $finto->attivaApp($workspace, 'pm');
    $accesso = entraNelFinto($email);

    return ['persona' => $persona, 'workspace' => $workspace, 'gettone' => $accesso['gettone']['gettone'] ?? $accesso['gettone']];
}

/** Il gettone di assistenza di una persona: lo staff crea il codice, il client della board lo scambia senza verificatore. @return array{gettone: string, impersonificazione_id: string} */
function entraComeAssistenza(string $gettoneDelloStaff, string $persona, ?string $workspace = null): array
{
    $corpo = ['app' => 'pm'] + ($workspace === null ? [] : ['workspace_id' => $workspace]);
    $assistenza = Api::staff($gettoneDelloStaff)->post("/v1/staff/utenti/{$persona}/impersonificazioni", $corpo)['data'];
    $scambio = scambioDelFinto(['codice' => $assistenza['codice']], 'board');
    expect($scambio->status())->toBe(201);

    return ['gettone' => $scambio->json('data.gettone'), 'impersonificazione_id' => $assistenza['impersonificazione_id']];
}

/** Lo stato e il codice dell'errore di una chiamata dello staff, o ['ok', null]. */
function esitoAssistenza(Closure $chiamata): array
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
function gettoneDelloStaffPerAssistenza(BackofficeFinto $finto, string $email, string $ruolo = 'superadmin'): string
{
    return gettoneDellaPersonaDelloStaff($finto, $finto->persona($email, PASSWORD, nome: ucfirst(strstr($email, '@', true))), $ruolo);
}

/** @param  array<string, mixed>  $persona una persona del finto */
function gettoneDellaPersonaDelloStaff(BackofficeFinto $finto, array $persona, string $ruolo = 'superadmin'): string
{
    $staff = $finto->staffDi($persona, $ruolo, true);
    return $finto->ingressoDelloStaff($staff);
}

it('il codice: la forma della risposta, 60 secondi, e lo scambio senza verificatore dà il gettone del workspace (F4.1)', function () {
    $staff = gettoneDelloStaffPerAssistenza($this->finto, 'sara@lucianocastro.com');
    $giulia = giuliaDaAssistere($this->finto);

    $assistenza = Api::staff($staff)->post("/v1/staff/utenti/{$giulia['persona']['id']}/impersonificazioni", ['app' => 'pm', 'motivo' => 'Non vede le schede.'])['data'];

    expect(array_keys($assistenza))->toBe(['impersonificazione_id', 'codice', 'ritorno', 'scade_il'])
        ->and($assistenza['ritorno'])->toBe('https://board.zeiras.com/ingresso/ritorno')
        ->and($assistenza['scade_il'])->toBe('2026-11-06T10:01:00.000Z');

    $scambio = scambioDelFinto(['codice' => $assistenza['codice']], 'board');

    expect($scambio->status())->toBe(201)
        ->and($scambio->json('data.workspace.id'))->toBe($giulia['workspace']['id'])
        ->and($scambio->json('data.ruolo'))->toBe('proprietario')
        ->and($scambio->json('data.utente.email'))->toBe('giulia@example.com')
        ->and($scambio->json('data.scade_il'))->toBe('2026-11-06T14:00:00.000Z');
});

it('il codice lo scambia solo il client dell\'app, una volta, e mai con un verificatore (F4.1)', function () {
    $staff = gettoneDelloStaffPerAssistenza($this->finto, 'sara@lucianocastro.com');
    $giulia = giuliaDaAssistere($this->finto);
    $nuovo = fn () => Api::staff($staff)->post("/v1/staff/utenti/{$giulia['persona']['id']}/impersonificazioni", ['app' => 'pm'])['data']['codice'];

    foreach ([['board', ['verificatore' => str_repeat('a', 43)]], ['crm', []], ['home', []], [null, []], ['uat', []]] as [$cliente, $altro]) {
        $respinto = scambioDelFinto(['codice' => $nuovo()] + $altro, $cliente);

        expect($respinto->status())->toBe(422)->and($respinto->json('codice'))->toBe('verifica_non_riuscita');
    }

    $codice = $nuovo();
    expect(scambioDelFinto(['codice' => $codice], 'board')->status())->toBe(201)
        ->and(scambioDelFinto(['codice' => $codice], 'board')->json('codice'))->toBe('verifica_non_riuscita');

    $scaduto = $nuovo();
    Carbon::setTestNow('2026-11-06 10:01:01');
    expect(scambioDelFinto(['codice' => $scaduto], 'board')->json('codice'))->toBe('verifica_non_riuscita');
});

it('accessi.corrente.mostra: la fascia ha la persona assistita e mai chi la assiste, e ogni altro gettone ha null (F4.5)', function () {
    $staff = gettoneDelloStaffPerAssistenza($this->finto, 'sara@lucianocastro.com');
    $giulia = giuliaDaAssistere($this->finto);
    $assistenza = entraComeAssistenza($staff, $giulia['persona']['id']);

    $fascia = alFinto('GET', '/v1/accessi/corrente', null, $assistenza['gettone'])->json('data');

    expect(array_keys($fascia))->toBe(['accesso_id', 'scade_il', 'impersonificazione'])
        ->and($fascia['scade_il'])->toBe('2026-11-06T14:00:00.000Z')
        ->and($fascia['impersonificazione'])->toBe([
            'id' => $assistenza['impersonificazione_id'],
            'scade_il' => '2026-11-06T14:00:00.000Z',
            'sola_lettura' => false,
            'utente' => ['nome' => 'Giulia', 'cognome' => null, 'email' => 'giulia@example.com'],
        ])
        ->and(json_encode($fascia))->not->toContain('sara');

    $sua = alFinto('GET', '/v1/accessi/corrente', null, $giulia['gettone']);
    expect($sua->status())->toBe(200)->and($sua->json('data.impersonificazione'))->toBeNull();
});

it('la persona non vede l\'assistenza: non è fra i suoi accessi, e l\'anagrafica dello staff non la conta come un ingresso suo (F4, D5, D6)', function () {
    $staff = gettoneDelloStaffPerAssistenza($this->finto, 'sara@lucianocastro.com');
    $giulia = giuliaDaAssistere($this->finto);
    $marco = $this->finto->persona('marco@example.com', PASSWORD, nome: 'Marco');
    $this->finto->workspace('Altro', $marco);

    $prima = alFinto('GET', '/v1/accessi', null, $giulia['gettone'])->json('data');
    entraComeAssistenza($staff, $giulia['persona']['id']);
    $dopo = alFinto('GET', '/v1/accessi', null, $giulia['gettone'])->json('data');

    expect($dopo)->toBe($prima);
    // Marco non è mai entrato: un'assistenza non gli dà un accesso suo.
    expect(esitoAssistenza(fn () => Api::staff($staff)->post("/v1/staff/utenti/{$marco['id']}/impersonificazioni", ['app' => 'pm'])))->toBe([409, 'utente_mai_acceduto']);
});

it('i metodi: il gettone di assistenza apre la lettura, nega con 403 azione_non_consentita_in_assistenza il resto, e il supporto non scrive (F4.3)', function () {
    $staff = gettoneDelloStaffPerAssistenza($this->finto, 'sara@lucianocastro.com');
    $supporto = gettoneDelloStaffPerAssistenza($this->finto, 'sam@lucianocastro.com', 'supporto');
    $giulia = giuliaDaAssistere($this->finto);

    $comeSuperadmin = entraComeAssistenza($staff, $giulia['persona']['id'])['gettone'];
    $comeSupporto = entraComeAssistenza($supporto, $giulia['persona']['id'])['gettone'];

    foreach ([$comeSuperadmin, $comeSupporto] as $gettone) {
        expect(alFinto('GET', '/v1/io', null, $gettone)->status())->toBe(200);

        foreach ([['PATCH', '/v1/io', ['nome' => 'Altra']], ['POST', '/v1/gettoni', ['workspace_id' => $giulia['workspace']['id']]], ['POST', '/v1/io/esportazioni', null], ['DELETE', '/v1/accessi/altri', null]] as [$verbo, $percorso, $corpo]) {
            $negato = alFinto($verbo, $percorso, $corpo, $gettone);

            expect($negato->status())->toBe(403)->and($negato->json('codice'))->toBe('azione_non_consentita_in_assistenza');
        }
    }

    // La scrittura di contenuto: al superadmin sì, al supporto (sola lettura) no, prima ancora del corpo.
    $scrittura = fn (string $gettone) => alFinto('POST', '/v1/crm/persone', ['nome' => 'Mara'], $gettone)->json('codice');

    expect($scrittura($comeSupporto))->toBe('azione_non_consentita_in_assistenza')
        ->and($scrittura($comeSuperadmin))->not->toBe('azione_non_consentita_in_assistenza');
});

it('uno staff con un gettone di assistenza non apre lo staff, e un gettone dello staff non apre le app (F4.3)', function () {
    $staff = gettoneDelloStaffPerAssistenza($this->finto, 'sara@lucianocastro.com');
    $giulia = giuliaDaAssistere($this->finto);
    $assistenza = entraComeAssistenza($staff, $giulia['persona']['id']);

    expect(esitoAssistenza(fn () => Api::staff($assistenza['gettone'])->get('/v1/staff/utenti')))->toBe([403, 'permesso_negato'])
        ->and(alFinto('GET', '/v1/io', null, $staff)->status())->toBe(401);
});

it('ogni chiamata ammessa è una riga del registro dello staff, con la persona vera dello staff e l\'assistenza; quelle negate no (F4.4)', function () {
    $staff = gettoneDelloStaffPerAssistenza($this->finto, 'sara@lucianocastro.com');
    $giulia = giuliaDaAssistere($this->finto);
    $assistenza = entraComeAssistenza($staff, $giulia['persona']['id']);

    alFinto('GET', '/v1/io', null, $assistenza['gettone']);
    alFinto('PATCH', '/v1/io', ['nome' => 'Altra'], $assistenza['gettone']);
    alFinto('GET', '/v1/accessi/corrente', null, $assistenza['gettone']);

    $righe = array_values(array_filter($this->finto->registroDelloStaff(), fn (array $riga) => $riga['impersonificazione_id'] !== null));

    expect(array_column($righe, 'azione'))->toBe(['staff.utenti.impersonificazioni.crea', 'io.mostra', 'accessi.corrente.mostra'])
        ->and(array_unique(array_column($righe, 'impersonificazione_id')))->toBe([$assistenza['impersonificazione_id']])
        ->and(array_unique(array_column($righe, 'bersaglio_id')))->toBe([$giulia['persona']['id']])
        ->and($righe[0]['motivo'])->toBe('assistenza')
        ->and($righe[1]['staff_id'])->toBe($righe[0]['staff_id']);
});

it('trenta minuti senza chiamate fermano l\'assistenza con 401, e ogni chiamata ammessa li rinnova; al massimo quattro ore (F4.2, D4)', function () {
    $staff = gettoneDelloStaffPerAssistenza($this->finto, 'sara@lucianocastro.com');
    $giulia = giuliaDaAssistere($this->finto);
    $assistenza = entraComeAssistenza($staff, $giulia['persona']['id'])['gettone'];
    $viva = entraComeAssistenza($staff, $giulia['persona']['id'])['gettone'];

    Carbon::setTestNow('2026-11-06 10:25:00');
    expect(alFinto('GET', '/v1/io', null, $assistenza)->status())->toBe(200);

    Carbon::setTestNow('2026-11-06 10:50:00');
    expect(alFinto('GET', '/v1/io', null, $assistenza)->status())->toBe(200);

    Carbon::setTestNow('2026-11-06 11:21:00');
    $fermo = alFinto('GET', '/v1/io', null, $assistenza);
    expect($fermo->status())->toBe(401)->and($fermo->json('codice'))->toBe('gettone_non_valido');

    // Una chiamata ogni 25 minuti la tiene viva, ma dopo quattro ore dallo scambio è 401 comunque.
    foreach (['10:25', '10:50', '11:15', '11:40', '12:05', '12:30', '12:55', '13:20', '13:45'] as $ora) {
        Carbon::setTestNow("2026-11-06 {$ora}:00");
        expect(alFinto('GET', '/v1/io', null, $viva)->status())->toBe(200);
    }

    Carbon::setTestNow('2026-11-06 14:01:00');
    expect(alFinto('GET', '/v1/io', null, $viva)->status())->toBe(401);
});

it('«Termina»: accessi.corrente.elimina chiude l\'assistenza e il suo accesso, e la persona resta com\'era (F4.6)', function () {
    $staff = gettoneDelloStaffPerAssistenza($this->finto, 'sara@lucianocastro.com');
    $giulia = giuliaDaAssistere($this->finto);
    $assistenza = entraComeAssistenza($staff, $giulia['persona']['id']);

    expect(alFinto('DELETE', '/v1/accessi/corrente', null, $assistenza['gettone'])->status())->toBe(204)
        ->and(alFinto('GET', '/v1/io', null, $assistenza['gettone'])->status())->toBe(401)
        ->and(alFinto('GET', '/v1/io', null, $giulia['gettone'])->status())->toBe(200)
        ->and(alFinto('GET', '/v1/accessi/corrente', null, $giulia['gettone'])->json('data.impersonificazione'))->toBeNull();
});

it('staff.impersonificazioni.elimina: il superadmin chiude ogni assistenza, il supporto la propria, una già chiusa è 204 e non scrive una seconda riga, e una che non c\'è è 404 (F4.6)', function () {
    $staff = gettoneDelloStaffPerAssistenza($this->finto, 'sara@lucianocastro.com');
    $supporto = gettoneDelloStaffPerAssistenza($this->finto, 'sam@lucianocastro.com', 'supporto');
    $altroSupporto = gettoneDelloStaffPerAssistenza($this->finto, 'sofi@lucianocastro.com', 'supporto');
    $giulia = giuliaDaAssistere($this->finto);

    $delSupporto = entraComeAssistenza($supporto, $giulia['persona']['id']);
    $delSuperadmin = entraComeAssistenza($staff, $giulia['persona']['id']);
    $righe = fn () => count(array_filter($this->finto->registroDelloStaff(), fn (array $riga) => $riga['azione'] === 'staff.impersonificazioni.elimina'));

    expect(esitoAssistenza(fn () => Api::staff($altroSupporto)->delete("/v1/staff/impersonificazioni/{$delSupporto['impersonificazione_id']}")))->toBe([404, 'non_trovato'])
        ->and(esitoAssistenza(fn () => Api::staff($supporto)->delete("/v1/staff/impersonificazioni/{$delSuperadmin['impersonificazione_id']}")))->toBe([404, 'non_trovato'])
        ->and(esitoAssistenza(fn () => Api::staff($supporto)->delete('/v1/staff/impersonificazioni/'.strtolower((string) Illuminate\Support\Str::ulid()))))->toBe([404, 'non_trovato'])
        ->and($righe())->toBe(0);

    Api::staff($supporto)->delete("/v1/staff/impersonificazioni/{$delSupporto['impersonificazione_id']}");
    Api::staff($supporto)->delete("/v1/staff/impersonificazioni/{$delSupporto['impersonificazione_id']}");
    Api::staff($staff)->delete("/v1/staff/impersonificazioni/{$delSuperadmin['impersonificazione_id']}");

    expect($righe())->toBe(2)
        ->and(alFinto('GET', '/v1/io', null, $delSupporto['gettone'])->status())->toBe(401)
        ->and(alFinto('GET', '/v1/io', null, $delSuperadmin['gettone'])->status())->toBe(401)
        ->and(alFinto('GET', '/v1/io', null, $giulia['gettone'])->status())->toBe(200);
});

it('gli errori del codice: la persona che non c\'è, lo staff, chi chiama, la sospesa, il workspace, l\'app spenta, il ritorno nel corpo (F4.1)', function () {
    $sara = $this->finto->persona('sara@lucianocastro.com', PASSWORD, nome: 'Sara');
    $staff = gettoneDellaPersonaDelloStaff($this->finto, $sara);
    $giulia = giuliaDaAssistere($this->finto);
    $id = $giulia['persona']['id'];
    $crea = fn (string $persona, array $corpo, ?string $gettone = null) => esitoAssistenza(fn () => Api::staff($gettone ?? $staff)->post("/v1/staff/utenti/{$persona}/impersonificazioni", $corpo));

    expect($crea(strtolower((string) Illuminate\Support\Str::ulid()), ['app' => 'pm']))->toBe([404, 'non_trovato'])
        ->and($crea($id, ['app' => 'sconosciuta']))->toBe([422, 'dati_non_validi'])
        ->and($crea($id, []))->toBe([422, 'dati_non_validi'])
        ->and($crea($id, ['app' => 'pm', 'ritorno' => 'https://altro.example.com']))->toBe([422, 'dati_non_validi'])
        ->and($crea($id, ['app' => 'pm', 'workspace_id' => strtolower((string) Illuminate\Support\Str::ulid())]))->toBe([404, 'non_trovato'])
        ->and($crea($id, ['app' => 'crm']))->toBe([403, 'app_non_attiva']);

    // Chi è anche dello staff, e chi chiama, non si assiste.
    $altroStaff = $this->finto->persona('lia@lucianocastro.com', PASSWORD);
    $this->finto->staffDi($altroStaff, 'supporto', true);
    entraNelFinto('lia@lucianocastro.com');
    expect($crea($sara['id'], ['app' => 'pm']))->toBe([403, 'impersonificazione_non_consentita'])
        ->and($crea($altroStaff['id'], ['app' => 'pm']))->toBe([403, 'impersonificazione_non_consentita']);

    // Sospesa: lo staff non entra come lei.
    Api::staff($staff)->post("/v1/staff/utenti/{$id}/sospensione", ['motivo' => 'altro']);
    expect($crea($id, ['app' => 'pm']))->toBe([403, 'impersonificazione_non_consentita']);
});

it('il workspace: senza dirlo vale l\'unico che la persona ha, con più d\'uno è 422 su #/workspace_id (F4.1)', function () {
    $staff = gettoneDelloStaffPerAssistenza($this->finto, 'sara@lucianocastro.com');
    $giulia = giuliaDaAssistere($this->finto);
    $secondo = $this->finto->workspace('Secondo', $giulia['persona']);
    $this->finto->attivaApp($secondo, 'pm');

    try {
        Api::staff($staff)->post("/v1/staff/utenti/{$giulia['persona']['id']}/impersonificazioni", ['app' => 'pm']);
        $errori = [];
    } catch (Zeiras\Auth\Errori\ErroreApi $errore) {
        $errori = $errore->errori;
    }

    expect(array_column($errori, 'pointer'))->toBe(['#/workspace_id']);

    $assistenza = Api::staff($staff)->post("/v1/staff/utenti/{$giulia['persona']['id']}/impersonificazioni", ['app' => 'pm', 'workspace_id' => $secondo['id']])['data'];
    $scambio = scambioDelFinto(['codice' => $assistenza['codice']], 'board');

    expect($scambio->json('data.workspace.id'))->toBe($secondo['id']);
});

it('il ruolo: senza gettone dello staff 401, con quello di una persona 403 (F4.1)', function () {
    $giulia = giuliaDaAssistere($this->finto);
    $marco = $this->finto->persona('marco@example.com', PASSWORD, nome: 'Marco');

    expect(esitoAssistenza(fn () => Api::staff()->post("/v1/staff/utenti/{$marco['id']}/impersonificazioni", ['app' => 'pm'])))->toBe([401, 'gettone_assente'])
        ->and(esitoAssistenza(fn () => Api::staff($giulia['gettone'])->post("/v1/staff/utenti/{$marco['id']}/impersonificazioni", ['app' => 'pm'])))->toBe([403, 'permesso_negato']);
});
