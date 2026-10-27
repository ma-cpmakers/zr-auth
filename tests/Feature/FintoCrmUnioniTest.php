<?php

// L'unione dei contatti nel finto (#2752, CRM-01 F8): ciò che un frontend vede dal suo lato. Che le risposte e gli errori siano quelli del
// backoffice lo prova il suo FintoTest (confronta finto e backoffice sulle stesse chiamate).

/** Due contatti che sembrano lo stesso, con note e attività su chi si unisce: [$gettoni, id di chi resta, id di chi si unisce, finto, workspace]. */
function duePersoneDoppie(): array
{
    [$finto, $gettoni, $studio] = studioColCrm();
    $resta = alFinto('POST', '/v1/crm/persone', ['nome' => 'Anna', 'cognome' => 'Rossi', 'ruolo' => 'Direttrice', 'email' => [['indirizzo' => 'anna@example.com', 'etichetta' => 'lavoro']], 'telefoni' => [['numero' => '+39 02 1234567', 'etichetta' => 'lavoro']]], $gettoni['carla'])->json('data.id');
    $unito = alFinto('POST', '/v1/crm/persone', ['nome' => 'Anna', 'cognome' => 'Rossi', 'ruolo' => 'CEO', 'email' => [['indirizzo' => 'a.rossi@example.com', 'etichetta' => 'lavoro']], 'telefoni' => [['numero' => '+39 02 1234567', 'etichetta' => 'altro']]], $gettoni['carla'])->json('data.id');
    alFinto('POST', '/v1/crm/note', ['persona_id' => $unito, 'testo' => 'Della prima'], $gettoni['carla']);
    alFinto('POST', '/v1/crm/attivita', ['tipo' => 'compito', 'titolo' => 'Richiamare', 'persona_id' => $unito], $gettoni['carla']);

    return [$gettoni, $resta, $unito, $finto, $studio];
}

/** Il corpo di un'unione, con le versioni che i due contatti hanno adesso. */
function corpoDellUnione(array $gettoni, string $resta, string $unito, array $in = []): array
{
    $versioni = [];

    foreach ([$resta, $unito] as $contatto) {
        $versioni[$contatto] = alFinto('GET', "/v1/crm/persone/{$contatto}", null, $gettoni['anna'])->header('ETag');
    }

    return ['resta_id' => $resta, 'unito_id' => $unito, 'versioni' => $versioni] + $in;
}

it('i doppioni sono le coppie di contatti con lo stesso telefono o lo stesso nome, e le legge chi amministra', function () {
    [$gettoni, $resta, $unito] = duePersoneDoppie();

    $doppioni = alFinto('GET', '/v1/crm/persone/doppioni', null, $gettoni['anna']);

    expect($doppioni->status())->toBe(200)
        ->and($doppioni->json('data'))->toBe([['persone' => [min($resta, $unito), max($resta, $unito)], 'motivo' => 'telefono']])
        ->and($doppioni->json('successivo'))->toBeNull()
        ->and(alFinto('GET', '/v1/crm/persone/doppioni', null, $gettoni['carla'])->json('codice'))->toBe('permesso_negato')
        ->and(alFinto('GET', '/v1/crm/persone/doppioni?limite=51', null, $gettoni['anna'])->status())->toBe(422);
});

it('unire due contatti sposta note e attività, prende i campi in conflitto da `scelte`, archivia chi si unisce con unito_in e lo dice nella cronologia', function () {
    [$gettoni, $resta, $unito] = duePersoneDoppie();

    $senzaScelta = alFinto('POST', '/v1/crm/persone/unioni', corpoDellUnione($gettoni, $resta, $unito), $gettoni['anna']);
    $unione = alFinto('POST', '/v1/crm/persone/unioni', corpoDellUnione($gettoni, $resta, $unito, ['scelte' => ['ruolo' => 'unito']]), $gettoni['anna']);

    expect($senzaScelta->status())->toBe(422)
        ->and($senzaScelta->json('errors.0.pointer'))->toBe('#/scelte/ruolo')
        ->and($unione->status())->toBe(201)
        ->and($unione->header('Location'))->toBe('/v1/crm/persone/unioni/'.$unione->json('data.id'))
        ->and($unione->json('data.spostati'))->toBe(['note' => 1, 'attivita' => 1, 'trattative' => 0])
        ->and($unione->json('data.annullata_il'))->toBeNull();

    $diResta = alFinto('GET', "/v1/crm/persone/{$resta}", null, $gettoni['anna'])->json('data');
    $diUnito = alFinto('GET', "/v1/crm/persone/{$unito}", null, $gettoni['anna'])->json('data');

    expect($diResta['ruolo'])->toBe('CEO')
        ->and(array_column($diResta['email'], 'indirizzo'))->toBe(['anna@example.com', 'a.rossi@example.com'])
        ->and($diResta['unito_in'])->toBeNull()
        ->and($diUnito['archiviato_il'])->not->toBeNull()
        ->and($diUnito['unito_in'])->toBe(['id' => $resta, 'nome' => 'Anna', 'cognome' => 'Rossi'])
        ->and($diUnito['email'])->toBe([])
        ->and(alFinto('GET', '/v1/crm/persone/doppioni', null, $gettoni['anna'])->json('data'))->toBe([]);

    $cronologia = collect(alFinto('GET', "/v1/crm/persone/{$resta}/cronologia", null, $gettoni['anna'])->json('data'))->firstWhere('tipo', 'unita');

    expect($cronologia)->toMatchArray(['altra_persona_id' => $unito, 'unione_id' => $unione->json('data.id'), 'campi' => []]);
});

it('un contatto non si unisce con se stesso, né dopo aver cambiato (412), né quando è già unito (409), né se è il membro a chiederlo (403)', function () {
    [$gettoni, $resta, $unito] = duePersoneDoppie();
    $vecchio = corpoDellUnione($gettoni, $resta, $unito, ['scelte' => ['ruolo' => 'resta']]);
    alFinto('PATCH', "/v1/crm/persone/{$resta}", ['fonte' => 'fiera'], $gettoni['carla']);

    expect(alFinto('POST', '/v1/crm/persone/unioni', corpoDellUnione($gettoni, $resta, $resta), $gettoni['anna'])->json('codice'))->toBe('unione_non_valida')
        ->and(alFinto('POST', '/v1/crm/persone/unioni', corpoDellUnione($gettoni, $resta, $unito, ['scelte' => ['ruolo' => 'resta']]), $gettoni['carla'])->json('codice'))->toBe('permesso_negato')
        ->and(alFinto('POST', '/v1/crm/persone/unioni', $vecchio, $gettoni['anna'])->status())->toBe(412);

    alFinto('POST', '/v1/crm/persone/unioni', corpoDellUnione($gettoni, $resta, $unito, ['scelte' => ['ruolo' => 'resta']]), $gettoni['anna']);

    expect(alFinto('POST', '/v1/crm/persone/unioni', corpoDellUnione($gettoni, $resta, $unito, ['scelte' => ['ruolo' => 'resta']]), $gettoni['anna'])->json('codice'))->toBe('contatto_non_unibile');
});

it('l\'annullamento rimette note, attività e campi a chi si era unito, una volta sola', function () {
    [$gettoni, $resta, $unito] = duePersoneDoppie();
    $id = alFinto('POST', '/v1/crm/persone/unioni', corpoDellUnione($gettoni, $resta, $unito, ['scelte' => ['ruolo' => 'unito']]), $gettoni['anna'])->json('data.id');

    $mostra = alFinto('GET', "/v1/crm/persone/unioni/{$id}", null, $gettoni['anna']);
    $annulla = alFinto('POST', "/v1/crm/persone/unioni/{$id}/annullamento", null, $gettoni['anna']);

    expect($mostra->json('data.dopo'))->toBe(['note' => 0, 'attivita' => 0, 'trattative' => 0, 'campi' => []])
        ->and($annulla->status())->toBe(200)
        ->and($annulla->json('data.rimessi'))->toBe(['note' => 1, 'attivita' => 1, 'trattative' => 0, 'campi' => ['ruolo', 'email', 'telefoni']])
        ->and($annulla->json('data.non_rimesse'))->toBe([])
        ->and(alFinto('POST', "/v1/crm/persone/unioni/{$id}/annullamento", null, $gettoni['anna'])->json('codice'))->toBe('gia_annullata')
        ->and(alFinto('GET', "/v1/crm/persone/{$resta}", null, $gettoni['anna'])->json('data.ruolo'))->toBe('Direttrice')
        ->and(alFinto('GET', "/v1/crm/persone/{$unito}", null, $gettoni['anna'])->json('data.unito_in'))->toBeNull()
        ->and(alFinto('GET', "/v1/crm/persone/{$unito}", null, $gettoni['anna'])->json('data.email.0.indirizzo'))->toBe('a.rossi@example.com')
        ->and(alFinto('GET', '/v1/crm/persone/unioni/01k6zzzzzzzzzzzzzzzzzzzzzz', null, $gettoni['anna'])->json('codice'))->toBe('non_trovato');
});

it('un contatto unito non si riattiva con una PATCH: 422 su #/archiviato, e l\'annullamento lo rimette', function () {
    [$gettoni, $resta, $unito] = duePersoneDoppie();
    $id = alFinto('POST', '/v1/crm/persone/unioni', corpoDellUnione($gettoni, $resta, $unito, ['scelte' => ['ruolo' => 'unito']]), $gettoni['anna'])->json('data.id');

    $riattiva = alFinto('PATCH', "/v1/crm/persone/{$unito}", ['archiviato' => false], $gettoni['carla']);

    expect($riattiva->status())->toBe(422)
        ->and($riattiva->json('errors.0'))->toMatchArray(['pointer' => '#/archiviato', 'regola' => 'valore_non_ammesso'])
        ->and(alFinto('GET', "/v1/crm/persone/{$unito}", null, $gettoni['anna'])->json('data.archiviato_il'))->not->toBeNull();

    alFinto('POST', "/v1/crm/persone/unioni/{$id}/annullamento", null, $gettoni['anna']);

    expect(alFinto('PATCH', "/v1/crm/persone/{$resta}", ['archiviato' => true], $gettoni['carla'])->status())->toBe(200)
        ->and(alFinto('PATCH', "/v1/crm/persone/{$resta}", ['archiviato' => false], $gettoni['carla'])->status())->toBe(200);
});

it('un contatto con una scheda collegata non si unisce: 409 contatto_non_unibile con schede_collegate in blocchi, finché la scheda non lo scollega', function () {
    [$gettoni, $resta, $unito, $finto, $studio] = duePersoneDoppie();
    $finto->attivaApp($studio, 'pm', 'crm');
    $scheda = $finto->scheda($studio, 'Scheda 1');
    $collegato = alFinto('POST', "/v1/board/schede/{$scheda['id']}/crm", ['tipo' => 'crm.persona', 'id' => $unito], $gettoni['anna']);
    expect($collegato->status())->toBe(201);
    $collegamento = $collegato->json('data.id');

    $risposta = alFinto('POST', '/v1/crm/persone/unioni', corpoDellUnione($gettoni, $resta, $unito), $gettoni['anna']);

    expect($risposta->status())->toBe(409)->and($risposta->json('codice'))->toBe('contatto_non_unibile')->and($risposta->json('blocchi'))->toBe(['schede_collegate']);

    alFinto('DELETE', "/v1/board/schede/{$scheda['id']}/crm/{$collegamento}", null, $gettoni['anna']);

    expect(alFinto('POST', '/v1/crm/persone/unioni', corpoDellUnione($gettoni, $resta, $unito, ['scelte' => ['ruolo' => 'resta']]), $gettoni['anna'])->status())->toBe(201);
});

it('l\'oblio di chi resta anonimizza anche chi si era unito in lui, e l\'unione non si annulla più (409)', function () {
    [$gettoni, $resta, $unito] = duePersoneDoppie();
    $unita = alFinto('POST', '/v1/crm/persone/unioni', corpoDellUnione($gettoni, $resta, $unito, ['scelte' => ['ruolo' => 'resta']]), $gettoni['anna']);
    expect($unita->status())->toBe(201);
    $unione = $unita->json('data.id');

    expect(alFinto('POST', "/v1/crm/persone/{$resta}/anonimizzazione", null, $gettoni['anna'])->status())->toBe(200);

    $letto = alFinto('GET', "/v1/crm/persone/{$unito}", null, $gettoni['anna']);

    expect($letto->json('data.anonimizzata_il'))->not->toBeNull()->and($letto->json('data.nome'))->toBeNull()
        ->and(alFinto('POST', "/v1/crm/persone/unioni/{$unione}/annullamento", null, $gettoni['anna'])->json('codice'))->toBe('contatto_non_unibile');
});
