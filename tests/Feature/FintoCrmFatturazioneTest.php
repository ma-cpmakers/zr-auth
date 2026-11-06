<?php

// #2925 (CRM-08): il collegamento del workspace al programma di fatturazione, nel finto. Qui ciò che un frontend vede dal suo lato; che le risposte e gli
// errori siano quelli del backoffice lo prova il suo FintoTest.

it('senza collegamento la lettura dice non_collegata; collegare passa dalla partenza e dal ritorno, e scollegare lo riporta (EF.1)', function () {
    [, $gettoni] = studioColCrm();

    expect(alFinto('GET', '/v1/crm/integrazioni/fatturazione', null, $gettoni['anna'])->json('data'))
        ->toBe(['stato' => 'non_collegata', 'programma' => null, 'collegata_il' => null, 'azienda' => null, 'disponibile' => true]);

    $partenza = alFinto('POST', '/v1/crm/integrazioni/fatturazione/autorizzazione', ['programma' => 'fatture_in_cloud'], $gettoni['anna']);
    expect($partenza->status())->toBe(201, $partenza->body())
        ->and(array_keys($partenza->json('data')))->toBe(['url', 'stato', 'scade_il']);

    $ritorno = alFinto('POST', '/v1/crm/integrazioni/fatturazione/collegamento', ['programma' => 'fatture_in_cloud', 'codice' => 'qualunque', 'stato' => $partenza->json('data.stato')], $gettoni['anna']);
    expect($ritorno->status())->toBe(201, $ritorno->body())
        ->and($ritorno->header('Location'))->toBe('/v1/crm/integrazioni/fatturazione')
        ->and($ritorno->json('data.stato'))->toBe('collegata')
        ->and($ritorno->json('data.programma'))->toBe('fatture_in_cloud')
        ->and(alFinto('GET', '/v1/crm/integrazioni/fatturazione', null, $gettoni['bruno'])->json('data'))->toBe($ritorno->json('data'))
        ->and(alFinto('POST', '/v1/crm/integrazioni/fatturazione/autorizzazione', ['programma' => 'fatture_in_cloud'], $gettoni['anna'])->json('codice'))->toBe('fatturazione_gia_collegata');

    expect(alFinto('DELETE', '/v1/crm/integrazioni/fatturazione/collegamento', null, $gettoni['anna'])->status())->toBe(204)
        ->and(alFinto('DELETE', '/v1/crm/integrazioni/fatturazione/collegamento', null, $gettoni['anna'])->status())->toBe(404)
        ->and(alFinto('GET', '/v1/crm/integrazioni/fatturazione', null, $gettoni['anna'])->json('data.stato'))->toBe('non_collegata');
});

it('uno stato usato, sconosciuto o d\'un\'altra persona è 422 verifica_non_riuscita; il membro è 403 (EF.2)', function () {
    [, $gettoni] = studioColCrm();
    $partenza = alFinto('POST', '/v1/crm/integrazioni/fatturazione/autorizzazione', ['programma' => 'fatture_in_cloud'], $gettoni['anna'])->json('data');
    $corpo = fn (string $stato) => ['programma' => 'fatture_in_cloud', 'codice' => 'c', 'stato' => $stato];

    expect(alFinto('POST', '/v1/crm/integrazioni/fatturazione/collegamento', $corpo($partenza['stato']), $gettoni['bruno'])->json('codice'))->toBe('verifica_non_riuscita')
        ->and(alFinto('POST', '/v1/crm/integrazioni/fatturazione/collegamento', $corpo($partenza['stato']), $gettoni['anna'])->json('codice'))->toBe('verifica_non_riuscita')
        ->and(alFinto('POST', '/v1/crm/integrazioni/fatturazione/collegamento', $corpo('mai-esistito'), $gettoni['anna'])->json('codice'))->toBe('verifica_non_riuscita');

    foreach ([['GET', '/v1/crm/integrazioni/fatturazione', null], ['POST', '/v1/crm/integrazioni/fatturazione/autorizzazione', ['programma' => 'fatture_in_cloud']], ['DELETE', '/v1/crm/integrazioni/fatturazione/collegamento', null]] as [$verbo, $percorso, $corpoDelMembro]) {
        expect(alFinto($verbo, $percorso, $corpoDelMembro, $gettoni['carla'])->json('codice'))->toBe('permesso_negato');
    }
});

it('un programma sconosciuto è 422 su #/programma, un campo estraneo 422 (EF.3)', function () {
    [, $gettoni] = studioColCrm();

    $sconosciuto = alFinto('POST', '/v1/crm/integrazioni/fatturazione/autorizzazione', ['programma' => 'altro'], $gettoni['anna']);
    $estraneo = alFinto('POST', '/v1/crm/integrazioni/fatturazione/autorizzazione', ['programma' => 'fatture_in_cloud', 'ritorno' => 'x'], $gettoni['anna']);

    expect($sconosciuto->status())->toBe(422)
        ->and($sconosciuto->json('errors.0.pointer'))->toBe('#/programma')
        ->and($estraneo->status())->toBe(422)
        ->and($estraneo->json('errors.0.pointer'))->toBe('#/ritorno');
});
