<?php

// #2926, #3342, #3343 (CRM-08 F4): le fatture di una rata o di un acquisto nel finto. Qui ciò che un frontend vede dal suo lato; che le risposte e gli
// errori siano quelli del backoffice lo prova il suo FintoTest.

/**
 * Un workspace col programma collegato, un'azienda col profilo completo, una voce con IVA al 22 % e un acquisto da 6.000 di un suo contatto.
 *
 * @return array{0: array<string, string>, 1: string, 2: string, 3: string}
 */
function acquistoFatturabileDelFinto(bool $profilo = true): array
{
    [, $gettoni] = studioColCrm();
    $partenza = alFinto('POST', '/v1/crm/integrazioni/fatturazione/autorizzazione', ['programma' => 'fatture_in_cloud'], $gettoni['anna'])->json('data.stato');
    alFinto('POST', '/v1/crm/integrazioni/fatturazione/collegamento', ['programma' => 'fatture_in_cloud', 'codice' => 'c', 'stato' => $partenza], $gettoni['anna']);

    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla'])->json('data.id');
    $persona = alFinto('POST', '/v1/crm/persone', ['nome' => 'Giulia', 'organizzazione_id' => $azienda, 'paga_per_id' => $azienda], $gettoni['carla'])->json('data.id');

    if ($profilo) {
        $creato = alFinto('POST', '/v1/crm/profili-fatturazione', profiloDelFinto($azienda), $gettoni['carla']);
        expect($creato->status())->toBe(201, $creato->body());
    }

    $voce = alFinto('POST', '/v1/crm/catalogo', ['tipo' => 'servizio', 'nome' => 'Percorso', 'prezzo' => ['importo' => 6000, 'valuta' => 'EUR'], 'aliquota_iva' => 2200], $gettoni['anna'])->json('data.id');
    $acquisto = alFinto('POST', '/v1/crm/acquisti', [
        'persona_id' => $persona, 'voce_id' => $voce, 'acquistato_il' => now()->format('Y-m-d'), 'metodo_pagamento' => 'da_pagare', 'pagato' => ['importo' => 0, 'valuta' => 'EUR'],
    ], $gettoni['anna']);
    expect($acquisto->status())->toBe(201, $acquisto->body());
    $rata = alFinto('GET', '/v1/crm/rate?acquisto_id='.$acquisto->json('data.id'), null, $gettoni['carla'])->json('data.0.id');

    return [$gettoni, $acquisto->json('data.id'), $rata, $persona];
}

it('emette la fattura di una rata: 201 con numero e data, la rata e l\'acquisto mostrano il campo fattura, il PDF ha url e scadenza (EFT.1)', function () {
    [$gettoni, $acquisto, $rata] = acquistoFatturabileDelFinto();

    expect(alFinto('GET', "/v1/crm/rate/{$rata}", null, $gettoni['anna'])->json('data.fattura'))->toBeNull()
        ->and(alFinto('GET', "/v1/crm/rate/{$rata}/fattura/pdf", null, $gettoni['anna'])->status())->toBe(404);

    $emessa = alFinto('POST', '/v1/crm/fatture', ['rata_id' => $rata], $gettoni['anna']);

    expect($emessa->status())->toBe(201, $emessa->body())
        ->and(array_keys($emessa->json('data')))->toBe(['id', 'rata_id', 'acquisto_id', 'numero', 'emessa_il'])
        ->and($emessa->json('data.numero'))->toBe('1/A')
        ->and($emessa->header('Location'))->toBe("/v1/crm/rate/{$rata}")
        ->and(alFinto('GET', "/v1/crm/rate/{$rata}", null, $gettoni['anna'])->json('data.fattura.numero'))->toBe('1/A')
        ->and(alFinto('GET', "/v1/crm/acquisti/{$acquisto}", null, $gettoni['anna'])->json('data.fattura'))->toBeNull();

    $pdf = alFinto('GET', "/v1/crm/rate/{$rata}/fattura/pdf", null, $gettoni['anna']);
    expect($pdf->status())->toBe(200)
        ->and(array_keys($pdf->json('data')))->toBe(['url', 'scade_il'])
        ->and(alFinto('GET', "/v1/crm/acquisti/{$acquisto}/fattura/pdf", null, $gettoni['anna'])->status())->toBe(404);
});

it('una fattura sola: la stessa rata, l\'acquisto con una rata fatturata e un membro sono respinti (EFT.2)', function () {
    [$gettoni, $acquisto, $rata] = acquistoFatturabileDelFinto();

    expect(alFinto('POST', '/v1/crm/fatture', ['rata_id' => $rata], $gettoni['carla'])->json('codice'))->toBe('permesso_negato');
    alFinto('POST', '/v1/crm/fatture', ['rata_id' => $rata], $gettoni['anna']);

    expect(alFinto('POST', '/v1/crm/fatture', ['rata_id' => $rata], $gettoni['anna'])->json('codice'))->toBe('fattura_gia_emessa')
        ->and(alFinto('POST', '/v1/crm/fatture', ['acquisto_id' => $acquisto], $gettoni['anna'])->json('codice'))->toBe('fattura_gia_emessa')
        ->and(alFinto('POST', '/v1/crm/fatture', ['rata_id' => $rata, 'acquisto_id' => $acquisto], $gettoni['anna'])->status())->toBe(422)
        ->and(alFinto('POST', '/v1/crm/fatture', [], $gettoni['anna'])->json('errors.0.pointer'))->toBe('#/rata_id');
});

it('senza profilo di chi paga è 422 non_trovato sul campo, con un profilo incompleto 422 con mancano, senza collegamento 409 (EFT.3)', function () {
    [$gettoni, , $rata] = acquistoFatturabileDelFinto(profilo: false);

    $senza = alFinto('POST', '/v1/crm/fatture', ['rata_id' => $rata], $gettoni['anna']);
    expect($senza->status())->toBe(422)
        ->and($senza->json('errors.0.pointer'))->toBe('#/rata_id')
        ->and($senza->json('errors.0.regola'))->toBe('non_trovato');

    alFinto('DELETE', '/v1/crm/integrazioni/fatturazione/collegamento', null, $gettoni['anna']);
    expect(alFinto('POST', '/v1/crm/fatture', ['rata_id' => $rata], $gettoni['anna'])->json('codice'))->toBe('fatturazione_non_collegata');
});
