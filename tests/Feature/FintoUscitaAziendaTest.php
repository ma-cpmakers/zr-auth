<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #2740: l'uscita da un'azienda nel finto. Qui ciò che un frontend vede dal suo lato; che le risposte e gli errori siano quelli del
// backoffice, byte per byte, lo prova il FintoTest del backoffice.

/**
 * Lo Studio di Anna (proprietaria), con Bruno amministratore e Carla membro; Bruno e Carla hanno anche una casa loro, in un'altra azienda.
 * [finto, studio, case, persone, accesso], dove `accesso(email)` è il gettone dell'accesso della persona.
 */
function squadraDiAziende(): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $bruno = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $carla = $finto->persona('carla@example.com', PASSWORD, nome: 'Carla');
    $studio = $finto->workspace('Studio', $anna);
    $finto->membro($studio, $bruno, 'amministratore');
    $finto->membro($studio, $carla, 'membro');
    $case = ['bruno' => $finto->workspace('Casa di Bruno', $bruno), 'carla' => $finto->workspace('Casa di Carla', $carla)];

    // Un accesso per persona: il finto ha i freni degli accessi, e questi test ne chiedono tanti.
    $accessi = [];

    return [$finto, $studio, $case, ['anna' => $anna, 'bruno' => $bruno, 'carla' => $carla], function (string $email) use (&$accessi) {
        return $accessi[$email] ??= entraNelFinto($email)['gettone']['gettone'];
    }];
}

it('io.aziende.elenca dice se la persona può lasciare ogni azienda: il codice del 409 e i workspace coinvolti, null e [] se può (#2740)', function () {
    [$finto, $studio, $case, , $accesso] = squadraDiAziende();
    $azienda = fn (string $email, string $id) => collect(alFinto('GET', '/v1/io/aziende', gettone: $accesso($email))->json('data'))->firstWhere('id', $id);

    expect($azienda('anna@example.com', $studio['azienda_id']))->toMatchArray(['uscita_negata' => 'proprieta_da_trasferire', 'uscita_negata_workspace' => [['id' => $studio['id'], 'nome' => 'Studio']]])
        // Bruno ha una casa: lo Studio gli resta negato perché è l'unico amministratore (il proprietario non conta), e la sua casa perché ne è proprietario.
        ->and($azienda('bruno@example.com', $studio['azienda_id']))->toMatchArray(['uscita_negata' => 'unico_amministratore', 'uscita_negata_workspace' => [['id' => $studio['id'], 'nome' => 'Studio']]])
        ->and($azienda('bruno@example.com', $case['bruno']['azienda_id']))->toMatchArray(['uscita_negata' => 'proprieta_da_trasferire', 'uscita_negata_workspace' => [['id' => $case['bruno']['id'], 'nome' => 'Casa di Bruno']]])
        // Carla può lasciare lo Studio: ha un'altra casa; il campo c'è, null e [].
        ->and($azienda('carla@example.com', $studio['azienda_id']))->toMatchArray(['uscita_negata' => null, 'uscita_negata_workspace' => []]);

    // Gli altri metodi dell'azienda (aziende.mostra, AziendaConRuolo) non li portano: lo schema non li ha.
    $anna = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso('anna@example.com'))->json('data.gettone');

    expect(alFinto('GET', "/v1/aziende/{$studio['azienda_id']}", gettone: $anna)->json('data'))->not->toHaveKeys(['uscita_negata', 'uscita_negata_workspace']);
});

it('io.aziende.uscita.crea: 204 e la persona non è più in nessun workspace dell\'azienda, i suoi gettoni di là sono 401, l\'accesso e le altre aziende restano (#2740)', function () {
    [$finto, $studio, $case, $persone, $accesso] = squadraDiAziende();
    $secondo = $finto->workspace('Secondo', $persone['anna'], $studio['azienda_id']);
    $finto->membro($secondo, $persone['carla'], 'membro');
    $dellAccesso = $accesso('carla@example.com');
    $gettone = fn (array $workspace) => alFinto('POST', '/v1/gettoni', ['workspace_id' => $workspace['id']], $dellAccesso)->json('data.gettone');
    $inStudio = $gettone($studio);
    $inSecondo = $gettone($secondo);
    $incasa = $gettone($case['carla']);

    $risposta = alFinto('POST', "/v1/io/aziende/{$studio['azienda_id']}/uscita", gettone: $inStudio);

    expect($risposta->status())->toBe(204)->and($risposta->body())->toBe('')
        ->and(esito(alFinto('GET', '/v1/io', gettone: $inStudio)))->toBe([401, 'gettone_non_valido'])
        ->and(esito(alFinto('GET', '/v1/io', gettone: $inSecondo)))->toBe([401, 'gettone_non_valido'])
        ->and(alFinto('GET', '/v1/io', gettone: $incasa)->status())->toBe(200)
        ->and(alFinto('GET', '/v1/io', gettone: $dellAccesso)->status())->toBe(200)
        ->and(collect(alFinto('GET', '/v1/io/aziende', gettone: $dellAccesso)->json('data'))->pluck('id')->all())->toBe([$case['carla']['azienda_id']])
        ->and(collect(alFinto('GET', '/v1/workspace/membri', gettone: alFinto('POST', '/v1/gettoni', ['workspace_id' => $secondo['id']], $accesso('anna@example.com'))->json('data.gettone'))->json('data'))->pluck('id')->all())->not->toContain($persone['carla']['id'])
        // Un'azienda che non ha più niente da lasciare è 404, come una che non c'è.
        ->and(esito(alFinto('POST', "/v1/io/aziende/{$studio['azienda_id']}/uscita", gettone: $dellAccesso)))->toBe([404, 'non_trovato']);
});

it('io.aziende.uscita.crea risponde nell\'ordine del backoffice: il corpo (422), poi il 404, poi i 409 con i loro workspace, per gravità (#2740)', function () {
    [$finto, $studio, $case, $persone, $accesso] = squadraDiAziende();
    $esce = fn (string $email, string $azienda, array $corpo = []) => alFinto('POST', "/v1/io/aziende/{$azienda}/uscita", $corpo, $accesso($email));

    expect(esito($esce('carla@example.com', $studio['azienda_id'], ['x' => 1])))->toBe([422, 'dati_non_validi'])
        // Il corpo viene prima del 404.
        ->and(esito($esce('carla@example.com', '01zzzzzzzzzzzzzzzzzzzzzzzz', ['x' => 1])))->toBe([422, 'dati_non_validi'])
        ->and(esito($esce('carla@example.com', '01zzzzzzzzzzzzzzzzzzzzzzzz')))->toBe([404, 'non_trovato'])
        // L'azienda di un'altra persona è lo stesso 404.
        ->and(esito($esce('carla@example.com', $case['bruno']['azienda_id'])))->toBe([404, 'non_trovato']);

    $proprieta = $esce('anna@example.com', $studio['azienda_id']);
    $unico = $esce('bruno@example.com', $studio['azienda_id']);

    expect(esito($proprieta))->toBe([409, 'proprieta_da_trasferire'])
        ->and($proprieta->json('workspace_da_trasferire'))->toBe([['id' => $studio['id'], 'nome' => 'Studio']])
        ->and(esito($unico))->toBe([409, 'unico_amministratore'])
        ->and($unico->json('workspace_senza_amministratore'))->toBe([['id' => $studio['id'], 'nome' => 'Studio']]);

    // Carla lascia lo Studio; la sua casa è sua (è proprietaria), e la proprietà viene prima dell'ultimo workspace.
    expect($esce('carla@example.com', $studio['azienda_id'])->status())->toBe(204)
        ->and(esito($esce('carla@example.com', $case['carla']['azienda_id'])))->toBe([409, 'proprieta_da_trasferire']);

    // Chi ha un solo workspace, e lì è amministratore: il codice dell'ultimo viene dopo la proprietà e prima dell'unico amministratore.
    $solo = $finto->persona('solo@example.com', PASSWORD);
    $ufficio = $finto->workspace('Ufficio', $persone['anna']);
    $finto->membro($ufficio, $solo, 'amministratore');
    $risposta = $esce('solo@example.com', $ufficio['azienda_id']);

    expect(esito($risposta))->toBe([409, 'ultimo_workspace'])
        ->and($risposta->json('workspace_da_lasciare'))->toBe([['id' => $ufficio['id'], 'nome' => 'Ufficio']]);
});

it('un workspace con l\'eliminazione programmata non è vivo: non conta come altra casa né si lascia (#2740)', function () {
    [$finto, $studio, $case, $persone, $accesso] = squadraDiAziende();
    $casa = alFinto('POST', '/v1/gettoni', ['workspace_id' => $case['carla']['id']], $accesso('carla@example.com'))->json('data.gettone');

    expect(alFinto('DELETE', '/v1/workspace', gettone: $casa)->status())->toBe(202);

    $risposta = alFinto('POST', "/v1/io/aziende/{$studio['azienda_id']}/uscita", gettone: $accesso('carla@example.com'));

    expect(esito($risposta))->toBe([409, 'ultimo_workspace'])
        ->and($risposta->json('workspace_da_lasciare'))->toBe([['id' => $studio['id'], 'nome' => 'Studio']]);
});

it('un\'azienda i cui workspace della persona sono tutti in eliminazione programmata è 409 eliminazione_programmata, con quei workspace, nell\'elenco e nella chiamata (TX.1, #2866)', function () {
    [$finto, $studio, $case, $persone, $accesso] = squadraDiAziende();
    $casa = alFinto('POST', '/v1/gettoni', ['workspace_id' => $case['carla']['id']], $accesso('carla@example.com'))->json('data.gettone');

    expect(alFinto('DELETE', '/v1/workspace', gettone: $casa)->status())->toBe(202);

    $azienda = collect(alFinto('GET', '/v1/io/aziende', gettone: $accesso('carla@example.com'))->json('data'))->firstWhere('id', $case['carla']['azienda_id']);
    $risposta = alFinto('POST', "/v1/io/aziende/{$case['carla']['azienda_id']}/uscita", gettone: $accesso('carla@example.com'));

    // deve fallire se: un'azienda con tutti i workspace in eliminazione ha uscita_negata null, o la chiamata è un 404 muto
    expect($azienda)->toMatchArray(['uscita_negata' => 'eliminazione_programmata', 'uscita_negata_workspace' => [['id' => $case['carla']['id'], 'nome' => 'Casa di Carla']]])
        ->and(esito($risposta))->toBe([409, 'eliminazione_programmata'])
        ->and($risposta->json('workspace_da_eliminare'))->toBe($azienda['uscita_negata_workspace']);
});
