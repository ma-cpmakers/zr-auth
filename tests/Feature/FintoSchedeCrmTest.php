<?php

use Illuminate\Http\Client\Response;
use Zeiras\Auth\Testing\BackofficeFinto;

// #2419 (zr-board #2418): i collegamenti di una scheda a un record del CRM nel finto. Qui ciò che un frontend vede dal suo lato (la
// forma, i testi, i tetti, il doppione, il motivo di un record che non si legge); che le risposte e gli errori siano quelli del
// backoffice lo prova il FintoTest del backoffice, che confronta finto e backoffice sulle stesse chiamate.

/**
 * Un workspace con `pm` e `crm` attive e `$quante` schede: [finto, schede, gettone del workspace].
 *
 * @return array{BackofficeFinto, list<array<string, mixed>>, string}
 */
function conLeSchedeECrm(int $quante = 2): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio', $anna);
    $finto->attivaApp($studio, 'pm', 'crm');
    $schede = array_map(fn (int $n) => $finto->scheda($studio, "Scheda {$n}"), range(1, $quante));
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');

    return [$finto, $schede, $gettone];
}

/** Un contatto del CRM, con `$campi` oltre al nome: il suo id. */
function contattoDelFinto(string $gettone, string $nome, array $campi = []): string
{
    $risposta = alFinto('POST', '/v1/crm/persone', ['nome' => $nome, ...$campi], $gettone);
    expect($risposta->status())->toBe(201);

    return $risposta->json('data.id');
}

/** Il collegamento di una scheda a un record; con `$stato` ne controlla lo stato. */
function collegaNelFinto(string $gettone, array $scheda, string $tipo, string $id, ?int $stato = null): Response
{
    $risposta = alFinto('POST', "/v1/board/schede/{$scheda['id']}/crm", ['tipo' => $tipo, 'id' => $id], $gettone);

    if ($stato !== null) {
        expect($risposta->status())->toBe($stato);
    }

    return $risposta;
}

it('collega un contatto: 201, la Location, la forma del backoffice con titolo e contesto di oggi; il doppione è 409 record_gia_collegato', function () {
    [, [$scheda], $gettone] = conLeSchedeECrm(1);
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettone)->json('data.id');
    $persona = contattoDelFinto($gettone, 'Giulia', ['cognome' => 'Verdi', 'ruolo' => 'CEO', 'organizzazione_id' => $azienda]);

    $prima = collegaNelFinto($gettone, $scheda, 'crm.persona', $persona, 201);

    expect($prima->header('Location'))->toBe("/v1/board/schede/{$scheda['id']}/crm/".$prima->json('data.id'))
        ->and(array_keys($prima->json('data')))->toBe(['id', 'tipo', 'record_id', 'titolo', 'contesto', 'motivo', 'indirizzo', 'creato_il', 'creato_da'])
        ->and($prima->json('data'))->toMatchArray([
            'tipo' => 'crm.persona', 'record_id' => $persona, 'titolo' => 'Giulia Verdi',
            'contesto' => ['organizzazione' => 'Rossi Srl', 'ruolo' => 'CEO'], 'motivo' => null, 'indirizzo' => "/v1/crm/persone/{$persona}",
        ]);

    $doppione = collegaNelFinto($gettone, $scheda, 'crm.persona', $persona, 409);
    expect($doppione->json('codice'))->toBe('record_gia_collegato');
    expect(collect(alFinto('GET', "/v1/board/schede/{$scheda['id']}/crm", null, $gettone)->json('data'))->pluck('record_id')->all())->toBe([$persona]);
});

it('collega un\'azienda (contesto null) e un\'opportunità (pipeline, fase, esito); l\'elenco li dà nell\'ordine di nascita, a cursore', function () {
    [, [$scheda, $altra], $gettone] = conLeSchedeECrm(2);
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettone)->json('data.id');
    $pipeline = alFinto('POST', '/v1/crm/pipeline', ['nome' => 'Vendite', 'fasi' => [['nome' => 'Contatto', 'probabilita' => 10]]], $gettone)->json('data.id');
    $trattativa = alFinto('POST', '/v1/crm/trattative', ['titolo' => 'Fornitura', 'pipeline_id' => $pipeline], $gettone)->json('data.id');

    $a = collegaNelFinto($gettone, $scheda, 'crm.organizzazione', $azienda, 201);
    $t = collegaNelFinto($gettone, $scheda, 'crm.trattativa', $trattativa, 201);

    expect($a->json('data.titolo'))->toBe('Rossi Srl')->and($a->json('data.contesto'))->toBeNull()
        ->and($t->json('data.titolo'))->toBe('Fornitura')
        ->and($t->json('data.contesto'))->toBe(['pipeline' => 'Vendite', 'fase' => 'Contatto', 'esito' => 'aperta']);

    $prima = alFinto('GET', "/v1/board/schede/{$scheda['id']}/crm?limite=1", null, $gettone);
    expect($prima->json('data.0.id'))->toBe($a->json('data.id'))->and($prima->json('successivo'))->not->toBeNull();
    $seconda = alFinto('GET', "/v1/board/schede/{$scheda['id']}/crm?limite=1&cursore=".$prima->json('successivo'), null, $gettone);
    expect($seconda->json('data.0.id'))->toBe($t->json('data.id'))->and($seconda->json('successivo'))->toBeNull()
        ->and(alFinto('GET', "/v1/board/schede/{$altra['id']}/crm", null, $gettone)->json('data'))->toBe([]);
});

it('un id che non c\'è nel workspace (inventato o di un altro) è 422 su #/id con lo stesso testo; il corpo sbagliato è 422 sul suo campo', function () {
    [$finto, [$scheda], $gettone] = conLeSchedeECrm(1);
    $altro = $finto->persona('bruno@example.com', PASSWORD, 'Bruno');
    $altroStudio = $finto->workspace('Altro', $altro);
    $finto->attivaApp($altroStudio, 'crm');
    $gettoneAltro = alFinto('POST', '/v1/gettoni', ['workspace_id' => $altroStudio['id']], entraNelFinto('bruno@example.com')['gettone']['gettone'])->json('data.gettone');
    $estraneo = contattoDelFinto($gettoneAltro, 'Estraneo');

    $inventato = collegaNelFinto($gettone, $scheda, 'crm.persona', '01k6w9a1c3e5g7j9m1p3r5t7v9', 422);
    $dellAltro = collegaNelFinto($gettone, $scheda, 'crm.persona', $estraneo, 422);

    expect($inventato->json('errors.0.pointer'))->toBe('#/id')
        ->and($inventato->json('errors.0.detail'))->toBe($dellAltro->json('errors.0.detail'))
        ->and($dellAltro->json('errors.0.regola'))->toBe('non_trovato');
    expect(collegaNelFinto($gettone, $scheda, 'crm.cliente', $estraneo, 422)->json('errors.0.pointer'))->toBe('#/tipo');
    expect(collegaNelFinto($gettone, $scheda, 'crm.persona', 'non-un-ulid', 422)->json('errors.0.pointer'))->toBe('#/id');
    expect(alFinto('POST', "/v1/board/schede/{$scheda['id']}/crm", ['tipo' => 'crm.persona'], $gettone)->json('errors.0.pointer'))->toBe('#/id');
});

it('il cinquantunesimo collegamento vivo è 409 limite_raggiunto con limite 50; eliminarne uno libera il posto', function () {
    [, [$scheda], $gettone] = conLeSchedeECrm(1);
    $primo = null;

    foreach (range(1, 50) as $n) {
        $risposta = collegaNelFinto($gettone, $scheda, 'crm.persona', contattoDelFinto($gettone, "Contatto {$n}"), 201);
        $primo ??= $risposta->json('data.id');
    }

    $fermata = collegaNelFinto($gettone, $scheda, 'crm.persona', contattoDelFinto($gettone, 'Contatto 51'), 409);
    expect($fermata->json('codice'))->toBe('limite_raggiunto')->and($fermata->json('limite'))->toBe(50);

    expect(alFinto('DELETE', "/v1/board/schede/{$scheda['id']}/crm/{$primo}", null, $gettone)->status())->toBe(204);
    collegaNelFinto($gettone, $scheda, 'crm.persona', contattoDelFinto($gettone, 'Contatto 52'), 201);
});

it('elimina toglie il collegamento (204): non è più in lista, non si elimina di nuovo (404) e lo stesso record si ricollega', function () {
    [, [$scheda, $altra], $gettone] = conLeSchedeECrm(2);
    $persona = contattoDelFinto($gettone, 'Giulia');
    $id = collegaNelFinto($gettone, $scheda, 'crm.persona', $persona, 201)->json('data.id');

    expect(alFinto('DELETE', "/v1/board/schede/{$altra['id']}/crm/{$id}", null, $gettone)->status())->toBe(404);
    expect(alFinto('DELETE', "/v1/board/schede/{$scheda['id']}/crm/{$id}", null, $gettone)->status())->toBe(204);
    expect(alFinto('GET', "/v1/board/schede/{$scheda['id']}/crm", null, $gettone)->json('data'))->toBe([])
        ->and(alFinto('DELETE', "/v1/board/schede/{$scheda['id']}/crm/{$id}", null, $gettone)->status())->toBe(404);
    collegaNelFinto($gettone, $scheda, 'crm.persona', $persona, 201);
});

it('una scheda archiviata si legge ma non si collega né si scollega (409 scheda_archiviata); una scheda che non c\'è è 404', function () {
    [$finto, [$scheda], $gettone] = conLeSchedeECrm(1);
    $persona = contattoDelFinto($gettone, 'Giulia');
    $id = collegaNelFinto($gettone, $scheda, 'crm.persona', $persona, 201)->json('data.id');

    $finto->segnaScheda($scheda, 'archiviata');

    expect(collegaNelFinto($gettone, $scheda, 'crm.persona', contattoDelFinto($gettone, 'Marco'))->json('codice'))->toBe('scheda_archiviata')
        ->and(alFinto('DELETE', "/v1/board/schede/{$scheda['id']}/crm/{$id}", null, $gettone)->json('codice'))->toBe('scheda_archiviata')
        ->and(alFinto('GET', "/v1/board/schede/{$scheda['id']}/crm", null, $gettone)->json('data.0.record_id'))->toBe($persona)
        ->and(alFinto('GET', '/v1/board/schede/01k6w9a1c3e5g7j9m1p3r5t7v9/crm', null, $gettone)->status())->toBe(404);
});

it('un contatto anonimizzato resta nell\'elenco con titolo e contesto null e il motivo anonimizzato', function () {
    [, [$scheda], $gettone] = conLeSchedeECrm(1);
    $persona = contattoDelFinto($gettone, 'Giulia');
    collegaNelFinto($gettone, $scheda, 'crm.persona', $persona, 201);

    expect(alFinto('POST', "/v1/crm/persone/{$persona}/anonimizzazione", null, $gettone)->status())->toBeLessThan(300);

    expect(alFinto('GET', "/v1/board/schede/{$scheda['id']}/crm", null, $gettone)->json('data.0'))
        ->toMatchArray(['record_id' => $persona, 'titolo' => null, 'contesto' => null, 'motivo' => 'anonimizzato']);
});

it('con l\'app crm spenta: l\'elenco tiene i collegamenti con motivo non_leggibile, collegare è 403 app_non_attiva; senza pm è 403 app_non_attiva', function () {
    [, [$scheda], $gettone] = conLeSchedeECrm(1);
    $persona = contattoDelFinto($gettone, 'Giulia');
    collegaNelFinto($gettone, $scheda, 'crm.persona', $persona, 201);

    expect(alFinto('PATCH', '/v1/app/crm', ['stato' => 'disponibile'], $gettone)->status())->toBe(200);

    expect(alFinto('GET', "/v1/board/schede/{$scheda['id']}/crm", null, $gettone)->json('data.0'))
        ->toMatchArray(['record_id' => $persona, 'titolo' => null, 'contesto' => null, 'motivo' => 'non_leggibile'])
        ->and(collegaNelFinto($gettone, $scheda, 'crm.persona', $persona)->json('codice'))->toBe('app_non_attiva');

    expect(alFinto('PATCH', '/v1/app/pm', ['stato' => 'disponibile'], $gettone)->status())->toBe(200);
    expect(alFinto('GET', "/v1/board/schede/{$scheda['id']}/crm", null, $gettone)->json('codice'))->toBe('app_non_attiva');
});

it('la Idempotency-Key ripete lo stesso 201 senza un secondo collegamento; con un corpo diverso è 422 chiave_idempotenza_riusata', function () {
    [, [$scheda], $gettone] = conLeSchedeECrm(1);
    $persona = contattoDelFinto($gettone, 'Giulia');
    $altra = contattoDelFinto($gettone, 'Marco');
    $percorso = "/v1/board/schede/{$scheda['id']}/crm";

    $uno = alFinto('POST', $percorso, ['tipo' => 'crm.persona', 'id' => $persona], $gettone, null, ['Idempotency-Key' => 'chiave-1']);
    $due = alFinto('POST', $percorso, ['tipo' => 'crm.persona', 'id' => $persona], $gettone, null, ['Idempotency-Key' => 'chiave-1']);

    expect($uno->status())->toBe(201)->and($due->status())->toBe(201)->and($due->json('data.id'))->toBe($uno->json('data.id'));
    expect(count(alFinto('GET', $percorso, null, $gettone)->json('data')))->toBe(1);
    expect(alFinto('POST', $percorso, ['tipo' => 'crm.persona', 'id' => $altra], $gettone, null, ['Idempotency-Key' => 'chiave-1'])->json('codice'))->toBe('chiave_idempotenza_riusata');
});

it('il sessantunesimo collegamento in un minuto è 429 troppo_richieste (freno per persona)', function () {
    [, [$scheda], $gettone] = conLeSchedeECrm(1);
    $persona = contattoDelFinto($gettone, 'Giulia');
    collegaNelFinto($gettone, $scheda, 'crm.persona', $persona, 201);

    foreach (range(1, 59) as $_) {
        expect(collegaNelFinto($gettone, $scheda, 'crm.persona', $persona)->status())->toBe(409);
    }

    expect(collegaNelFinto($gettone, $scheda, 'crm.persona', $persona)->status())->toBe(429);
});
