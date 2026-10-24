<?php

// #2461 (CRM-01): l'indirizzo dell'azienda con coordinate e testo libero nel finto. Qui ciò che un frontend vede dal suo lato; che le risposte e
// gli errori siano quelli del backoffice lo prova il suo FintoTest.

const INDIRIZZO_DEL_FINTO = ['via', 'cap', 'citta', 'provincia', 'paese', 'testo', 'lat', 'lng', 'place_id', 'precisione'];

/** @return array<string, mixed> */
function indirizzoDelFinto(array $campi): array
{
    return array_replace(array_fill_keys(INDIRIZZO_DEL_FINTO, null), $campi);
}

it('l\'indirizzo ha le dieci chiavi, coordinate e precisione comprese, e un indirizzo senza precisione resta com\'è (AD.1)', function () {
    [, $gettoni] = studioColCrm();

    $citta = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl', 'indirizzo' => ['precisione' => 'citta', 'citta' => 'Milano', 'lat' => 45.4642, 'lng' => 9.19, 'place_id' => 'ChIJ-milano']], $gettoni['carla']);
    $senza = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Bianchi Spa', 'indirizzo' => ['citta' => 'Torino']], $gettoni['carla']);

    expect($citta->status())->toBe(201)
        ->and($citta->json('data.indirizzo'))->toBe(indirizzoDelFinto(['citta' => 'Milano', 'lat' => 45.4642, 'lng' => 9.19, 'place_id' => 'ChIJ-milano', 'precisione' => 'citta']))
        ->and($senza->json('data.indirizzo'))->toBe(indirizzoDelFinto(['citta' => 'Torino']));
});

it('lat e lng vanno in coppia, e un valore fuori dai limiti è 422 sul suo pointer (AD.1, AD.2)', function () {
    [, $gettoni] = studioColCrm();

    $solaLat = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'A', 'indirizzo' => ['citta' => 'Milano', 'lat' => 45.1]], $gettoni['carla']);
    $fuori = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'B', 'indirizzo' => ['citta' => 'Milano', 'lat' => 91, 'lng' => 9.1]], $gettoni['carla']);
    $stringa = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'C', 'indirizzo' => ['citta' => 'Milano', 'lat' => '45.1', 'lng' => 9.1]], $gettoni['carla']);
    $fuoriElenco = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'D', 'indirizzo' => ['precisione' => 'quartiere']], $gettoni['carla']);

    expect($solaLat->status())->toBe(422)
        ->and($solaLat->json('errors.0'))->toMatchArray(['pointer' => '#/indirizzo/lng', 'regola' => 'obbligatorio'])
        ->and($fuori->json('errors.0.pointer'))->toBe('#/indirizzo/lat')
        ->and($stringa->json('errors.0.pointer'))->toBe('#/indirizzo/lat')
        ->and($fuoriElenco->json('errors.0.pointer'))->toBe('#/indirizzo/precisione');
});

it('indirizzo vuole la via, citta rifiuta via e cap, testo_libero vuole il solo testo (AD.3, AD.4)', function () {
    [, $gettoni] = studioColCrm();

    $senzaVia = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'A', 'indirizzo' => ['precisione' => 'indirizzo', 'citta' => 'Milano']], $gettoni['carla']);
    $cittaConVia = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'B', 'indirizzo' => ['precisione' => 'citta', 'citta' => 'Milano', 'via' => 'Via Roma 1', 'cap' => '20121']], $gettoni['carla']);
    $testoConCitta = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'C', 'indirizzo' => ['precisione' => 'testo_libero', 'testo' => 'x', 'citta' => 'Milano', 'lat' => 45.1, 'lng' => 9.1]], $gettoni['carla']);
    $testoSenzaPrecisione = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'D', 'indirizzo' => ['testo' => 'Zona industriale']], $gettoni['carla']);
    $testoLibero = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'E', 'indirizzo' => ['precisione' => 'testo_libero', 'testo' => 'Zona industriale']], $gettoni['carla']);

    expect($senzaVia->json('errors.0'))->toMatchArray(['pointer' => '#/indirizzo/via', 'regola' => 'obbligatorio'])
        ->and(collect($cittaConVia->json('errors'))->pluck('regola', 'pointer')->all())->toBe(['#/indirizzo/via' => 'campo_vietato', '#/indirizzo/cap' => 'campo_vietato'])
        ->and(collect($testoConCitta->json('errors'))->pluck('regola', 'pointer')->all())->toBe(['#/indirizzo/citta' => 'campo_vietato', '#/indirizzo/lat' => 'campo_vietato', '#/indirizzo/lng' => 'campo_vietato'])
        ->and($testoSenzaPrecisione->json('errors.0'))->toMatchArray(['pointer' => '#/indirizzo/testo', 'regola' => 'campo_vietato'])
        ->and($testoLibero->json('data.indirizzo'))->toBe(indirizzoDelFinto(['testo' => 'Zona industriale', 'precisione' => 'testo_libero']));
});

it('l\'indirizzo sostituisce l\'oggetto intero in modifica: senza lat e lng le toglie (AD.6)', function () {
    [, $gettoni] = studioColCrm();
    $id = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl', 'indirizzo' => ['precisione' => 'citta', 'citta' => 'Milano', 'lat' => 45.4642, 'lng' => 9.19]], $gettoni['carla'])->json('data.id');

    $senza = alFinto('PATCH', "/v1/crm/organizzazioni/{$id}", ['indirizzo' => ['precisione' => 'citta', 'citta' => 'Milano']], $gettoni['carla']);
    $tolto = alFinto('PATCH', "/v1/crm/organizzazioni/{$id}", ['indirizzo' => null], $gettoni['carla']);

    expect($senza->json('data.indirizzo'))->toBe(indirizzoDelFinto(['citta' => 'Milano', 'precisione' => 'citta']))
        ->and($tolto->json('data.indirizzo'))->toBeNull();
});

it('l\'importazione ricava la precisione dalla riga e rifiuta lat, lng, place_id e precisione (AD.8)', function () {
    [, $gettoni] = studioColCrm();

    $risposta = alFinto('POST', '/v1/crm/organizzazioni/importazioni', ['righe' => [
        ['nome' => 'A', 'indirizzo' => ['testo' => 'Zona industriale']],
        ['nome' => 'B', 'indirizzo' => ['via' => 'Via Roma 1']],
        ['nome' => 'C', 'indirizzo' => ['citta' => 'Torino']],
        ['nome' => 'D', 'indirizzo' => ['citta' => 'Genova', 'cap' => '16100']],
        ['nome' => 'E', 'indirizzo' => ['testo' => 'x', 'citta' => 'Milano']],
        ['nome' => 'F', 'indirizzo' => ['citta' => 'Roma', 'lat' => 41.9]],
    ]], $gettoni['carla']);

    expect($risposta->status())->toBe(201)
        ->and(array_column($risposta->json('data.righe'), 'esito'))->toBe(['creata', 'creata', 'creata', 'creata', 'rifiutata', 'rifiutata'])
        ->and($risposta->json('data.righe.4.errors.0'))->toMatchArray(['pointer' => '#/righe/4/indirizzo/testo', 'regola' => 'campo_vietato'])
        ->and(array_unique(array_column($risposta->json('data.righe.5.errors'), 'regola')))->toBe(['campo_estraneo']);

    $precisioni = array_map(fn (int $riga) => alFinto('GET', '/v1/crm/organizzazioni/'.$risposta->json("data.righe.{$riga}.id"), null, $gettoni['carla'])->json('data.indirizzo.precisione'), [0, 1, 2, 3]);

    expect($precisioni)->toBe(['testo_libero', 'indirizzo', 'citta', null]);
});

it('l\'esportazione ha cinque colonne in più dopo paese, e le coordinate sono numeri col loro meno (AD.8)', function () {
    [, $gettoni] = studioColCrm();
    alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Sydney Pty', 'indirizzo' => ['precisione' => 'citta', 'citta' => 'Sydney', 'lat' => -33.8688, 'lng' => 151.2093, 'place_id' => 'ChIJ-sydney']], $gettoni['carla']);

    $righe = array_map(fn (string $riga) => str_getcsv($riga, escape: ''), preg_split('/\r\n/', trim(ltrim(alFinto('GET', '/v1/crm/organizzazioni/esportazione', null, $gettoni['carla'])->body(), "\xEF\xBB\xBF")), -1, PREG_SPLIT_NO_EMPTY));
    $colonne = array_combine($righe[0], $righe[1]);

    expect(array_slice($righe[0], 4, 10))->toBe(INDIRIZZO_DEL_FINTO)
        ->and(array_intersect_key($colonne, array_flip(['citta', 'lat', 'lng', 'place_id', 'precisione'])))->toBe(['citta' => 'Sydney', 'lat' => '-33.8688', 'lng' => '151.2093', 'place_id' => 'ChIJ-sydney', 'precisione' => 'citta']);
});
