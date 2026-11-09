<?php

use Illuminate\Support\Carbon;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Errori\GettoneRifiutato;
use Zeiras\Auth\Testing\BackofficeFinto;

// #2911 (F1.7): Api::staff() e il finto dei quattordici metodi `staff.*`, per il frontend dello staff (zr-superadmin). Che le risposte e gli errori siano quelli
// del backoffice lo prova il FintoTest del backoffice; qui si prova che il client firma sempre, e che il finto tiene le regole che i controller dello staff devono
// rispettare (R16, i freni, il TOTP a un passo solo, i ruoli, il perimetro solo_uat, l'ultimo superadmin).

beforeEach(function () {
    Carbon::setTestNow('2026-11-04 10:00:00');
    config(['zr-auth.cliente' => BackofficeFinto::CLIENTE, 'zr-auth.segreto' => BackofficeFinto::SEGRETO_DEL_CLIENTE]);
    request()->server->set('REMOTE_ADDR', '203.0.113.7');
    $this->finto = BackofficeFinto::attiva();
});

/** Lo stato e il codice di un errore di /v1 di una chiamata, o ['ok', null] se riesce. */
function esitoDelloStaff(Closure $chiamata): array
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

/** Una persona dello staff già entrata: il gettone dello staff. Col login normale di Zeiras (#3445); le persone di prova (`$soloUat`) con la password e il secondo fattore. */
function entraComeStaff(array $staff, bool $soloUat = false): string
{
    if (! $soloUat) {
        return test()->finto->ingressoDelloStaff($staff);
    }

    $sfida = Api::staff()->post('/v1/staff/accessi', ['email' => $staff['email'], 'password' => PASSWORD])['data']['sfida']['id'];

    return Api::staff()->post('/v1/staff/accessi/verifica', ['sfida' => $sfida, 'codice' => BackofficeFinto::CODICE_TOTP_DELLO_STAFF])['data']['gettone'];
}

function personaDelloStaff(BackofficeFinto $finto, string $email, string $ruolo = 'superadmin', bool $conTotp = true, bool $soloUat = false): array
{
    $persona = $finto->persona($email, PASSWORD, nome: ucfirst(strstr($email, '@', true)));

    return $finto->staffDi($persona, $ruolo, $conTotp, $soloUat);
}

it('Api::staff() firma sempre, anche col gettone; senza client riconosciuto è 401 cliente_non_riconosciuto (F1.5, F1.7)', function () {
    $sara = personaDelloStaff($this->finto, 'sara@lucianocastro.com', soloUat: true);
    $gettone = entraComeStaff($sara, true);

    Api::staff($gettone)->get('/v1/staff/corrente');

    expect(array_column($this->finto->ipVisti(), 'operazione'))->toBe(['staff.accessi.crea', 'staff.accessi.verifica.crea', 'staff.corrente.mostra'])
        ->and(array_unique(array_column($this->finto->ipVisti(), 'ip')))->toBe(['203.0.113.7']);

    config(['zr-auth.segreto' => null]);

    expect(esitoDelloStaff(fn () => Api::staff($gettone)->get('/v1/staff/corrente')))->toBe([401, 'cliente_non_riconosciuto'])
        ->and(esitoDelloStaff(fn () => Api::staff()->post('/v1/staff/accessi', ['email' => 'sara@lucianocastro.com', 'password' => PASSWORD])))->toBe([401, 'cliente_non_riconosciuto']);
    expect(fn () => Api::staff(''))->toThrow(InvalidArgumentException::class);
});

it('enrolment: invito, gettone di 15 minuti che apre solo staff.totp.*, codice 000000, dieci codici di recupero una volta sola (F1.3, F1.10)', function () {
    $nuova = personaDelloStaff($this->finto, 'nuova@lucianocastro.com', 'supporto', conTotp: false, soloUat: true);
    $segreto = $this->finto->ultimoInvitoDelloStaff('nuova@lucianocastro.com');

    expect($segreto)->toBeString()
        ->and(esitoDelloStaff(fn () => Api::staff()->post('/v1/staff/inviti/accettazione', ['segreto' => $segreto, 'password' => PASSWORD_SBAGLIATA])))->toBe([401, 'invito_non_valido'])
        ->and(esitoDelloStaff(fn () => Api::staff()->post('/v1/staff/inviti/accettazione', ['segreto' => str_repeat('0', 20), 'password' => PASSWORD])))->toBe([401, 'invito_non_valido']);

    $enrolment = Api::staff()->post('/v1/staff/inviti/accettazione', ['segreto' => $segreto, 'password' => PASSWORD])['data'];

    expect($enrolment['scade_il'])->toBe('2026-11-04T10:15:00.000Z')
        ->and(esitoDelloStaff(fn () => Api::staff($enrolment['gettone'])->get('/v1/staff/corrente')))->toBe([403, 'permesso_negato'])
        ->and(esitoDelloStaff(fn () => Api::staff($enrolment['gettone'])->post('/v1/staff/totp/conferma', ['codice' => '000000'])))->toBe([401, 'secondo_fattore_non_valido']);

    $avvio = Api::staff($enrolment['gettone'])->post('/v1/staff/totp')['data'];
    expect($avvio['segreto'])->toMatch('/^[A-Z2-7]{32}$/')->and($avvio['otpauth'])->toStartWith('otpauth://totp/Zeiras%3Anuova%40lucianocastro.com?')->toContain($avvio['segreto']);

    expect(esitoDelloStaff(fn () => Api::staff($enrolment['gettone'])->post('/v1/staff/totp/conferma', ['codice' => '123456'])))->toBe([401, 'secondo_fattore_non_valido']);
    $codici = Api::staff($enrolment['gettone'])->post('/v1/staff/totp/conferma', ['codice' => '000000'])['data']['codici_di_recupero'];

    expect($codici)->toHaveCount(10)->and(array_unique($codici))->toHaveCount(10)
        ->and($this->finto->codiciDiRecuperoDelloStaff($nuova))->toBe($codici)
        ->and(esitoDelloStaff(fn () => Api::staff($enrolment['gettone'])->post('/v1/staff/totp')))->toBe([401, 'gettone_non_valido'])
        ->and($this->finto->ultimoInvitoDelloStaff('nuova@lucianocastro.com'))->toBeNull()
        ->and(esitoDelloStaff(fn () => Api::staff()->post('/v1/staff/inviti/accettazione', ['segreto' => $segreto, 'password' => PASSWORD])))->toBe([401, 'invito_non_valido']);
});

it('un enrolment scaduto dopo 15 minuti è 401 gettone_non_valido; il quinto codice sbagliato chiude il gettone (F1.10)', function () {
    personaDelloStaff($this->finto, 'nuova@lucianocastro.com', 'supporto', conTotp: false, soloUat: true);
    $segreto = $this->finto->ultimoInvitoDelloStaff('nuova@lucianocastro.com');
    $gettone = Api::staff()->post('/v1/staff/inviti/accettazione', ['segreto' => $segreto, 'password' => PASSWORD])['data']['gettone'];
    Api::staff($gettone)->post('/v1/staff/totp');

    foreach (range(1, 5) as $_) {
        expect(esitoDelloStaff(fn () => Api::staff($gettone)->post('/v1/staff/totp/conferma', ['codice' => '999999'])))->toBe([401, 'secondo_fattore_non_valido']);
    }

    expect(esitoDelloStaff(fn () => Api::staff($gettone)->post('/v1/staff/totp/conferma', ['codice' => '000000'])))->toBe([401, 'gettone_non_valido']);

    $gettone = Api::staff()->post('/v1/staff/inviti/accettazione', ['segreto' => $segreto, 'password' => PASSWORD])['data']['gettone'];
    Carbon::setTestNow('2026-11-04 10:16:00');

    expect(esitoDelloStaff(fn () => Api::staff($gettone)->post('/v1/staff/totp')))->toBe([401, 'gettone_non_valido']);
});

it('ingresso di prova: il primo passo risponde allo stesso modo per chiunque (R16), e la persona dello staff vera ha una sfida inventata; il secondo ha un solo errore (F1.2, #3445)', function () {
    $sara = personaDelloStaff($this->finto, 'sara@lucianocastro.com', soloUat: true);
    personaDelloStaff($this->finto, 'vera@lucianocastro.com');
    $this->finto->persona('semplice@lucianocastro.com', PASSWORD);
    $this->finto->persona('fuori@example.com', PASSWORD);
    $forma = fn (array $risposta) => [array_keys($risposta['data']), array_keys($risposta['data']['sfida']), strlen($risposta['data']['sfida']['id'])];

    $risposte = [
        'sara' => ['sara@lucianocastro.com', PASSWORD],
        'password sbagliata' => ['sara@lucianocastro.com', PASSWORD_SBAGLIATA],
        'sconosciuta' => ['nessuna@lucianocastro.com', PASSWORD],
        'non staff' => ['semplice@lucianocastro.com', PASSWORD],
        'staff vera, non di prova' => ['vera@lucianocastro.com', PASSWORD],
        'fuori dominio' => ['fuori@example.com', PASSWORD],
    ];
    $sfide = [];

    foreach ($risposte as $nome => [$email, $password]) {
        $risposta = Api::staff()->post('/v1/staff/accessi', ['email' => $email, 'password' => $password]);
        expect($forma($risposta))->toBe([['sfida'], ['id', 'scade_il'], 64])->and($risposta['data']['sfida']['scade_il'])->toBe('2026-11-04T10:05:00.000Z');
        $sfide[$nome] = $risposta['data']['sfida']['id'];
    }

    foreach (array_slice($sfide, 1) as $sfida) {
        expect(esitoDelloStaff(fn () => Api::staff()->post('/v1/staff/accessi/verifica', ['sfida' => $sfida, 'codice' => '000000'])))->toBe([401, 'secondo_fattore_non_valido']);
    }

    $dati = Api::staff()->post('/v1/staff/accessi/verifica', ['sfida' => $sfide['sara'], 'codice' => '000000'])['data'];

    expect($dati)->toHaveKeys(['gettone', 'scade_il', 'inattivita_scade_il', 'id', 'nome', 'cognome', 'email', 'ruolo'])
        ->and($dati['scade_il'])->toBe('2026-11-04T18:00:00.000Z')->and($dati['inattivita_scade_il'])->toBe('2026-11-04T10:30:00.000Z')
        ->and($dati['id'])->toBe($sara['id'])->and($dati['ruolo'])->toBe('superadmin')
        ->and(esitoDelloStaff(fn () => Api::staff()->post('/v1/staff/accessi/verifica', ['sfida' => $sfide['sara'], 'codice' => '000000'])))->toBe([401, 'secondo_fattore_non_valido']);
});

it('il codice del TOTP vale per un passo solo; un codice di recupero una volta sola; il quinto sbagliato uccide la sfida (F1.2)', function () {
    $sara = personaDelloStaff($this->finto, 'sara@lucianocastro.com', soloUat: true);
    $sfida = fn () => Api::staff()->post('/v1/staff/accessi', ['email' => 'sara@lucianocastro.com', 'password' => PASSWORD])['data']['sfida']['id'];
    $verifica = fn (string $sfida, string $codice) => esitoDelloStaff(fn () => Api::staff()->post('/v1/staff/accessi/verifica', ['sfida' => $sfida, 'codice' => $codice]));

    expect($verifica($sfida(), '000000'))->toBe(['ok', null])
        ->and($verifica($sfida(), '000000'))->toBe([401, 'secondo_fattore_non_valido']);

    Carbon::setTestNow('2026-11-04 10:01:00');
    $recupero = $this->finto->codiciDiRecuperoDelloStaff($sara)[0];

    expect($verifica($sfida(), $recupero))->toBe(['ok', null])
        ->and($verifica($sfida(), $recupero))->toBe([401, 'secondo_fattore_non_valido'])
        ->and($this->finto->codiciDiRecuperoDelloStaff($sara))->toHaveCount(9);

    $morta = $sfida();

    foreach (range(1, 5) as $_) {
        expect($verifica($morta, '111111'))->toBe([401, 'secondo_fattore_non_valido']);
    }

    expect($verifica($morta, '000000'))->toBe([401, 'secondo_fattore_non_valido']);
});

it('i freni: il sesto primo passo per la stessa email in 15 minuti è 429 con il Retry-After (F1.2)', function () {
    personaDelloStaff($this->finto, 'sara@lucianocastro.com');

    foreach (range(1, 5) as $_) {
        Api::staff()->post('/v1/staff/accessi', ['email' => 'sara@lucianocastro.com', 'password' => PASSWORD]);
    }

    try {
        Api::staff()->post('/v1/staff/accessi', ['email' => 'sara@lucianocastro.com', 'password' => PASSWORD]);
        $this->fail('Il sesto tentativo doveva essere frenato.');
    } catch (ErroreApi $errore) {
        expect([$errore->stato, $errore->codice])->toBe([429, 'troppe_richieste'])->and($errore->riprovaFra)->toBeInt()->toBeGreaterThan(0);
    }
});

it('il gettone dello staff: 30 minuti senza chiamate lo spengono, ogni chiamata li rinnova, 8 ore al massimo; staff.accessi.corrente.elimina lo chiude (F1.2)', function () {
    $sara = personaDelloStaff($this->finto, 'sara@lucianocastro.com');
    $gettone = entraComeStaff($sara);

    Carbon::setTestNow('2026-11-04 10:20:00');
    $corrente = Api::staff($gettone)->get('/v1/staff/corrente')['data'];

    expect($corrente['gettone'])->toBe(['scade_il' => '2026-11-04T18:00:00.000Z', 'inattivita_scade_il' => '2026-11-04T10:50:00.000Z'])
        ->and(array_keys($corrente))->toBe(['id', 'nome', 'cognome', 'email', 'ruolo', 'lingua', 'fuso_orario', 'gettone'])
        ->and($corrente['lingua'])->toBe('it')->and($corrente['fuso_orario'])->toBe('Europe/Rome');

    Carbon::setTestNow('2026-11-04 10:45:00');
    Api::staff($gettone)->get('/v1/staff/corrente');
    Carbon::setTestNow('2026-11-04 11:16:00');

    expect(esitoDelloStaff(fn () => Api::staff($gettone)->get('/v1/staff/corrente')))->toBe([401, 'gettone_non_valido']);

    Carbon::setTestNow('2026-11-04 12:00:00');
    $gettone = entraComeStaff($sara);
    Api::staff($gettone)->delete('/v1/staff/accessi/corrente');

    expect(esitoDelloStaff(fn () => Api::staff($gettone)->get('/v1/staff/corrente')))->toBe([401, 'gettone_non_valido'])
        ->and(esitoDelloStaff(fn () => Api::staff()->get('/v1/staff/corrente')))->toBe([401, 'gettone_assente']);
});

it('i ruoli: solo il superadmin gestisce lo staff; supporto e fatturazione leggono se stessi e il proprio registro (F1.4, F1.8)', function () {
    personaDelloStaff($this->finto, 'sara@lucianocastro.com');
    $supporto = personaDelloStaff($this->finto, 'sup@lucianocastro.com', 'supporto');
    $gettone = entraComeStaff($supporto);

    Api::staff($gettone)->get('/v1/staff/corrente');
    Api::staff($gettone)->get('/v1/staff/registro');

    // Il supporto legge l'elenco (#3020, sola lettura) con la stessa forma del superadmin; tutto il resto dello staff è del superadmin.
    $elenco = Api::staff($gettone)->get('/v1/staff')['data'];
    expect(array_column($elenco, 'id'))->toContain($supporto['id'])->and(array_keys($elenco[0]))->toBe(['id', 'utente_id', 'nome', 'cognome', 'email', 'ruolo', 'creato_il', 'creato_da', 'ultimo_ingresso']);

    $chiamate = [['get', '/v1/staff/'.$supporto['id']], ['post', '/v1/staff', ['email' => 'x@lucianocastro.com', 'ruolo' => 'supporto']],
        ['patch', '/v1/staff/'.$supporto['id'], ['ruolo' => 'supporto']], ['delete', '/v1/staff/'.$supporto['id']],
        ['post', '/v1/staff/'.$supporto['id'].'/totp/reimpostazione', ['motivo' => 'assistenza']], ['post', '/v1/staff/registro/esportazioni', []]];

    foreach ($chiamate as $chiamata) {
        [$metodo, $percorso] = $chiamata;
        $corpo = $chiamata[2] ?? null;

        expect(esitoDelloStaff(fn () => $corpo === null ? Api::staff($gettone)->$metodo($percorso) : Api::staff($gettone)->$metodo($percorso, $corpo)))->toBe([403, 'permesso_negato']);
    }

    $righe = Api::staff($gettone)->get('/v1/staff/registro')['data'];
    expect(array_unique(array_column($righe, 'staff_id')))->toBe([$supporto['id']]);

    // La fatturazione non legge l'elenco (#3020, SE.2).
    $fatturazione = personaDelloStaff($this->finto, 'fat@lucianocastro.com', 'fatturazione');
    expect(esitoDelloStaff(fn () => Api::staff(entraComeStaff($fatturazione))->get('/v1/staff')))->toBe([403, 'permesso_negato']);
});

it('staff.crea, modifica, elimina e reimpostazione: dominio, ruoli, nessuno su se stesso, ultimo superadmin, gettoni chiusi (F1.4, F1.9)', function () {
    $sara = personaDelloStaff($this->finto, 'sara@lucianocastro.com');
    $gettone = entraComeStaff($sara);
    $this->finto->persona('bruno@castroandpartners.com', PASSWORD, nome: 'Bruno');
    $this->finto->persona('fuori@example.com', PASSWORD);
    $this->finto->persona('plus+x@lucianocastro.com', PASSWORD);
    $this->finto->persona('nonverificata@lucianocastro.com', PASSWORD, verificata: false);
    $crea = fn (string $email, string $ruolo = 'supporto') => esitoDelloStaff(fn () => Api::staff($gettone)->post('/v1/staff', ['email' => $email, 'ruolo' => $ruolo]));

    expect($crea('fuori@example.com'))->toBe([422, 'dominio_non_ammesso'])
        ->and($crea('plus+x@lucianocastro.com'))->toBe([422, 'dominio_non_ammesso'])
        ->and($crea('uat@castroandpartners.com'))->toBe([422, 'dominio_non_ammesso'])
        ->and($crea('nessuno@lucianocastro.com'))->toBe([404, 'non_trovato'])
        ->and($crea('nonverificata@lucianocastro.com'))->toBe([404, 'non_trovato'])
        ->and(esitoDelloStaff(fn () => Api::staff($gettone)->post('/v1/staff', ['email' => 'bruno@castroandpartners.com', 'ruolo' => 'capo'])))->toBe([422, 'dati_non_validi']);

    $bruno = Api::staff($gettone)->post('/v1/staff', ['email' => 'bruno@castroandpartners.com', 'ruolo' => 'supporto'])['data'];

    expect(array_keys($bruno))->toBe(['id', 'utente_id', 'nome', 'cognome', 'email', 'ruolo', 'creato_il', 'creato_da', 'ultimo_ingresso'])
        ->and($bruno['creato_da'])->toBe(['nome' => 'Sara', 'cognome' => null])->and($bruno['ultimo_ingresso'])->toBeNull()
        ->and($crea('bruno@castroandpartners.com'))->toBe([409, 'staff_gia_presente'])
        ->and($this->finto->ultimoInvitoDelloStaff('bruno@castroandpartners.com'))->toBeNull();

    $elenco = Api::staff($gettone)->get('/v1/staff');
    expect(array_column($elenco['data'], 'id'))->toBe([$sara['id'], $bruno['id']])->and($elenco['successivo'])->toBeNull();

    // Nessuno su se stesso; il ruolo si cambia, e lo stesso di prima non scrive niente.
    expect(esitoDelloStaff(fn () => Api::staff($gettone)->patch('/v1/staff/'.$sara['id'], ['ruolo' => 'supporto'])))->toBe([403, 'permesso_negato'])
        ->and(esitoDelloStaff(fn () => Api::staff($gettone)->delete('/v1/staff/'.$sara['id'])))->toBe([403, 'permesso_negato'])
        ->and(esitoDelloStaff(fn () => Api::staff($gettone)->post('/v1/staff/'.$sara['id'].'/totp/reimpostazione', ['motivo' => 'assistenza'])))->toBe([403, 'permesso_negato'])
        ->and(esitoDelloStaff(fn () => Api::staff($gettone)->patch('/v1/staff/'.$bruno['id'], ['ruolo' => 'capo'])))->toBe([422, 'dati_non_validi'])
        ->and(esitoDelloStaff(fn () => Api::staff($gettone)->patch('/v1/staff/'.$bruno['id'], ['ruolo' => 'fatturazione', 'solo_uat' => true])))->toBe([422, 'dati_non_validi'])
        ->and(Api::staff($gettone)->patch('/v1/staff/'.$bruno['id'], ['ruolo' => 'fatturazione'])['data']['ruolo'])->toBe('fatturazione')
        ->and(esitoDelloStaff(fn () => Api::staff($gettone)->get('/v1/staff/nonesiste')))->toBe([404, 'non_trovato']);

    // Togliere una persona dallo staff ne chiude i gettoni e la porta fuori dalle letture.
    $elia = personaDelloStaff($this->finto, 'elia@lucianocastro.com', 'fatturazione');
    $gettoneElia = entraComeStaff($elia);
    Api::staff($gettone)->delete('/v1/staff/'.$elia['id']);

    expect(esitoDelloStaff(fn () => Api::staff($gettoneElia)->get('/v1/staff/corrente')))->toBe([401, 'gettone_non_valido'])
        ->and(esitoDelloStaff(fn () => Api::staff($gettone)->get('/v1/staff/'.$elia['id'])))->toBe([404, 'non_trovato']);

    // Reimpostazione: il motivo è obbligatorio, il secondo fattore va, i gettoni si chiudono, parte un invito nuovo.
    $alba = personaDelloStaff($this->finto, 'alba@lucianocastro.com', 'supporto', soloUat: true);
    $gettoneAlba = entraComeStaff($alba, true);

    // Per una persona che non è di prova (#3445) il secondo fattore non c'è più: 404.
    expect(esitoDelloStaff(fn () => Api::staff($gettone)->post('/v1/staff/'.$bruno['id'].'/totp/reimpostazione', ['motivo' => 'assistenza'])))->toBe([404, 'non_trovato']);

    expect(esitoDelloStaff(fn () => Api::staff($gettone)->post('/v1/staff/'.$alba['id'].'/totp/reimpostazione', [])))->toBe([422, 'dati_non_validi'])
        ->and(esitoDelloStaff(fn () => Api::staff($gettone)->post('/v1/staff/'.$alba['id'].'/totp/reimpostazione', ['motivo' => 'perche'])))->toBe([422, 'dati_non_validi']);

    $dopo = Api::staff($gettone)->post('/v1/staff/'.$alba['id'].'/totp/reimpostazione', ['motivo' => 'problema_tecnico'])['data'];

    expect(array_key_exists('totp_confermato', $dopo))->toBeFalse()->and($this->finto->ultimoInvitoDelloStaff('alba@lucianocastro.com'))->toBeString()
        ->and($this->finto->codiciDiRecuperoDelloStaff($alba))->toBe([])
        ->and(esitoDelloStaff(fn () => Api::staff($gettoneAlba)->get('/v1/staff/corrente')))->toBe([401, 'gettone_non_valido']);
});

it('fallisciLoStaff(): il prossimo metodo risponde con l\'errore preparato, una volta, dopo la guardia e il corpo (F1.7)', function () {
    $sara = personaDelloStaff($this->finto, 'sara@lucianocastro.com');
    $dora = personaDelloStaff($this->finto, 'dora@lucianocastro.com');
    $gettone = entraComeStaff($sara);
    $this->finto->fallisciLoStaff('staff.elimina', 'ultimo_superadmin');

    expect(esitoDelloStaff(fn () => Api::staff($gettone)->delete('/v1/staff/'.$dora['id'])))->toBe([409, 'ultimo_superadmin'])
        ->and(Api::staff($gettone)->get('/v1/staff/'.$dora['id'])['data']['id'])->toBe($dora['id']);

    Api::staff($gettone)->delete('/v1/staff/'.$dora['id']);

    expect(esitoDelloStaff(fn () => Api::staff($gettone)->get('/v1/staff/'.$dora['id'])))->toBe([404, 'non_trovato']);

    $this->finto->fallisciLoStaff('staff.crea', 'servizio_non_disponibile');

    expect(esitoDelloStaff(fn () => Api::staff()->post('/v1/staff', ['email' => 'x@lucianocastro.com', 'ruolo' => 'supporto'])))->toBe([401, 'gettone_assente'])
        ->and(esitoDelloStaff(fn () => Api::staff($gettone)->post('/v1/staff', ['email' => 'x@lucianocastro.com', 'ruolo' => 'supporto'])))->toBe([503, 'servizio_non_disponibile'])
        ->and(fn () => $this->finto->fallisciLoStaff('accessi.crea', 'non_trovato'))->toThrow(InvalidArgumentException::class);
});

it('il perimetro solo_uat: vede e crea solo uat+…@castroandpartners.com, fuori è 404, il registro è il suo (F1.11)', function () {
    $vera = personaDelloStaff($this->finto, 'vera@lucianocastro.com');
    $prova = personaDelloStaff($this->finto, 'uat+zr-superadmin-uno@castroandpartners.com', 'superadmin', soloUat: true);
    $altra = personaDelloStaff($this->finto, 'uat+zr-supporto-1@castroandpartners.com', 'supporto', soloUat: true);
    $gettone = entraComeStaff($prova, true);
    $gettoneVera = entraComeStaff($vera);

    expect(array_column(Api::staff($gettone)->get('/v1/staff')['data'], 'id'))->toBe(array_values(array_filter([$prova['id'], $altra['id']], fn () => true)))
        ->and(array_column(Api::staff($gettoneVera)->get('/v1/staff')['data'], 'id'))->toHaveCount(3)
        ->and(esitoDelloStaff(fn () => Api::staff($gettone)->get('/v1/staff/'.$vera['id'])))->toBe([404, 'non_trovato'])
        ->and(esitoDelloStaff(fn () => Api::staff($gettone)->patch('/v1/staff/'.$vera['id'], ['ruolo' => 'supporto'])))->toBe([404, 'non_trovato'])
        ->and(esitoDelloStaff(fn () => Api::staff($gettone)->delete('/v1/staff/'.$vera['id'])))->toBe([404, 'non_trovato'])
        ->and(Api::staff($gettone)->get('/v1/staff/'.$altra['id'])['data']['ruolo'])->toBe('supporto');

    $this->finto->persona('uat+nuovo@castroandpartners.com', PASSWORD);
    $this->finto->persona('fuori@lucianocastro.com', PASSWORD);

    // Il + vale per chi ha il segno, non per chi viene invitato: dentro il perimetro il dominio lo boccia, fuori «non c'è».
    expect(esitoDelloStaff(fn () => Api::staff($gettone)->post('/v1/staff', ['email' => 'uat+nuovo@castroandpartners.com', 'ruolo' => 'supporto'])))->toBe([422, 'dominio_non_ammesso'])
        ->and(esitoDelloStaff(fn () => Api::staff($gettone)->post('/v1/staff', ['email' => 'fuori@lucianocastro.com', 'ruolo' => 'supporto'])))->toBe([404, 'non_trovato']);

    $registro = Api::staff($gettone)->get('/v1/staff/registro')['data'];

    expect(array_unique(array_column($registro, 'staff_id')))->toBe([$prova['id']]);
});

it('il registro: righe dal più recente a cursore, filtri, nomi risolti alla lettura; l\'esportazione è un CSV e si scrive nel registro (F1.8)', function () {
    $sara = personaDelloStaff($this->finto, 'sara@lucianocastro.com');
    $gettone = entraComeStaff($sara);
    $dora = personaDelloStaff($this->finto, 'dora@lucianocastro.com', 'supporto', soloUat: true);
    Api::staff($gettone)->patch('/v1/staff/'.$dora['id'], ['ruolo' => 'fatturazione']);
    Api::staff($gettone)->post('/v1/staff/'.$dora['id'].'/totp/reimpostazione', ['motivo' => 'sicurezza']);

    $prima = Api::staff($gettone)->get('/v1/staff/registro', ['limite' => 2]);

    expect(array_column($prima['data'], 'azione'))->toBe(['staff.totp.reimpostazione.crea', 'staff.modifica'])
        ->and($prima['data'][0])->toMatchArray(['staff_id' => $sara['id'], 'ruolo' => 'superadmin', 'bersaglio_tipo' => 'staff', 'bersaglio_id' => $dora['id'], 'campi' => ['totp'], 'motivo' => 'sicurezza',
            'ip' => '203.0.113.7', 'impersonificazione_id' => null, 'staff' => ['nome' => 'Sara', 'cognome' => null], 'bersaglio' => ['nome' => 'Dora', 'cognome' => null]])
        ->and(array_keys($prima['data'][0]))->toBe(['posizione', 'id', 'staff_id', 'ruolo', 'azione', 'bersaglio_tipo', 'bersaglio_id', 'campi', 'motivo', 'ip', 'impersonificazione_id', 'quando', 'staff', 'bersaglio']);

    $seconda = Api::staff($gettone)->get('/v1/staff/registro', ['limite' => 2, 'cursore' => $prima['successivo']]);

    expect(array_column($seconda['data'], 'azione'))->toBe(['ingressi.scambio.crea'])->and($seconda['successivo'])->toBeNull()
        ->and(array_column(Api::staff($gettone)->get('/v1/staff/registro', ['azione' => 'staff.modifica'])['data'], 'azione'))->toBe(['staff.modifica'])
        ->and(esitoDelloStaff(fn () => Api::staff($gettone)->get('/v1/staff/registro', ['bersaglio_tipo' => 'altro'])))->toBe([422, 'dati_non_validi']);

    $esportazione = Api::staff($gettone)->scaricaConPost('/v1/staff/registro/esportazioni', corpo: ['azione' => 'staff.modifica']);
    $contenuto = implode('', iterator_to_array($esportazione->blocchi(), false));

    expect($esportazione->tipo)->toBe('text/csv')
        ->and($contenuto)->toStartWith("\xEF\xBB\xBFposizione,id,staff_id,ruolo,azione,bersaglio_tipo,bersaglio_id,campi,motivo,ip,impersonificazione_id,quando\r\n")
        ->and($contenuto)->toContain('staff.modifica')->not->toContain('staff.totp.reimpostazione.crea')
        ->and(Api::staff($gettone)->get('/v1/staff/registro', ['azione' => 'staff.registro.esportazioni.crea'])['data'])->toHaveCount(1);

    // Senza filtri il file va dalla riga più vecchia a quella di questa stessa esportazione, che c'è.
    $tutto = implode('', iterator_to_array(Api::staff($gettone)->scaricaConPost('/v1/staff/registro/esportazioni')->blocchi(), false));
    $azioni = array_map(fn (string $riga) => str_getcsv($riga, escape: '')[4] ?? null, array_slice(explode("\r\n", trim($tutto)), 1));

    expect($azioni[0])->toBe('ingressi.scambio.crea')->and(end($azioni))->toBe('staff.registro.esportazioni.crea');
});

it('il tipo del gettone: lo staff non apre staff.totp.*, l\'enrolment non apre il resto, un gettone di persona non apre niente: tutti 403 (F1.1, F1.10)', function () {
    $sara = personaDelloStaff($this->finto, 'sara@lucianocastro.com');
    $gettone = entraComeStaff($sara);
    $persona = $this->finto->persona('anna@example.com', PASSWORD);
    $accesso = Api::senzaGettone()->post('/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD])['data']['gettone']['gettone'];

    expect(esitoDelloStaff(fn () => Api::staff($gettone)->post('/v1/staff/totp')))->toBe([403, 'permesso_negato'])
        ->and(esitoDelloStaff(fn () => Api::staff($gettone)->post('/v1/staff/totp/conferma', ['codice' => '000000'])))->toBe([403, 'permesso_negato'])
        ->and(esitoDelloStaff(fn () => Api::staff($accesso)->get('/v1/staff/corrente')))->toBe([403, 'permesso_negato'])
        ->and(esitoDelloStaff(fn () => Api::staff('zr_'.str_repeat('a', 48))->get('/v1/staff/corrente')))->toBe([401, 'gettone_non_valido']);
});

it('i codici di recupero hanno il formato del backoffice e si accettano come li scrive una persona: maiuscoli o minuscoli, con o senza trattino (F1.2)', function () {
    $sara = personaDelloStaff($this->finto, 'sara@lucianocastro.com', soloUat: true);
    [$primo, $secondo] = $this->finto->codiciDiRecuperoDelloStaff($sara);
    $verifica = function (string $codice) {
        $sfida = Api::staff()->post('/v1/staff/accessi', ['email' => 'sara@lucianocastro.com', 'password' => PASSWORD])['data']['sfida']['id'];

        return esitoDelloStaff(fn () => Api::staff()->post('/v1/staff/accessi/verifica', ['sfida' => $sfida, 'codice' => $codice]));
    };

    expect($primo)->toMatch('/^[ABCDEFGHJKLMNPQRSTUVWXYZ2-9]{5}-[ABCDEFGHJKLMNPQRSTUVWXYZ2-9]{5}$/')
        ->and($verifica(' '.strtolower(str_replace('-', '', $primo)).' '))->toBe(['ok', null])
        ->and($verifica($primo))->toBe([401, 'secondo_fattore_non_valido'])
        ->and($verifica(strtolower($secondo)))->toBe(['ok', null]);
});

it('invitoDelloStaff() dà un invito nuovo per la persona dello staff e annulla il precedente; una persona che non c\'è è un errore', function () {
    $nuova = personaDelloStaff($this->finto, 'nuova@lucianocastro.com', 'supporto', conTotp: false, soloUat: true);
    $primo = $this->finto->ultimoInvitoDelloStaff('nuova@lucianocastro.com');
    $secondo = $this->finto->invitoDelloStaff($nuova);

    expect($secondo)->toBeString()->not->toBe($primo)
        ->and($this->finto->ultimoInvitoDelloStaff('nuova@lucianocastro.com'))->toBe($secondo)
        ->and(esitoDelloStaff(fn () => Api::staff()->post('/v1/staff/inviti/accettazione', ['segreto' => $primo, 'password' => PASSWORD])))->toBe([401, 'invito_non_valido'])
        ->and(fn () => $this->finto->invitoDelloStaff(['id' => 'inesistente']))->toThrow(LogicException::class);
});

it('dieci codici sbagliati in 24 ore bloccano la persona: poi lo stesso secondo_fattore_non_valido, anche col codice giusto, senza 429 né Retry-After (M2)', function () {
    $sara = personaDelloStaff($this->finto, 'sara@lucianocastro.com', 'superadmin');
    $primoPasso = fn () => Api::staff()->post('/v1/staff/accessi', ['email' => 'sara@lucianocastro.com', 'password' => PASSWORD])['data']['sfida']['id'];

    for ($i = 0; $i < 10; $i++) {
        Carbon::setTestNow(Carbon::now()->addMinutes(16));
        expect(esitoDelloStaff(fn () => Api::staff()->post('/v1/staff/accessi/verifica', ['sfida' => $primoPasso(), 'codice' => '111111'])))->toBe([401, 'secondo_fattore_non_valido']);
    }

    Carbon::setTestNow(Carbon::now()->addMinutes(16));

    expect(esitoDelloStaff(fn () => Api::staff()->post('/v1/staff/accessi/verifica', ['sfida' => $primoPasso(), 'codice' => BackofficeFinto::CODICE_TOTP_DELLO_STAFF])))->toBe([401, 'secondo_fattore_non_valido']);
});

it('staff.utenti.conteggi.mostra e staff.workspace.conteggi.mostra: solo numeri, superadmin e supporto, la fatturazione 403, senza gettone 401, e il perimetro solo_uat conta le sole persone uat+ (#2995)', function () {
    $sara = personaDelloStaff($this->finto, 'sara@lucianocastro.com');
    $supporto = personaDelloStaff($this->finto, 'sup@lucianocastro.com', 'supporto');
    $fatturazione = personaDelloStaff($this->finto, 'fat@lucianocastro.com', 'fatturazione');
    $prova = personaDelloStaff($this->finto, 'prova@lucianocastro.com', 'superadmin', true, true);
    $uat = $this->finto->persona('uat+uno@castroandpartners.com', PASSWORD, nome: 'Prova');
    $vera = $this->finto->persona('vera@example.com', PASSWORD, nome: 'Vera');
    $this->finto->workspace('Studio uat', $uat);
    $this->finto->workspace('Studio vero', $vera);
    $inEliminazione = $this->finto->workspace('Studio che se ne va', $vera);
    $accessoVera = entraNelFinto('vera@example.com')['gettone']['gettone'];
    $gettoneVera = alFinto('POST', '/v1/gettoni', ['workspace_id' => $inEliminazione['id']], $accessoVera)->json('data.gettone');
    expect(alFinto('DELETE', '/v1/workspace', gettone: $gettoneVera)->status())->toBe(202);

    $conta = fn (string $gettone, string $percorso) => Api::staff($gettone)->get($percorso);
    [$gettoneSara, $gettoneSupporto, $gettoneProva, $gettoneFatturazione] = [entraComeStaff($sara), entraComeStaff($supporto), entraComeStaff($prova, true), entraComeStaff($fatturazione)];

    // Tutti: le quattro persone dello staff, `uat+uno` e Vera; Vera non ha chiesto di eliminare l'account, ha programmato l'eliminazione di un workspace.
    expect($conta($gettoneSara, '/v1/staff/utenti/conteggi'))->toBe(['data' => ['tutti' => 6, 'attivo' => 6, 'sospeso' => 0, 'in_eliminazione' => 0]])
        ->and($conta($gettoneSupporto, '/v1/staff/workspace/conteggi'))->toBe(['data' => ['tutti' => 3, 'attivo' => 2, 'in_eliminazione' => 1]])
        // solo_uat: le persone `uat+*` (una) e i workspace il cui proprietario è una di loro (uno).
        ->and($conta($gettoneProva, '/v1/staff/utenti/conteggi'))->toBe(['data' => ['tutti' => 1, 'attivo' => 1, 'sospeso' => 0, 'in_eliminazione' => 0]])
        ->and($conta($gettoneProva, '/v1/staff/workspace/conteggi'))->toBe(['data' => ['tutti' => 1, 'attivo' => 1, 'in_eliminazione' => 0]]);

    foreach (['/v1/staff/utenti/conteggi', '/v1/staff/workspace/conteggi'] as $percorso) {
        expect(esitoDelloStaff(fn () => Api::staff($gettoneFatturazione)->get($percorso)))->toBe([403, 'permesso_negato'])
            ->and(esitoDelloStaff(fn () => Api::staff()->get($percorso)))->toBe([401, 'gettone_assente']);
    }
});

it('i codici di recupero si rigenerano col codice attuale: dieci nuovi, il passo usato non si riusa, per il login normale è 404 (#3443)', function () {
    $sara = personaDelloStaff($this->finto, 'sara@lucianocastro.com', soloUat: true);
    $gettone = entraComeStaff($sara, true);
    $rigenera = fn (string $codice) => esitoDelloStaff(fn () => Api::staff($gettone)->post('/v1/staff/totp/codici-di-recupero', ['codice' => $codice]));

    // L'accesso ha appena usato il passo di adesso: lo stesso codice non vale due volte.
    expect($rigenera('000000'))->toBe([401, 'secondo_fattore_non_valido']);

    Carbon::setTestNow(Carbon::now()->addSeconds(30));
    expect($rigenera('123456'))->toBe([401, 'secondo_fattore_non_valido']);

    $nuovi = Api::staff($gettone)->post('/v1/staff/totp/codici-di-recupero', ['codice' => '000000'])['data']['codici_di_recupero'];

    expect($nuovi)->toHaveCount(10)->and(array_unique($nuovi))->toHaveCount(10)->and($nuovi[0])->toMatch('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/')
        ->and($rigenera('000000'))->toBe([401, 'secondo_fattore_non_valido']);

    $senza = personaDelloStaff($this->finto, 'bruno@lucianocastro.com', conTotp: false);
    $gettoneSenza = entraComeStaff($senza);

    expect(esitoDelloStaff(fn () => Api::staff($gettoneSenza)->post('/v1/staff/totp/codici-di-recupero', ['codice' => '000000'])))->toBe([404, 'non_trovato'])
        ->and(esitoDelloStaff(fn () => Api::staff()->post('/v1/staff/totp/codici-di-recupero', ['codice' => '000000'])))->toBe([401, 'gettone_assente']);
});

it('i codici di recupero: cinque tentativi sbagliati in un\'ora per gettone, poi 429 anche col codice giusto, e l\'ingresso della persona non è toccato (#3443, decisione zr-pm 9/11)', function () {
    $sara = personaDelloStaff($this->finto, 'sara@lucianocastro.com', soloUat: true);
    $gettone = entraComeStaff($sara, true);
    Carbon::setTestNow(Carbon::now()->addSeconds(30));

    foreach (range(1, 5) as $i) {
        expect(esitoDelloStaff(fn () => Api::staff($gettone)->post('/v1/staff/totp/codici-di-recupero', ['codice' => '123456'])))->toBe([401, 'secondo_fattore_non_valido']);
    }

    expect(esitoDelloStaff(fn () => Api::staff($gettone)->post('/v1/staff/totp/codici-di-recupero', ['codice' => '000000'])))->toBe([429, 'troppe_richieste'])
        ->and(entraComeStaff($sara, true))->toBeString();
});
