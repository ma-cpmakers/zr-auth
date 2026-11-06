<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #2791 (zr-board #2767): il Gate nel finto. Qui ciò che un frontend vede dal suo lato (le forme, gli esiti, i 409 nell'ordine, il corpo della
// decisione, le prescrizioni come schede, l'approvatore letto dal CRM); che risposte ed errori siano quelli del backoffice lo prova il FintoTest
// del backoffice, che confronta finto e backoffice sulle stesse chiamate.

/** @return array<string, string> l'intestazione di una creazione (la guardia dei segreti non legge un valore che non è fra virgolette) */
function chiaveDelGate(string $valore): array
{
    return ['Idempotency-Key' => $valore];
}

/**
 * Un workspace con `pm` e `crm`, un Gate e un contatto del CRM: [finto, workspace, Gate, contatto, gettone]. Con `$conApprovatore` il contatto è già
 * collegato al Gate con il ruolo di approvatore.
 *
 * @return array{BackofficeFinto, array<string, mixed>, array<string, mixed>, string, string}
 */
function conUnGate(bool $conApprovatore = false): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD, 'Anna Bianchi');
    $studio = $finto->workspace('Studio', $anna);
    $finto->attivaApp($studio, 'pm', 'crm');
    $gate = $finto->scheda($studio, 'Gate fondazioni', tipo: 'gate');
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');
    $contatto = alFinto('POST', '/v1/crm/persone', ['nome' => 'Marco', 'cognome' => 'Bianchi'], $gettone)->json('data.id');

    if ($conApprovatore) {
        expect(alFinto('POST', "/v1/board/schede/{$gate['id']}/crm", ['tipo' => 'crm.persona', 'id' => $contatto, 'ruolo' => 'approvatore'], $gettone)->status())->toBe(201);
    }

    return [$finto, $studio, $gate, $contatto, $gettone];
}

it('un Gate nasce in_preparazione, senza approvatore né decisione; una scheda che non è un Gate ha esito, approvatore e decisione null', function () {
    [$finto, $studio, $gate, , $gettone] = conUnGate();
    $fase = $finto->scheda($studio, 'Una fase', tipo: 'fase');

    $letta = alFinto('GET', "/v1/board/schede/{$gate['id']}", null, $gettone)->json('data');
    $altra = alFinto('GET', "/v1/board/schede/{$fase['id']}", null, $gettone)->json('data');

    expect(array_slice(array_keys($letta), 9, 5))->toBe(['copertina', 'esito', 'approvatore', 'decisione', 'tipo'])
        ->and([$letta['esito'], $letta['approvatore'], $letta['decisione']])->toBe(['in_preparazione', null, null])
        ->and([$altra['esito'], $altra['approvatore'], $altra['decisione']])->toBe([null, null, null]);
});

it('il ruolo approvatore: 201 col ruolo, il Gate porta l\'approvatore col titolo del CRM, un secondo è 409 approvatore_gia_indicato, e solo un contatto su un Gate è ammesso (422 su #/ruolo)', function () {
    [$finto, $studio, $gate, $contatto, $gettone] = conUnGate();
    $altro = alFinto('POST', '/v1/crm/persone', ['nome' => 'Elena'], $gettone)->json('data.id');
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettone)->json('data.id');
    $fase = $finto->scheda($studio, 'Una fase', tipo: 'fase');

    $primo = alFinto('POST', "/v1/board/schede/{$gate['id']}/crm", ['tipo' => 'crm.persona', 'id' => $contatto, 'ruolo' => 'approvatore'], $gettone);
    $normale = alFinto('POST', "/v1/board/schede/{$gate['id']}/crm", ['tipo' => 'crm.persona', 'id' => $altro], $gettone);

    expect([$primo->status(), $primo->json('data.ruolo'), $normale->status(), $normale->json('data.ruolo')])->toBe([201, 'approvatore', 201, null])
        ->and(alFinto('GET', "/v1/board/schede/{$gate['id']}", null, $gettone)->json('data.approvatore'))->toBe(['persona_id' => $contatto, 'titolo' => 'Marco Bianchi']);

    $secondo = alFinto('POST', "/v1/board/schede/{$gate['id']}/crm", ['tipo' => 'crm.persona', 'id' => $altro, 'ruolo' => 'approvatore'], $gettone);
    expect([$secondo->status(), $secondo->json('codice')])->toBe([409, 'record_gia_collegato']);
    $terzo = alFinto('POST', '/v1/crm/persone', ['nome' => 'Luca'], $gettone)->json('data.id');
    $terzoCollegato = alFinto('POST', "/v1/board/schede/{$gate['id']}/crm", ['tipo' => 'crm.persona', 'id' => $terzo, 'ruolo' => 'approvatore'], $gettone);
    expect([$terzoCollegato->status(), $terzoCollegato->json('codice')])->toBe([409, 'approvatore_gia_indicato']);

    foreach ([
        [$gate['id'], ['tipo' => 'crm.organizzazione', 'id' => $azienda, 'ruolo' => 'approvatore']],
        [$fase['id'], ['tipo' => 'crm.persona', 'id' => $contatto, 'ruolo' => 'approvatore']],
        [$gate['id'], ['tipo' => 'crm.persona', 'id' => $contatto, 'ruolo' => 'direttore']],
    ] as [$scheda, $corpo]) {
        $rifiuto = alFinto('POST', "/v1/board/schede/{$scheda}/crm", $corpo, $gettone);
        expect([$rifiuto->status(), $rifiuto->json('errors.0.pointer')])->toBe([422, '#/ruolo']);
    }

    // Tolto il collegamento, se ne indica un altro.
    expect(alFinto('DELETE', "/v1/board/schede/{$gate['id']}/crm/".$primo->json('data.id'), null, $gettone)->status())->toBe(204)
        ->and(alFinto('POST', "/v1/board/schede/{$gate['id']}/crm", ['tipo' => 'crm.persona', 'id' => $terzo, 'ruolo' => 'approvatore'], $gettone)->status())->toBe(201);
});

it('la richiesta porta il Gate in approvazione (esito, richiesto_il, richiesto_da); senza approvatore è 409 gate_senza_approvatore, su una scheda che non è un Gate 409 scheda_non_gate', function () {
    [$finto, $studio, $gate, , $gettone] = conUnGate();
    $fase = $finto->scheda($studio, 'Una fase', tipo: 'fase');

    expect(alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/richiesta", null, $gettone)->json('codice'))->toBe('gate_senza_approvatore')
        ->and(alFinto('POST', "/v1/board/schede/{$fase['id']}/gate/richiesta", null, $gettone)->json('codice'))->toBe('scheda_non_gate');

    $gate = $finto->scheda($studio, 'Gate con approvatore', tipo: 'gate');
    $contatto = alFinto('POST', '/v1/crm/persone', ['nome' => 'Marco'], $gettone)->json('data.id');
    alFinto('POST', "/v1/board/schede/{$gate['id']}/crm", ['tipo' => 'crm.persona', 'id' => $contatto, 'ruolo' => 'approvatore'], $gettone);
    $richiesta = alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/richiesta", null, $gettone);

    expect($richiesta->status())->toBe(200)
        ->and(array_keys($richiesta->json('data')))->toBe(['esito', 'richiesto_il', 'richiesto_da'])
        ->and($richiesta->json('data.esito'))->toBe('in_approvazione')
        ->and($richiesta->json('data.richiesto_da.nome'))->toBe('Anna Bianchi')
        ->and(alFinto('GET', "/v1/board/schede/{$gate['id']}", null, $gettone)->json('data.esito'))->toBe('in_approvazione')
        ->and(alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/richiesta", null, $gettone)->json('codice'))->toBe('gate_gia_richiesto');
});

it('con la stessa Idempotency-Key la richiesta non si fa due volte e ripete la risposta', function () {
    [, , $gate, , $gettone] = conUnGate(true);

    $prima = alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/richiesta", null, $gettone, null, chiaveDelGate('gate-1'));
    $ancora = alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/richiesta", null, $gettone, null, chiaveDelGate('gate-1'));

    expect([$prima->status(), $ancora->status()])->toBe([200, 200])
        ->and($ancora->body())->toBe($prima->body());
});

it('la decisione: 200 con esito, deciso_il, deciso_da, per_conto_di e motivo; la scheda dice esito e decisione; senza richiesta è 409 gate_non_richiesto; poi la richiesta è 409 gate_deciso', function () {
    [, , $gate, $contatto, $gettone] = conUnGate(true);

    expect(alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/decisione", ['decisione' => 'approvato'], $gettone)->json('codice'))->toBe('gate_non_richiesto');
    alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/richiesta", null, $gettone);

    $decisione = alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/decisione", ['decisione' => 'approvato'], $gettone);

    expect($decisione->status())->toBe(200)
        ->and(array_keys($decisione->json('data')))->toBe(['esito', 'deciso_il', 'deciso_da', 'per_conto_di', 'motivo', 'prescrizioni'])
        ->and($decisione->json('data'))->toMatchArray(['esito' => 'approvato', 'motivo' => null, 'prescrizioni' => [], 'per_conto_di' => ['persona_id' => $contatto, 'titolo' => 'Marco Bianchi']]);
    $letta = alFinto('GET', "/v1/board/schede/{$gate['id']}", null, $gettone)->json('data');
    expect($letta['esito'])->toBe('approvato')
        ->and(array_keys($letta['decisione']))->toBe(['decisione', 'deciso_il', 'deciso_da', 'per_conto_di', 'motivo'])
        ->and($letta['decisione']['deciso_da']['nome'])->toBe('Anna Bianchi')
        ->and(alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/richiesta", null, $gettone)->json('codice'))->toBe('gate_deciso');
});

it('il corpo della decisione: decisione, motivo (obbligatorio con con_prescrizioni e respinto) e prescrizioni (vietate con le altre decisioni) sono 422 sul campo', function (array $corpo, string $pointer) {
    [, , $gate, , $gettone] = conUnGate(true);
    alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/richiesta", null, $gettone);

    $risposta = alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/decisione", $corpo, $gettone);

    expect([$risposta->status(), $risposta->json('errors.0.pointer')])->toBe([422, $pointer])
        ->and(alFinto('GET', "/v1/board/schede/{$gate['id']}", null, $gettone)->json('data.esito'))->toBe('in_approvazione');
})->with([
    'senza decisione' => [[], '#/decisione'],
    'decisione sconosciuta' => [['decisione' => 'forse'], '#/decisione'],
    'respinto senza motivo' => [['decisione' => 'respinto'], '#/motivo'],
    'motivo troppo lungo' => [['decisione' => 'respinto', 'motivo' => str_repeat('a', 2001)], '#/motivo'],
    'con_prescrizioni senza prescrizioni' => [['decisione' => 'con_prescrizioni', 'motivo' => 'Sì, ma.'], '#/prescrizioni'],
    'più di venti prescrizioni' => [['decisione' => 'con_prescrizioni', 'motivo' => 'Sì, ma.', 'prescrizioni' => array_fill(0, 21, 'Una')], '#/prescrizioni'],
    'prescrizione troppo lunga' => [['decisione' => 'con_prescrizioni', 'motivo' => 'Sì, ma.', 'prescrizioni' => [str_repeat('a', 501)]], '#/prescrizioni/0'],
    'prescrizioni con approvato' => [['decisione' => 'approvato', 'prescrizioni' => ['Una']], '#/prescrizioni'],
    'campo estraneo' => [['decisione' => 'approvato', 'colore' => 'rosso'], '#/colore'],
]);

it('con_prescrizioni: le prescrizioni nascono come schede della board col numero successivo; una nuova richiesta dopo respinto o con_prescrizioni azzera la decisione', function () {
    [, , $gate, , $gettone] = conUnGate(true);
    alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/richiesta", null, $gettone);

    $decisione = alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/decisione", ['decisione' => 'con_prescrizioni', 'motivo' => 'Quasi.', 'prescrizioni' => ['Eseguire le prove', 'Allegare il report']], $gettone);

    $nate = $decisione->json('data.prescrizioni');
    expect($nate)->toHaveCount(2)
        ->and(array_keys($nate[0]))->toBe(['id', 'scheda_id']);
    $prima = alFinto('GET', "/v1/board/schede/{$nate[0]['scheda_id']}", null, $gettone)->json('data');
    $seconda = alFinto('GET', "/v1/board/schede/{$nate[1]['scheda_id']}", null, $gettone)->json('data');
    expect([$prima['titolo'], $prima['numero'], $seconda['titolo'], $seconda['numero']])->toBe(['Eseguire le prove', 2, 'Allegare il report', 3])
        ->and($prima['tipo'])->toBeNull()
        ->and(alFinto('GET', "/v1/board/schede/{$gate['id']}", null, $gettone)->json('data.decisione.motivo'))->toBe('Quasi.');

    expect(alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/richiesta", null, $gettone)->status())->toBe(200);
    $riaperto = alFinto('GET', "/v1/board/schede/{$gate['id']}", null, $gettone)->json('data');
    expect([$riaperto['esito'], $riaperto['decisione']])->toBe(['in_approvazione', null]);
});

it('il contatto anonimizzato o scollegato dopo la richiesta: 409 approvatore_non_disponibile e nessuna decisione; prima della richiesta, gate_senza_approvatore', function (string $caso) {
    [, , $gate, $contatto, $gettone] = conUnGate(true);
    alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/richiesta", null, $gettone);
    $schede = count(alFinto('GET', '/v1/board/schede?board_id='.alFinto('GET', "/v1/board/schede/{$gate['id']}", null, $gettone)->json('data.board_id'), null, $gettone)->json('data'));

    // Con la richiesta aperta il collegamento dell'approvatore si toglie solo se il contatto non c'è più (gate_in_approvazione): `scollegato` è l'anonimizzato che poi si toglie.
    expect(alFinto('POST', "/v1/crm/persone/{$contatto}/anonimizzazione", null, $gettone)->status())->toBeLessThan(300);

    if ($caso === 'scollegato') {
        $collegamento = alFinto('GET', "/v1/board/schede/{$gate['id']}/crm", null, $gettone)->json('data.0.id');
        expect(alFinto('DELETE', "/v1/board/schede/{$gate['id']}/crm/{$collegamento}", null, $gettone)->status())->toBe(204);
    }

    $risposta = alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/decisione", ['decisione' => 'con_prescrizioni', 'motivo' => 'Quasi.', 'prescrizioni' => ['Una']], $gettone);

    expect([$risposta->status(), $risposta->json('codice')])->toBe([409, 'approvatore_non_disponibile'])
        ->and(count(alFinto('GET', '/v1/board/schede?board_id='.alFinto('GET', "/v1/board/schede/{$gate['id']}", null, $gettone)->json('data.board_id'), null, $gettone)->json('data')))->toBe($schede)
        ->and(alFinto('GET', "/v1/board/schede/{$gate['id']}", null, $gettone)->json('data.esito'))->toBe('in_approvazione');
})->with(['scollegato', 'anonimizzato']);

it('l\'ordine dei controlli della decisione: lo stato prima del corpo (409 gate_non_richiesto anche con un corpo non valido), il corpo prima dell\'approvatore (422 anche se non c\'è più)', function () {
    [, , $gate, $contatto, $gettone] = conUnGate(true);
    $nonValido = ['decisione' => 'forse'];

    $senzaRichiesta = alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/decisione", $nonValido, $gettone);
    expect([$senzaRichiesta->status(), $senzaRichiesta->json('codice')])->toBe([409, 'gate_non_richiesto']);

    alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/richiesta", null, $gettone);
    expect(alFinto('POST', "/v1/crm/persone/{$contatto}/anonimizzazione", null, $gettone)->status())->toBeLessThan(300);

    $senzaApprovatore = alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/decisione", $nonValido, $gettone);
    expect($senzaApprovatore->status())->toBe(422);
});

it('lo spostamento di una scheda risponde con avvisi vuoto: il finto ha una lista sola per board e non salta nessun Gate', function () {
    [$finto, $studio, $gate, , $gettone] = conUnGate(true);
    $scheda = $finto->scheda($studio, 'Una scheda');

    $risposta = alFinto('POST', "/v1/board/schede/{$scheda['id']}/spostamento", ['lista_id' => 'lista-di-prova', 'posizione' => 'cima'], $gettone);

    expect($risposta->status())->toBe(200)
        ->and(array_keys($risposta->json()))->toBe(['data', 'sbloccate', 'avvisi'])
        ->and($risposta->json('avvisi'))->toBe([]);
});

it('con la richiesta aperta e l\'approvatore disponibile il suo collegamento non si cambia né si toglie: 409 gate_in_approvazione; se il contatto non c\'è più si sostituisce', function () {
    [, , $gate, $contatto, $gettone] = conUnGate(true);
    $altro = alFinto('POST', '/v1/crm/persone', ['nome' => 'Elena'], $gettone)->json('data.id');
    alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/richiesta", null, $gettone);
    $collegamento = alFinto('GET', "/v1/board/schede/{$gate['id']}/crm", null, $gettone)->json('data.0.id');

    $nuovo = alFinto('POST', "/v1/board/schede/{$gate['id']}/crm", ['tipo' => 'crm.persona', 'id' => $altro, 'ruolo' => 'approvatore'], $gettone);
    $tolto = alFinto('DELETE', "/v1/board/schede/{$gate['id']}/crm/{$collegamento}", null, $gettone);

    expect([$nuovo->status(), $nuovo->json('codice'), $tolto->status(), $tolto->json('codice')])->toBe([409, 'gate_in_approvazione', 409, 'gate_in_approvazione']);

    // Un collegamento senza il ruolo si aggiunge e si toglie anche con la richiesta aperta.
    $semplice = alFinto('POST', "/v1/board/schede/{$gate['id']}/crm", ['tipo' => 'crm.persona', 'id' => $altro], $gettone);
    expect($semplice->status())->toBe(201)
        ->and(alFinto('DELETE', "/v1/board/schede/{$gate['id']}/crm/{$semplice->json('data.id')}", null, $gettone)->status())->toBe(204);

    expect(alFinto('POST', "/v1/crm/persone/{$contatto}/anonimizzazione", null, $gettone)->status())->toBeLessThan(300);
    // Anonimizzato ma ancora collegato: il collegamento nuovo con il ruolo è il doppione dell'approvatore, non il vincolo della richiesta.
    $doppione = alFinto('POST', "/v1/board/schede/{$gate['id']}/crm", ['tipo' => 'crm.persona', 'id' => $altro, 'ruolo' => 'approvatore'], $gettone);
    expect([$doppione->status(), $doppione->json('codice')])->toBe([409, 'approvatore_gia_indicato'])
        ->and(alFinto('DELETE', "/v1/board/schede/{$gate['id']}/crm/{$collegamento}", null, $gettone)->status())->toBe(204)
        ->and(alFinto('POST', "/v1/board/schede/{$gate['id']}/crm", ['tipo' => 'crm.persona', 'id' => $altro, 'ruolo' => 'approvatore'], $gettone)->status())->toBe(201);
});

it('l\'annullamento (#3206): 200 con il solo esito di prima e la decisione di prima; senza richiesta è 409 gate_non_richiesto; poi si rifà la richiesta', function () {
    [, , $gate, , $gettone] = conUnGate(true);
    $annulla = fn () => alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/annullamento", null, $gettone);

    expect($annulla()->json('codice'))->toBe('gate_non_richiesto');

    alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/richiesta", null, $gettone);
    alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/decisione", ['decisione' => 'respinto', 'motivo' => 'No.'], $gettone);
    alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/richiesta", null, $gettone);
    $risposta = $annulla();

    expect($risposta->status())->toBe(200)
        ->and($risposta->json())->toBe(['data' => ['esito' => 'respinto']])
        ->and(alFinto('GET', "/v1/board/schede/{$gate['id']}", null, $gettone)->json('data.decisione.decisione'))->toBe('respinto')
        ->and($annulla()->json('codice'))->toBe('gate_non_richiesto');

    $nuovo = alFinto('POST', '/v1/board/schede/'.$gate['id'].'/gate/richiesta', null, $gettone);
    expect($nuovo->status())->toBe(200)
        ->and($annulla()->json('data.esito'))->toBe('respinto');
});

it('l\'annullamento di un Gate mai deciso lo riporta in_preparazione', function () {
    [, , $gate, , $gettone] = conUnGate(true);
    alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/richiesta", null, $gettone);

    expect(alFinto('POST', "/v1/board/schede/{$gate['id']}/gate/annullamento", null, $gettone)->json('data.esito'))->toBe('in_preparazione')
        ->and(alFinto('GET', "/v1/board/schede/{$gate['id']}", null, $gettone)->json('data.decisione'))->toBeNull();
});
