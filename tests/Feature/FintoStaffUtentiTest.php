<?php

use Illuminate\Support\Carbon;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Errori\GettoneRifiutato;
use Zeiras\Auth\Testing\BackofficeFinto;

// #2912 (F2): i metodi `staff.utenti.*` e `staff.esportazioni.*` del finto, per il frontend dello staff (zr-superadmin). Che forme ed errori siano quelli del
// backoffice lo prova il FintoTest del backoffice; qui si prova che il finto tiene le regole: il ruolo, il bersaglio dello staff, il perimetro, i null del vero
// (cognome, ultimo_accesso, sospensione, campi del registro…), la sospensione, i due tetti, le esportazioni di chi le ha avviate.

beforeEach(function () {
    Carbon::setTestNow('2026-11-05 10:00:00');
    config(['zr-auth.cliente' => BackofficeFinto::CLIENTE, 'zr-auth.segreto' => BackofficeFinto::SEGRETO_DEL_CLIENTE]);
    request()->server->set('REMOTE_ADDR', '203.0.113.7');
    $this->finto = BackofficeFinto::attiva();
});

/** Lo stato e il codice di un errore di /v1, o ['ok', null] se riesce. */
function esitoUtentiStaff(Closure $chiamata): array
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
function gettoneDelloStaffDi(BackofficeFinto $finto, string $email, string $ruolo = 'superadmin', bool $soloUat = false): string
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

/** Una persona dello staff già esistente rientra: un gettone nuovo (il codice TOTP vale un passo alla volta, il tempo del test deve essere avanzato). */
function rientraComeStaff(string $email): string
{
    return test()->finto->ingressoDelloStaff($email);
}

/** Le azioni di F2 nel registro dello staff, nell'ordine: gli ingressi dello staff non contano. */
function azioniUtentiStaff(BackofficeFinto $finto): array
{
    return array_values(array_filter(array_column($finto->registroDelloStaff(), 'azione'), fn (string $azione) => str_starts_with($azione, 'staff.utenti.') || str_starts_with($azione, 'staff.esportazioni.')));
}

it('l\'elenco e la scheda: i campi dichiarati, i null del vero, la lettura della scheda nel registro e quella dell\'elenco no (F2.1, F2.7, F2.9)', function () {
    $gettone = gettoneDelloStaffDi($this->finto, 'sara@lucianocastro.com');
    $giulia = $this->finto->persona('giulia@example.com', PASSWORD, nome: 'Giulia');
    $marco = $this->finto->persona('marco@example.com', PASSWORD, nome: 'Marco');
    entraNelFinto('giulia@example.com');
    $this->finto->datiDelloStaffSuLUtente($giulia, ['paese' => 'IT', 'nota_staff' => 'Cliente storica.']);

    $elenco = Api::staff($gettone)->get('/v1/staff/utenti')['data'];
    $righe = array_column($elenco, null, 'id');

    expect(array_keys($righe[$giulia['id']]))->toBe(['id', 'nome', 'cognome', 'email', 'stato', 'mai_acceduto', 'workspace_n', 'ultimo_accesso', 'creato_il'])
        ->and($righe[$giulia['id']]['mai_acceduto'])->toBeFalse()
        ->and($righe[$giulia['id']]['ultimo_accesso'])->toBe('2026-11-05T10:00:00.000Z')
        ->and($righe[$marco['id']])->toMatchArray(['cognome' => null, 'mai_acceduto' => true, 'ultimo_accesso' => null, 'stato' => 'attivo', 'workspace_n' => 0])
        ->and(azioniUtentiStaff($this->finto))->toBe([]);

    $scheda = Api::staff($gettone)->get("/v1/staff/utenti/{$marco['id']}")['data'];

    expect(array_keys($scheda))->toBe(['id', 'nome', 'cognome', 'email', 'stato', 'mai_acceduto', 'workspace_n', 'ultimo_accesso', 'creato_il', 'telefono', 'lingua', 'fuso_orario', 'paese', 'nota_staff', 'consensi', 'sicurezza', 'accessi_n', 'workspace', 'sospensione', 'eliminazione', 'cambio_email'])
        ->and($scheda)->toMatchArray(['telefono' => null, 'paese' => null, 'nota_staff' => null, 'consensi' => [], 'sospensione' => null, 'eliminazione' => null, 'cambio_email' => null, 'accessi_n' => 0, 'workspace' => [], 'sicurezza' => ['provider' => [], 'sessioni_aperte' => 0]]);
    expect(Api::staff($gettone)->get("/v1/staff/utenti/{$giulia['id']}")['data'])->toMatchArray(['paese' => 'IT', 'nota_staff' => 'Cliente storica.', 'accessi_n' => 1, 'sicurezza' => ['provider' => [], 'sessioni_aperte' => 1]]);

    $righeDelRegistro = array_values(array_filter($this->finto->registroDelloStaff(), fn (array $riga) => str_starts_with($riga['azione'], 'staff.utenti.')));
    expect(array_column($righeDelRegistro, 'azione'))->toBe(['staff.utenti.mostra', 'staff.utenti.mostra'])
        ->and(array_column($righeDelRegistro, 'bersaglio_tipo'))->toBe(['utente', 'utente'])
        ->and(array_column($righeDelRegistro, 'bersaglio_id'))->toBe([$marco['id'], $giulia['id']]);
});

it('il superadmin e il supporto leggono, la fatturazione e un gettone qualsiasi no; senza firma 401 (F2.1)', function () {
    $sara = gettoneDelloStaffDi($this->finto, 'sara@lucianocastro.com');
    $paola = gettoneDelloStaffDi($this->finto, 'paola@lucianocastro.com', 'supporto');
    $fabio = gettoneDelloStaffDi($this->finto, 'fabio@lucianocastro.com', 'fatturazione');
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $gettoneDiAnna = entraNelFinto('anna@example.com')['gettone']['gettone'];

    expect(esitoUtentiStaff(fn () => Api::staff($sara)->get('/v1/staff/utenti')))->toBe(['ok', null])
        ->and(esitoUtentiStaff(fn () => Api::staff($paola)->get('/v1/staff/utenti')))->toBe(['ok', null])
        ->and(esitoUtentiStaff(fn () => Api::staff($fabio)->get('/v1/staff/utenti')))->toBe([403, 'permesso_negato'])
        ->and(esitoUtentiStaff(fn () => Api::staff($fabio)->post('/v1/staff/utenti/ricerca', [])))->toBe([403, 'permesso_negato'])
        ->and(esitoUtentiStaff(fn () => Api::staff($fabio)->get("/v1/staff/utenti/{$anna['id']}")))->toBe([403, 'permesso_negato'])
        ->and(esitoUtentiStaff(fn () => Api::staff($gettoneDiAnna)->get('/v1/staff/utenti')))->toBe([403, 'permesso_negato'])
        ->and(esitoUtentiStaff(fn () => Api::staff()->get('/v1/staff/utenti')))->toBe([401, 'gettone_assente']);

    config(['zr-auth.segreto' => null]);

    expect(esitoUtentiStaff(fn () => Api::staff($sara)->get('/v1/staff/utenti')))->toBe([401, 'cliente_non_riconosciuto']);
});

it('la ricerca porta il termine nel corpo: nome, email esatta, stato, mai_acceduto; il cursore è suo e dei suoi filtri (F2.1, F2.7)', function () {
    $gettone = gettoneDelloStaffDi($this->finto, 'sara@lucianocastro.com');

    foreach (['Giulia Bianchi' => 'giulia@example.com', 'Giulio Verdi' => 'giulio@example.com', 'Marco Rossi' => 'marco@example.com'] as $nome => $email) {
        $this->finto->persona($email, PASSWORD, nome: $nome);
    }

    entraNelFinto('giulia@example.com');
    $cerca = fn (array $corpo) => Api::staff($gettone)->post('/v1/staff/utenti/ricerca', $corpo);

    expect(array_column($cerca(['q' => 'giuli'])['data'], 'email'))->toEqualCanonicalizing(['giulia@example.com', 'giulio@example.com'])
        ->and(array_column($cerca(['q' => 'bianchi giulia'])['data'], 'email'))->toBe(['giulia@example.com'])
        ->and(array_column($cerca(['email' => 'MARCO@example.com'])['data'], 'email'))->toBe(['marco@example.com'])
        ->and(array_column($cerca(['email' => 'marco@'])['data'], 'email'))->toBe([])
        ->and(array_column($cerca(['mai_acceduto' => false])['data'], 'email'))->toBe(['giulia@example.com'])
        ->and(array_column($cerca(['q' => null, 'stato' => 'attivo', 'limite' => null])['data'], 'email'))->toHaveCount(4)
        ->and(esitoUtentiStaff(fn () => $cerca(['stato' => 'archiviato'])))->toBe([422, 'dati_non_validi'])
        ->and(esitoUtentiStaff(fn () => $cerca(['limite' => 51])))->toBe([422, 'dati_non_validi'])
        ->and(esitoUtentiStaff(fn () => $cerca(['piano' => 'pro'])))->toBe([422, 'dati_non_validi']);

    $prima = $cerca(['limite' => 2]);
    expect($prima['data'])->toHaveCount(2)->and($prima['successivo'])->not->toBeNull();
    $seconda = $cerca(['limite' => 2, 'cursore' => $prima['successivo']]);
    expect(array_intersect(array_column($prima['data'], 'id'), array_column($seconda['data'], 'id')))->toBe([])
        // Il cursore dell'elenco non vale nella ricerca, né il cursore di una ricerca con altri filtri.
        ->and(esitoUtentiStaff(fn () => $cerca(['limite' => 2, 'q' => 'giuli', 'cursore' => $prima['successivo']])))->toBe([422, 'dati_non_validi'])
        ->and(esitoUtentiStaff(fn () => $cerca(['cursore' => Api::staff($gettone)->get('/v1/staff/utenti', ['limite' => 1])['successivo']])))->toBe([422, 'dati_non_validi']);
});

it('l\'elenco filtra per stato e mai_acceduto, e dice 25 per pagina senza `limite` (F2.7, F2.11)', function () {
    $gettone = gettoneDelloStaffDi($this->finto, 'sara@lucianocastro.com');

    foreach (range(1, 27) as $n) {
        $this->finto->persona("utente{$n}@example.com", PASSWORD, nome: "Utente {$n}");
    }

    $prima = Api::staff($gettone)->get('/v1/staff/utenti');
    expect($prima['data'])->toHaveCount(25)->and($prima['successivo'])->not->toBeNull()
        ->and(Api::staff($gettone)->get('/v1/staff/utenti', ['limite' => 50])['data'])->toHaveCount(28)
        ->and(esitoUtentiStaff(fn () => Api::staff($gettone)->get('/v1/staff/utenti', ['limite' => 51])))->toBe([422, 'dati_non_validi'])
        ->and(esitoUtentiStaff(fn () => Api::staff($gettone)->get('/v1/staff/utenti', ['stato' => 'archiviato'])))->toBe([422, 'dati_non_validi'])
        ->and(Api::staff($gettone)->get('/v1/staff/utenti', ['mai_acceduto' => 'false'])['data'])->toBe([])
        ->and(Api::staff($gettone)->get('/v1/staff/utenti', ['stato' => 'sospeso'])['data'])->toBe([]);
});

it('uno dello staff, la persona stessa e un utente che non c\'è: 403 o 404, ovunque (F2.5)', function () {
    $sara = $this->finto->persona('sara@lucianocastro.com', PASSWORD, nome: 'Sara');
    $this->finto->staffDi($sara, 'superadmin');
    $collega = $this->finto->persona('paola@lucianocastro.com', PASSWORD, nome: 'Paola');
    $this->finto->staffDi($collega, 'supporto');
    $gettone = $this->finto->ingressoDelloStaff('sara@lucianocastro.com');

    foreach ([$sara['id'], $collega['id']] as $id) {
        expect(esitoUtentiStaff(fn () => Api::staff($gettone)->get("/v1/staff/utenti/{$id}")))->toBe([403, 'permesso_negato'])
            ->and(esitoUtentiStaff(fn () => Api::staff($gettone)->get("/v1/staff/utenti/{$id}/registro")))->toBe([403, 'permesso_negato'])
            ->and(esitoUtentiStaff(fn () => Api::staff($gettone)->post("/v1/staff/utenti/{$id}/sospensione", ['motivo' => 'altro'])))->toBe([403, 'permesso_negato'])
            ->and(esitoUtentiStaff(fn () => Api::staff($gettone)->delete("/v1/staff/utenti/{$id}/sessioni")))->toBe([403, 'permesso_negato'])
            ->and(esitoUtentiStaff(fn () => Api::staff($gettone)->post("/v1/staff/utenti/{$id}/password/recupero")))->toBe([403, 'permesso_negato'])
            ->and(esitoUtentiStaff(fn () => Api::staff($gettone)->post("/v1/staff/utenti/{$id}/esportazioni")))->toBe([403, 'permesso_negato']);
    }

    expect(esitoUtentiStaff(fn () => Api::staff($gettone)->get('/v1/staff/utenti/01k8x5c9y7f2r6q0s4t8v2w6z0')))->toBe([404, 'non_trovato'])
        ->and(azioniUtentiStaff($this->finto))->toBe([]);
});

it('una persona dello staff solo_uat vede e tocca soltanto gli utenti uat+…@castroandpartners.com; gli altri 404 (F2.1)', function () {
    $gettone = gettoneDelloStaffDi($this->finto, 'prova@lucianocastro.com', 'superadmin', soloUat: true);
    $dentro = $this->finto->persona('uat+a@castroandpartners.com', PASSWORD, nome: 'Uat');
    $fuori = $this->finto->persona('vera@example.com', PASSWORD, nome: 'Vera');

    expect(array_column(Api::staff($gettone)->get('/v1/staff/utenti')['data'], 'id'))->toBe([$dentro['id']])
        ->and(esitoUtentiStaff(fn () => Api::staff($gettone)->get("/v1/staff/utenti/{$dentro['id']}")))->toBe(['ok', null])
        ->and(esitoUtentiStaff(fn () => Api::staff($gettone)->get("/v1/staff/utenti/{$fuori['id']}")))->toBe([404, 'non_trovato'])
        ->and(esitoUtentiStaff(fn () => Api::staff($gettone)->post("/v1/staff/utenti/{$fuori['id']}/sospensione", ['motivo' => 'altro'])))->toBe([404, 'non_trovato']);
});

it('la sospensione: solo il superadmin; chiude le sessioni; ogni nuovo accesso è 403 account_sospeso dopo credenziali giuste; si toglie; un doppio è 409 (F2.4)', function () {
    $sara = gettoneDelloStaffDi($this->finto, 'sara@lucianocastro.com');
    $paola = gettoneDelloStaffDi($this->finto, 'paola@lucianocastro.com', 'supporto');
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $gettoneDiAnna = entraNelFinto('anna@example.com')['gettone']['gettone'];

    expect(esitoUtentiStaff(fn () => Api::staff($paola)->post("/v1/staff/utenti/{$anna['id']}/sospensione", ['motivo' => 'altro'])))->toBe([403, 'permesso_negato'])
        ->and(esitoUtentiStaff(fn () => Api::staff($sara)->post("/v1/staff/utenti/{$anna['id']}/sospensione", [])))->toBe([422, 'dati_non_validi'])
        ->and(esitoUtentiStaff(fn () => Api::staff($sara)->post("/v1/staff/utenti/{$anna['id']}/sospensione", ['motivo' => 'noia'])))->toBe([422, 'dati_non_validi'])
        ->and(esitoUtentiStaff(fn () => Api::staff($sara)->post("/v1/staff/utenti/{$anna['id']}/sospensione", ['motivo' => 'altro', 'nota' => str_repeat('a', 501)])))->toBe([422, 'dati_non_validi']);

    $sospensione = Api::staff($sara)->post("/v1/staff/utenti/{$anna['id']}/sospensione", ['motivo' => 'segnalazione_abuso', 'nota' => 'Tre segnalazioni.'])['data'];

    expect($sospensione)->toBe(['motivo' => 'segnalazione_abuso', 'nota' => 'Tre segnalazioni.', 'dal' => '2026-11-05T10:00:00.000Z'])
        ->and(esitoUtentiStaff(fn () => Api::staff($sara)->post("/v1/staff/utenti/{$anna['id']}/sospensione", ['motivo' => 'altro'])))->toBe([409, 'gia_sospeso'])
        // La sessione di prima è chiusa; chi sbaglia la password riceve il 401 di sempre, chi la sa il 403.
        ->and(alFinto('GET', '/v1/io', null, $gettoneDiAnna)->json('codice'))->toBe('gettone_non_valido')
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD_SBAGLIATA])->json('codice'))->toBe('credenziali_non_valide')
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD])->json('codice'))->toBe('account_sospeso')
        ->and(Api::staff($sara)->get("/v1/staff/utenti/{$anna['id']}")['data'])->toMatchArray(['stato' => 'sospeso', 'sospensione' => $sospensione])
        ->and(Api::staff($sara)->get('/v1/staff/utenti/conteggi')['data'])->toBe(['tutti' => 3, 'attivo' => 2, 'sospeso' => 1, 'in_eliminazione' => 0])
        ->and(array_column(Api::staff($sara)->get('/v1/staff/utenti', ['stato' => 'sospeso'])['data'], 'id'))->toBe([$anna['id']]);

    Api::staff($sara)->delete("/v1/staff/utenti/{$anna['id']}/sospensione");

    expect(esitoUtentiStaff(fn () => Api::staff($sara)->delete("/v1/staff/utenti/{$anna['id']}/sospensione")))->toBe([404, 'non_trovato'])
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD])->status())->toBe(201)
        ->and(Api::staff($sara)->get("/v1/staff/utenti/{$anna['id']}")['data'])->toMatchArray(['stato' => 'attivo', 'sospensione' => null]);

    $azioni = array_column($this->finto->registroDelloStaff(), 'azione');
    expect($azioni)->toContain('staff.utenti.sospensione.crea', 'staff.utenti.sospensione.elimina');
    $riga = array_values(array_filter($this->finto->registroDelloStaff(), fn (array $r) => $r['azione'] === 'staff.utenti.sospensione.crea'))[0];
    // Il motivo è un codice, la nota non sta nel registro.
    expect($riga)->toMatchArray(['bersaglio_tipo' => 'utente', 'bersaglio_id' => $anna['id'], 'motivo' => 'segnalazione_abuso', 'campi' => null]);
});

it('chiudere le sessioni e avviare il recupero: superadmin e supporto, 204 e 202, una riga di registro, nessuna email all\'utente di troppo (F2.2, F2.8)', function () {
    $paola = gettoneDelloStaffDi($this->finto, 'paola@lucianocastro.com', 'supporto');
    $fabio = gettoneDelloStaffDi($this->finto, 'fabio@lucianocastro.com', 'fatturazione');
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $gettoneDiAnna = entraNelFinto('anna@example.com')['gettone']['gettone'];

    expect(esitoUtentiStaff(fn () => Api::staff($fabio)->delete("/v1/staff/utenti/{$anna['id']}/sessioni")))->toBe([403, 'permesso_negato'])
        ->and(Api::staff($paola)->delete("/v1/staff/utenti/{$anna['id']}/sessioni"))->toBe([])
        ->and(alFinto('GET', '/v1/io', null, $gettoneDiAnna)->json('codice'))->toBe('gettone_non_valido')
        // Senza sessioni aperte è 204 lo stesso.
        ->and(esitoUtentiStaff(fn () => Api::staff($paola)->delete("/v1/staff/utenti/{$anna['id']}/sessioni")))->toBe(['ok', null]);

    $recupero = Api::staff($paola)->post("/v1/staff/utenti/{$anna['id']}/password/recupero");

    expect($recupero)->toBe(['data' => ['scade_il' => '2026-11-05T10:10:00.000Z']])
        ->and($this->finto->ultimoCodice('anna@example.com'))->not->toBeNull()
        ->and(azioniUtentiStaff($this->finto))->toBe(['staff.utenti.sessioni.elimina', 'staff.utenti.sessioni.elimina', 'staff.utenti.password.recupero.crea']);
});

it('le esportazioni dei dati di un utente: in_corso, poi pronta; si scarica più volte fino a scade_il; dopo un\'ora è 409; un altro dello staff 404 (F2.3, F2.10)', function () {
    $sara = gettoneDelloStaffDi($this->finto, 'sara@lucianocastro.com');
    $paola = gettoneDelloStaffDi($this->finto, 'paola@lucianocastro.com');
    $gino = gettoneDelloStaffDi($this->finto, 'gino@lucianocastro.com', 'supporto');
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');

    // L'esportazione dei dati di un utente è del solo superadmin (zr-pm 05/11): il supporto ha 403 sulla richiesta, sulla lettura e sullo scaricamento.
    expect(esitoUtentiStaff(fn () => Api::staff($gino)->post("/v1/staff/utenti/{$anna['id']}/esportazioni")))->toBe([403, 'permesso_negato']);

    $creata = Api::staff($paola)->post("/v1/staff/utenti/{$anna['id']}/esportazioni")['data'];

    expect(esitoUtentiStaff(fn () => Api::staff($gino)->get("/v1/staff/esportazioni/{$creata['id']}")))->toBe([403, 'permesso_negato'])
        ->and(esitoUtentiStaff(fn () => Api::staff($gino)->scaricaConPost("/v1/staff/esportazioni/{$creata['id']}/scaricamento")))->toBe([403, 'permesso_negato']);

    expect($creata)->toMatchArray(['tipo' => 'utente_gdpr', 'stato' => 'in_corso', 'creata_il' => '2026-11-05T10:00:00.000Z', 'scade_il' => '2026-11-05T11:00:00.000Z', 'dimensione_byte' => null, 'errore' => null])
        ->and(esitoUtentiStaff(fn () => Api::staff($paola)->scaricaConPost("/v1/staff/esportazioni/{$creata['id']}/scaricamento")))->toBe([409, 'esportazione_non_disponibile'])
        // Un altro dello staff, anche superadmin, non la vede: 404.
        ->and(esitoUtentiStaff(fn () => Api::staff($sara)->get("/v1/staff/esportazioni/{$creata['id']}")))->toBe([404, 'non_trovato'])
        ->and(esitoUtentiStaff(fn () => Api::staff($sara)->scaricaConPost("/v1/staff/esportazioni/{$creata['id']}/scaricamento")))->toBe([404, 'non_trovato']);

    $this->finto->preparaLeEsportazioniDelloStaff();
    $pronta = Api::staff($paola)->get("/v1/staff/esportazioni/{$creata['id']}")['data'];
    $file = Api::staff($paola)->scaricaConPost("/v1/staff/esportazioni/{$creata['id']}/scaricamento");

    expect($pronta)->toMatchArray(['stato' => 'pronta', 'dimensione_byte' => 22])
        ->and($file->tipo)->toBe('application/zip')
        ->and($file->nome)->toStartWith('utente-')
        ->and(esitoUtentiStaff(fn () => Api::staff($paola)->scaricaConPost("/v1/staff/esportazioni/{$creata['id']}/scaricamento")))->toBe(['ok', null]);

    // Passata l'ora: la stessa persona rientra con un gettone nuovo, e il file non si scarica più.
    Carbon::setTestNow('2026-11-05 11:00:01');
    $paola = rientraComeStaff('paola@lucianocastro.com');

    expect(Api::staff($paola)->get("/v1/staff/esportazioni/{$creata['id']}")['data'])->toMatchArray(['stato' => 'scaduta', 'dimensione_byte' => 22])
        ->and(esitoUtentiStaff(fn () => Api::staff($paola)->scaricaConPost("/v1/staff/esportazioni/{$creata['id']}/scaricamento")))->toBe([409, 'esportazione_non_disponibile']);
    // Le righe: la richiesta e due scaricamenti.
    expect(array_count_values(azioniUtentiStaff($this->finto)))->toMatchArray(['staff.utenti.esportazioni.crea' => 1, 'staff.esportazioni.scaricamento.crea' => 2]);
});

it('il CSV dell\'elenco rispetta i filtri; una esportazione finisce fallita oltre il tetto; il CSV dell\'elenco è solo del superadmin, con i campi della riga e le formule con l\'apostrofo (F2.10)', function () {
    $sara = gettoneDelloStaffDi($this->finto, 'sara@lucianocastro.com');
    $paola = gettoneDelloStaffDi($this->finto, 'paola@lucianocastro.com', 'supporto');
    $this->finto->persona('anna@example.com', PASSWORD, nome: '=SOMMA(A1)');
    $this->finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');

    expect(esitoUtentiStaff(fn () => Api::staff($paola)->post('/v1/staff/utenti/ricerca/esportazioni', [])))->toBe([403, 'permesso_negato'])
        ->and(esitoUtentiStaff(fn () => Api::staff($sara)->post('/v1/staff/utenti/ricerca/esportazioni', ['limite' => 5])))->toBe([422, 'dati_non_validi'])
        ->and(esitoUtentiStaff(fn () => Api::staff($sara)->post('/v1/staff/utenti/ricerca/esportazioni', ['cursore' => 'x'])))->toBe([422, 'dati_non_validi']);

    $conFiltro = Api::staff($sara)->post('/v1/staff/utenti/ricerca/esportazioni', ['q' => 'somma'])['data'];
    $tutti = Api::staff($sara)->post('/v1/staff/utenti/ricerca/esportazioni', [])['data'];
    expect($conFiltro['tipo'])->toBe('elenco_utenti');
    $this->finto->preparaLeEsportazioniDelloStaff();
    $leggi = function (string $id) use ($sara) {
        $contenuto = '';
        $file = Api::staff($sara)->scaricaConPost("/v1/staff/esportazioni/{$id}/scaricamento");

        foreach ($file->blocchi() as $blocco) {
            $contenuto .= $blocco;
        }

        return $contenuto;
    };
    $filtrato = $leggi($conFiltro['id']);
    $intero = $leggi($tutti['id']);

    expect($filtrato)->toStartWith("\xEF\xBB\xBFid,nome,cognome,email,stato,mai_acceduto,workspace_n,ultimo_accesso,creato_il\r\n")
        // Il filtro del corpo c'è: solo la riga di «=SOMMA(A1)», con l'apostrofo davanti alla formula.
        ->and(substr_count($filtrato, "\r\n"))->toBe(2)
        ->and($filtrato)->toContain(",'=SOMMA(A1),,anna@example.com,attivo,true,0,,")
        // Senza filtro: sara, paola, anna e bruno, nell'ordine dell'elenco (dal più recente), e il peso dichiarato è quello del file col BOM.
        ->and(substr_count($intero, "\r\n"))->toBe(5)
        ->and(Api::staff($sara)->get("/v1/staff/esportazioni/{$tutti['id']}")['data']['dimensione_byte'])->toBe(strlen($intero));

    $quarta = Api::staff($sara)->post('/v1/staff/utenti/ricerca/esportazioni', ['mai_acceduto' => true])['data'];
    $this->finto->fallisciLeEsportazioniDelloStaff();
    $fallita = Api::staff($sara)->get("/v1/staff/esportazioni/{$quarta['id']}")['data'];

    expect($fallita)->toMatchArray(['stato' => 'fallita', 'errore' => 'esportazione_troppo_grande', 'dimensione_byte' => null])
        ->and(esitoUtentiStaff(fn () => Api::staff($sara)->scaricaConPost("/v1/staff/esportazioni/{$quarta['id']}/scaricamento")))->toBe([409, 'esportazione_non_disponibile'])
        ;
});

it('i due tetti: 30 azioni sensibili all\'ora senza riga oltre, 300 pagine di lettura con Retry-After e una riga; per persona, non per gettone (F2.11)', function () {
    $sara = gettoneDelloStaffDi($this->finto, 'sara@lucianocastro.com');
    $paola = gettoneDelloStaffDi($this->finto, 'paola@lucianocastro.com', 'supporto');
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');

    foreach (range(1, 30) as $n) {
        Api::staff($sara)->delete("/v1/staff/utenti/{$anna['id']}/sessioni");
    }

    $righe = count($this->finto->registroDelloStaff());

    $frenata = null;
    try {
        Api::staff($sara)->delete("/v1/staff/utenti/{$anna['id']}/sessioni");
    } catch (ErroreApi $errore) {
        $frenata = $errore;
    }

    // Retry-After in secondi interi; e le altre azioni sensibili contano nello stesso tetto.
    expect($frenata?->stato)->toBe(429)
        ->and($frenata?->riprovaFra)->toBe(3600)
        ->and(esitoUtentiStaff(fn () => Api::staff($sara)->post("/v1/staff/utenti/{$anna['id']}/password/recupero")))->toBe([429, 'troppe_richieste'])
        ->and(esitoUtentiStaff(fn () => Api::staff($sara)->post("/v1/staff/utenti/{$anna['id']}/esportazioni")))->toBe([429, 'troppe_richieste'])
        ->and(esitoUtentiStaff(fn () => Api::staff($sara)->post("/v1/staff/utenti/{$anna['id']}/sospensione", ['motivo' => 'altro'])))->toBe([429, 'troppe_richieste'])
        ->and(count($this->finto->registroDelloStaff()))->toBe($righe)
        ->and(esitoUtentiStaff(fn () => Api::staff($paola)->delete("/v1/staff/utenti/{$anna['id']}/sessioni")))->toBe(['ok', null]);

    foreach (range(1, 300) as $n) {
        Api::staff($paola)->get('/v1/staff/utenti', ['limite' => 1]);
    }

    $righe = count($this->finto->registroDelloStaff());

    expect(esitoUtentiStaff(fn () => Api::staff($paola)->get('/v1/staff/utenti')))->toBe([429, 'troppe_richieste'])
        ->and(esitoUtentiStaff(fn () => Api::staff($paola)->post('/v1/staff/utenti/ricerca', [])))->toBe([429, 'troppe_richieste'])
        ->and(count($this->finto->registroDelloStaff()))->toBe($righe + 2)
        // Un'altra persona dello staff non è frenata; e un gettone nuovo della stessa persona non azzera il conto.
        ->and(esitoUtentiStaff(fn () => Api::staff($sara)->get('/v1/staff/utenti')))->toBe(['ok', null]);

    Carbon::setTestNow('2026-11-05 10:01:00');
    $gettoneNuovo = $this->finto->ingressoDelloStaff('paola@lucianocastro.com');

    expect(esitoUtentiStaff(fn () => Api::staff($gettoneNuovo)->get('/v1/staff/utenti')))->toBe([429, 'troppe_richieste']);

    Carbon::setTestNow('2026-11-05 11:00:01');
    $gettoneDopo = $this->finto->ingressoDelloStaff('paola@lucianocastro.com');

    expect(esitoUtentiStaff(fn () => Api::staff($gettoneDopo)->get('/v1/staff/utenti')))->toBe(['ok', null]);
});

it('il registro dell\'utente: le sole righe su di lui, nome e cognome del bersaglio, i null del vero; il supporto vede le proprie (F2.1)', function () {
    $sara = gettoneDelloStaffDi($this->finto, 'sara@lucianocastro.com');
    $paola = gettoneDelloStaffDi($this->finto, 'paola@lucianocastro.com', 'supporto');
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $bruno = $this->finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');

    Api::staff($sara)->get("/v1/staff/utenti/{$anna['id']}");
    Api::staff($paola)->get("/v1/staff/utenti/{$anna['id']}");
    Api::staff($sara)->get("/v1/staff/utenti/{$bruno['id']}");

    $dalSuperadmin = Api::staff($sara)->get("/v1/staff/utenti/{$anna['id']}/registro");
    $dalSupporto = Api::staff($paola)->get("/v1/staff/utenti/{$anna['id']}/registro");

    expect($dalSuperadmin['data'])->toHaveCount(2)
        ->and($dalSuperadmin['successivo'])->toBeNull()
        ->and($dalSuperadmin['data'][0])->toMatchArray(['azione' => 'staff.utenti.mostra', 'bersaglio_tipo' => 'utente', 'bersaglio_id' => $anna['id'], 'campi' => null, 'motivo' => null, 'impersonificazione_id' => null, 'bersaglio' => ['nome' => 'Anna', 'cognome' => null]])
        ->and($dalSupporto['data'])->toHaveCount(1)
        ->and(array_column(Api::staff($sara)->get("/v1/staff/utenti/{$anna['id']}/registro", ['azione' => 'staff.altro'])['data'], 'id'))->toBe([])
        // Il registro letto non scrive nel registro.
        ->and(azioniUtentiStaff($this->finto))->toHaveCount(3);
});
