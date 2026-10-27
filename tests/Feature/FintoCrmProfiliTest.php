<?php

// #2751 (CRM-01 F5-F7): i profili di fatturazione e gli indirizzi di spedizione dei clienti del CRM nel finto. Qui ciò che un frontend vede dal
// suo lato; che le risposte e gli errori siano quelli del backoffice lo prova il suo FintoTest.

const PARTITA_IVA_DEL_FINTO = '12345678903';
const CODICE_FISCALE_DEL_FINTO = 'RSSMRA85T10A562S';

/** @return array<string, mixed> un profilo italiano completo, dell'azienda `$azienda` */
function profiloDelFinto(string $azienda, array $campi = []): array
{
    return [
        'organizzazione_id' => $azienda, 'tipo_intestatario' => 'azienda', 'paese' => 'IT', 'intestatario' => 'Rossi Srl', 'partita_iva' => PARTITA_IVA_DEL_FINTO,
        'codice_destinatario' => 'abcdefg', 'indirizzo' => ['via' => 'Via Roma', 'civico' => '1', 'cap' => '20121', 'citta' => 'Milano', 'provincia' => 'MI', 'paese' => 'IT'],
        ...$campi,
    ];
}

/** @return array<string, mixed> un indirizzo di spedizione dell'azienda `$azienda` */
function indirizzoDiSpedizioneDelFinto(string $azienda, array $campi = []): array
{
    return ['organizzazione_id' => $azienda, 'via' => 'Via Dante 2', 'cap' => '10121', 'citta' => 'Torino', 'paese' => 'IT', ...$campi];
}

it('un profilo nasce con 201, Location ed ETag; il primo del cliente è il predefinito e i dati fiscali si normalizzano (PF.1)', function () {
    [, $gettoni] = studioColCrm();
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla'])->json('data.id');

    $creato = alFinto('POST', '/v1/crm/profili-fatturazione', profiloDelFinto($azienda, ['partita_iva' => 'it 123 45678-903', 'pec' => 'Fatture@Rossi.IT']), $gettoni['carla']);

    expect($creato->status())->toBe(201)
        ->and($creato->header('Location'))->toBe('/v1/crm/profili-fatturazione/'.$creato->json('data.id'))
        ->and($creato->header('ETag'))->toStartWith('"')
        ->and($creato->json('data'))->toMatchArray([
            'organizzazione_id' => $azienda, 'persona_id' => null, 'partita_iva' => 'IT'.PARTITA_IVA_DEL_FINTO, 'codice_destinatario' => 'ABCDEFG', 'pec' => 'fatture@rossi.it',
            'predefinito' => true, 'area_fiscale' => 'nazionale', 'stato' => 'completo', 'mancano' => [], 'archiviato_il' => null, 'scissione_pagamenti' => false,
            'vies' => ['esito' => 'non_verificata', 'verificato_il' => null],
        ])
        ->and($creato->json('data.indirizzo'))->toBe(['via' => 'Via Roma', 'civico' => '1', 'cap' => '20121', 'citta' => 'Milano', 'provincia' => 'MI', 'paese' => 'IT']);
});

it('un profilo che non basta per fatturare si salva, incompleto, con ciò che manca; l\'area segue il paese dell\'azienda del workspace (PF.2)', function () {
    [, $gettoni] = studioColCrm();
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla'])->json('data.id');

    $povero = alFinto('POST', '/v1/crm/profili-fatturazione', ['organizzazione_id' => $azienda, 'tipo_intestatario' => 'azienda', 'paese' => 'IT'], $gettoni['carla']);
    $tedesco = alFinto('POST', '/v1/crm/profili-fatturazione', ['organizzazione_id' => $azienda, 'tipo_intestatario' => 'azienda', 'paese' => 'DE'], $gettoni['carla']);
    $americano = alFinto('POST', '/v1/crm/profili-fatturazione', ['organizzazione_id' => $azienda, 'tipo_intestatario' => 'professionista', 'paese' => 'US'], $gettoni['carla']);

    expect($povero->json('data.stato'))->toBe('incompleto')
        ->and($povero->json('data.mancano'))->toBe(['intestatario', 'indirizzo.via', 'indirizzo.cap', 'indirizzo.citta', 'indirizzo.provincia', 'partita_iva', 'codice_destinatario'])
        ->and($tedesco->json('data.area_fiscale'))->toBe('ue')
        ->and($tedesco->json('data.mancano'))->toBe(['intestatario', 'indirizzo.via', 'indirizzo.cap', 'indirizzo.citta', 'partita_iva'])
        ->and($americano->json('data.area_fiscale'))->toBe('extra_ue')
        ->and($americano->json('data.mancano'))->toBe(['intestatario', 'indirizzo.via', 'indirizzo.cap', 'indirizzo.citta', 'identificativo_estero']);
});

it('il codice fiscale e la partita IVA si controllano per il paese, e tutti e due sbagliati sono due errori sui loro pointer (PF.3)', function () {
    [, $gettoni] = studioColCrm();
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla'])->json('data.id');

    $entrambi = alFinto('POST', '/v1/crm/profili-fatturazione', ['organizzazione_id' => $azienda, 'tipo_intestatario' => 'privato', 'paese' => 'IT', 'codice_fiscale' => 'RSSMRA85T10A562X', 'partita_iva' => '12345678900'], $gettoni['carla']);
    $buono = alFinto('POST', '/v1/crm/profili-fatturazione', ['organizzazione_id' => $azienda, 'tipo_intestatario' => 'privato', 'paese' => 'IT', 'codice_fiscale' => 'rssmra85t10a562s'], $gettoni['carla']);

    expect($entrambi->status())->toBe(422)
        ->and(collect($entrambi->json('errors'))->pluck('regola', 'pointer')->all())->toBe(['#/codice_fiscale' => 'formato', '#/partita_iva' => 'formato'])
        ->and($buono->json('data.codice_fiscale'))->toBe(CODICE_FISCALE_DEL_FINTO);
});

it('il cliente è uno solo e c\'è nel workspace; paese e tipo sono obbligatori e i campi calcolati non si scrivono (PF.4)', function () {
    [, $gettoni] = studioColCrm();
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla'])->json('data.id');
    $persona = alFinto('POST', '/v1/crm/persone', ['nome' => 'Giulia'], $gettoni['carla'])->json('data.id');

    $nessuno = alFinto('POST', '/v1/crm/profili-fatturazione', ['tipo_intestatario' => 'privato', 'paese' => 'IT'], $gettoni['carla']);
    $due = alFinto('POST', '/v1/crm/profili-fatturazione', ['persona_id' => $persona, 'organizzazione_id' => $azienda, 'tipo_intestatario' => 'privato', 'paese' => 'IT'], $gettoni['carla']);
    $inesistente = alFinto('POST', '/v1/crm/profili-fatturazione', ['persona_id' => '01k7c4d6f8h0k2n4q6s8v0x2za', 'tipo_intestatario' => 'privato', 'paese' => 'IT'], $gettoni['carla']);
    $senzaPaese = alFinto('POST', '/v1/crm/profili-fatturazione', ['persona_id' => $persona, 'tipo_intestatario' => 'privato'], $gettoni['carla']);
    $calcolato = alFinto('POST', '/v1/crm/profili-fatturazione', ['persona_id' => $persona, 'tipo_intestatario' => 'privato', 'paese' => 'IT', 'stato' => 'completo'], $gettoni['carla']);

    expect($nessuno->json('errors.0'))->toMatchArray(['pointer' => '#/persona_id', 'regola' => 'obbligatorio'])
        ->and($due->json('errors.0.regola'))->toBe('campo_vietato')
        ->and($inesistente->json('errors.0'))->toMatchArray(['pointer' => '#/persona_id', 'regola' => 'non_trovato'])
        ->and($senzaPaese->json('errors.0'))->toMatchArray(['pointer' => '#/paese', 'regola' => 'obbligatorio'])
        ->and($calcolato->json('errors.0'))->toMatchArray(['pointer' => '#/stato', 'regola' => 'campo_vietato']);
});

it('il predefinito è uno solo: il secondo non lo è, true lo sposta, archiviarlo promuove il più vecchio, false non lo toglie (PF.5)', function () {
    [, $gettoni] = studioColCrm();
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla'])->json('data.id');
    $primo = alFinto('POST', '/v1/crm/profili-fatturazione', profiloDelFinto($azienda), $gettoni['carla'])->json('data.id');
    $secondo = alFinto('POST', '/v1/crm/profili-fatturazione', profiloDelFinto($azienda, ['nome_profilo' => 'Due']), $gettoni['carla'])->json('data');
    $terzo = alFinto('POST', '/v1/crm/profili-fatturazione', profiloDelFinto($azienda, ['nome_profilo' => 'Tre', 'predefinito' => true]), $gettoni['carla'])->json('data.id');
    $predefiniti = fn () => collect(alFinto('GET', "/v1/crm/profili-fatturazione?organizzazione_id={$azienda}", null, $gettoni['carla'])->json('data'))->where('predefinito', true)->pluck('id')->all();

    expect($secondo['predefinito'])->toBeFalse()
        ->and($predefiniti())->toBe([$terzo]);

    $togli = alFinto('PATCH', "/v1/crm/profili-fatturazione/{$terzo}", ['predefinito' => false], $gettoni['carla']);
    $archivia = alFinto('PATCH', "/v1/crm/profili-fatturazione/{$terzo}", ['archiviato' => true], $gettoni['carla']);
    $archiviatoPredefinito = alFinto('PATCH', "/v1/crm/profili-fatturazione/{$secondo['id']}", ['archiviato' => true, 'predefinito' => true], $gettoni['carla']);

    expect($togli->json('errors.0'))->toMatchArray(['pointer' => '#/predefinito', 'regola' => 'valore_non_ammesso'])
        ->and($archivia->status())->toBe(200)
        ->and($archivia->json('data'))->toMatchArray(['predefinito' => false])
        ->and($archivia->json('data.archiviato_il'))->not->toBeNull()
        ->and($archiviatoPredefinito->json('errors.0.pointer'))->toBe('#/predefinito')
        ->and($predefiniti())->toBe([$primo]);

    $ripristina = alFinto('PATCH', "/v1/crm/profili-fatturazione/{$terzo}", ['archiviato' => false], $gettoni['carla']);

    expect($ripristina->json('data.predefinito'))->toBeFalse()
        ->and($ripristina->json('data.archiviato_il'))->toBeNull();
});

it('un cliente ha al più venti elementi non archiviati: il ventunesimo è 409 limite_raggiunto, e archiviarne uno ne libera il posto (PF.6)', function () {
    [, $gettoni] = studioColCrm();
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla'])->json('data.id');
    $ultimo = null;

    foreach (range(1, 20) as $n) {
        $ultimo = alFinto('POST', '/v1/crm/indirizzi-spedizione', indirizzoDiSpedizioneDelFinto($azienda, ['nome_indirizzo' => "Sede {$n}"]), $gettoni['carla'])->json('data.id');
    }

    $oltre = alFinto('POST', '/v1/crm/indirizzi-spedizione', indirizzoDiSpedizioneDelFinto($azienda), $gettoni['carla']);
    alFinto('PATCH', "/v1/crm/indirizzi-spedizione/{$ultimo}", ['archiviato' => true], $gettoni['carla']);
    $dopo = alFinto('POST', '/v1/crm/indirizzi-spedizione', indirizzoDiSpedizioneDelFinto($azienda), $gettoni['carla']);
    $ripristina = alFinto('PATCH', "/v1/crm/indirizzi-spedizione/{$ultimo}", ['archiviato' => false], $gettoni['carla']);

    expect($oltre->status())->toBe(409)
        ->and($oltre->json('codice'))->toBe('limite_raggiunto')
        ->and($oltre->json('limite'))->toBe(20)
        ->and($dopo->status())->toBe(201)
        ->and($ripristina->status())->toBe(409);
});

it('l\'elenco è di un cliente, dal più vecchio, con gli archiviati a richiesta; un dato personale nella query è 422 e un cliente che non c\'è è 404 (PF.7)', function () {
    [, $gettoni] = studioColCrm();
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla'])->json('data.id');
    $altra = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Bianchi Spa'], $gettoni['carla'])->json('data.id');
    $ids = [];

    foreach (['Uno', 'Due', 'Tre'] as $nome) {
        $ids[] = alFinto('POST', '/v1/crm/profili-fatturazione', profiloDelFinto($azienda, ['nome_profilo' => $nome]), $gettoni['carla'])->json('data.id');
    }

    alFinto('POST', '/v1/crm/profili-fatturazione', profiloDelFinto($altra), $gettoni['carla']);
    alFinto('PATCH', "/v1/crm/profili-fatturazione/{$ids[1]}", ['archiviato' => true], $gettoni['carla']);

    $vivi = alFinto('GET', "/v1/crm/profili-fatturazione?organizzazione_id={$azienda}", null, $gettoni['anna']);
    $archiviati = alFinto('GET', "/v1/crm/profili-fatturazione?organizzazione_id={$azienda}&archiviati=true", null, $gettoni['anna']);
    $pagina = alFinto('GET', "/v1/crm/profili-fatturazione?organizzazione_id={$azienda}&limite=1", null, $gettoni['anna']);
    $dopo = alFinto('GET', "/v1/crm/profili-fatturazione?organizzazione_id={$azienda}&limite=1&cursore=".urlencode((string) $pagina->json('successivo')), null, $gettoni['anna']);
    $altroCliente = alFinto('GET', "/v1/crm/profili-fatturazione?organizzazione_id={$altra}&cursore=".urlencode((string) $pagina->json('successivo')), null, $gettoni['anna']);
    $dato = alFinto('GET', "/v1/crm/profili-fatturazione?organizzazione_id={$azienda}&partita_iva=".PARTITA_IVA_DEL_FINTO, null, $gettoni['anna']);
    $senzaCliente = alFinto('GET', '/v1/crm/profili-fatturazione', null, $gettoni['anna']);
    $inesistente = alFinto('GET', '/v1/crm/profili-fatturazione?persona_id=01k7c4d6f8h0k2n4q6s8v0x2za', null, $gettoni['anna']);

    expect($vivi->json('data.*.id'))->toBe([$ids[0], $ids[2]])
        ->and($archiviati->json('data.*.id'))->toBe([$ids[1]])
        ->and($pagina->json('data.*.id'))->toBe([$ids[0]])
        ->and($dopo->json('data.*.id'))->toBe([$ids[2]])
        ->and($altroCliente->status())->toBe(422)
        ->and($altroCliente->json('errors.0'))->toMatchArray(['parameter' => 'cursore', 'regola' => 'formato'])
        ->and($dato->status())->toBe(422)
        ->and($dato->json('errors.0'))->toMatchArray(['parameter' => 'partita_iva', 'regola' => 'campo_vietato'])
        ->and($senzaCliente->status())->toBe(422)
        ->and($inesistente->status())->toBe(404);
});

it('la ricerca porta il codice fiscale e la partita IVA nel corpo, normalizzati, e trova per uguaglianza esatta (PF.8)', function () {
    [, $gettoni] = studioColCrm();
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla'])->json('data.id');
    $persona = alFinto('POST', '/v1/crm/persone', ['nome' => 'Mario'], $gettoni['carla'])->json('data.id');
    $impresa = alFinto('POST', '/v1/crm/profili-fatturazione', profiloDelFinto($azienda), $gettoni['carla'])->json('data.id');
    $privato = alFinto('POST', '/v1/crm/profili-fatturazione', ['persona_id' => $persona, 'tipo_intestatario' => 'privato', 'paese' => 'IT', 'codice_fiscale' => CODICE_FISCALE_DEL_FINTO], $gettoni['carla'])->json('data.id');

    $perPartita = alFinto('POST', '/v1/crm/profili-fatturazione/ricerca', ['partita_iva' => '12345678903'], $gettoni['carla']);
    $perCodice = alFinto('POST', '/v1/crm/profili-fatturazione/ricerca', ['codice_fiscale' => 'rssmra85t10a562s'], $gettoni['carla']);
    $parziale = alFinto('POST', '/v1/crm/profili-fatturazione/ricerca', ['partita_iva' => '123456'], $gettoni['carla']);
    $vuota = alFinto('POST', '/v1/crm/profili-fatturazione/ricerca', [], $gettoni['carla']);
    $estranea = alFinto('POST', '/v1/crm/profili-fatturazione/ricerca', ['partita_iva' => '12345678903', 'nome' => 'Rossi'], $gettoni['carla']);

    expect($perPartita->status())->toBe(200)
        ->and($perPartita->json('data.*.id'))->toBe([$impresa])
        ->and($perPartita->json('successivo'))->toBeNull()
        ->and($perCodice->json('data.*.id'))->toBe([$privato])
        ->and($parziale->json('data'))->toBe([])
        ->and($vuota->status())->toBe(422)
        ->and($estranea->json('errors.0'))->toMatchArray(['pointer' => '#/nome', 'regola' => 'campo_estraneo']);
});

it('mostra dà l\'ETag e il 304; la modifica con If-Match vecchio è 412; una modifica che non cambia niente non sposta aggiornato_il (PF.9)', function () {
    [, $gettoni] = studioColCrm();
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla'])->json('data.id');
    $creato = alFinto('POST', '/v1/crm/profili-fatturazione', profiloDelFinto($azienda), $gettoni['carla']);
    $id = $creato->json('data.id');
    $etag = $creato->header('ETag');

    $mostra = alFinto('GET', "/v1/crm/profili-fatturazione/{$id}", null, $gettoni['anna']);
    $uguale = alFinto('GET', "/v1/crm/profili-fatturazione/{$id}", null, $gettoni['anna'], null, ['If-None-Match' => $etag]);
    $nulla = alFinto('PATCH', "/v1/crm/profili-fatturazione/{$id}", ['intestatario' => 'Rossi Srl', 'partita_iva' => 'IT 12345678903'], $gettoni['carla']);
    $cambia = alFinto('PATCH', "/v1/crm/profili-fatturazione/{$id}", ['intestatario' => 'Rossi e Figli Srl'], $gettoni['carla'], null, ['If-Match' => $etag]);
    $vecchio = alFinto('PATCH', "/v1/crm/profili-fatturazione/{$id}", ['intestatario' => 'Altro'], $gettoni['carla'], null, ['If-Match' => $etag]);
    $vuoto = alFinto('PATCH', "/v1/crm/profili-fatturazione/{$id}", [], $gettoni['carla']);
    $cliente = alFinto('PATCH', "/v1/crm/profili-fatturazione/{$id}", ['organizzazione_id' => $azienda], $gettoni['carla']);

    expect($mostra->header('ETag'))->toBe($etag)
        ->and($uguale->status())->toBe(304)
        ->and($nulla->status())->toBe(200)
        ->and($nulla->json('data.aggiornato_il'))->toBe($creato->json('data.aggiornato_il'))
        ->and($nulla->header('ETag'))->toBe($etag)
        ->and($cambia->json('data.intestatario'))->toBe('Rossi e Figli Srl')
        ->and($cambia->header('ETag'))->not->toBe($etag)
        ->and($vecchio->status())->toBe(412)
        ->and($vuoto->json('errors.0.pointer'))->toBe('#')
        ->and($cliente->json('errors.0'))->toMatchArray(['pointer' => '#/organizzazione_id', 'regola' => 'campo_estraneo']);
});

it('cambiare il paese rifà i controlli dei dati fiscali già salvati, e l\'indirizzo si cambia per campo o si toglie con null (PF.10)', function () {
    [, $gettoni] = studioColCrm();
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla'])->json('data.id');
    $id = alFinto('POST', '/v1/crm/profili-fatturazione', profiloDelFinto($azienda), $gettoni['carla'])->json('data.id');

    $tedesco = alFinto('PATCH', "/v1/crm/profili-fatturazione/{$id}", ['paese' => 'DE'], $gettoni['carla']);
    $via = alFinto('PATCH', "/v1/crm/profili-fatturazione/{$id}", ['indirizzo' => ['via' => 'Via Dante']], $gettoni['carla']);
    $senza = alFinto('PATCH', "/v1/crm/profili-fatturazione/{$id}", ['indirizzo' => null], $gettoni['carla']);

    expect($tedesco->status())->toBe(422)
        ->and($tedesco->json('errors.0'))->toMatchArray(['pointer' => '#/partita_iva', 'regola' => 'formato'])
        ->and($via->json('data.indirizzo'))->toBe(['via' => 'Via Dante', 'civico' => '1', 'cap' => '20121', 'citta' => 'Milano', 'provincia' => 'MI', 'paese' => 'IT'])
        ->and($senza->json('data.indirizzo'))->toBe(['via' => null, 'civico' => null, 'cap' => null, 'citta' => null, 'provincia' => null, 'paese' => null])
        ->and($senza->json('data.stato'))->toBe('incompleto');
});

it('un indirizzo di spedizione vuole via, cap, citta e paese; una modifica non li toglie; si archivia e non si cancella (IS.1)', function () {
    [, $gettoni] = studioColCrm();
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla'])->json('data.id');

    $senzaVia = alFinto('POST', '/v1/crm/indirizzi-spedizione', ['organizzazione_id' => $azienda, 'cap' => '10121', 'citta' => 'Torino', 'paese' => 'IT'], $gettoni['carla']);
    $telefono = alFinto('POST', '/v1/crm/indirizzi-spedizione', indirizzoDiSpedizioneDelFinto($azienda, ['telefono_corriere' => 'chiama!']), $gettoni['carla']);
    $creato = alFinto('POST', '/v1/crm/indirizzi-spedizione', indirizzoDiSpedizioneDelFinto($azienda, ['destinatario' => 'Mario Rossi', 'telefono_corriere' => '+39 011 123456']), $gettoni['carla']);
    $id = $creato->json('data.id');
    $tolta = alFinto('PATCH', "/v1/crm/indirizzi-spedizione/{$id}", ['via' => null], $gettoni['carla']);
    $note = alFinto('PATCH', "/v1/crm/indirizzi-spedizione/{$id}", ['note_consegna' => 'Citofono 3', 'destinatario' => null], $gettoni['carla']);
    $archivia = alFinto('PATCH', "/v1/crm/indirizzi-spedizione/{$id}", ['archiviato' => true], $gettoni['carla']);
    $mostra = alFinto('GET', "/v1/crm/indirizzi-spedizione/{$id}", null, $gettoni['anna']);

    expect($senzaVia->json('errors.0'))->toMatchArray(['pointer' => '#/via', 'regola' => 'obbligatorio'])
        ->and($telefono->json('errors.0.pointer'))->toBe('#/telefono_corriere')
        ->and($creato->status())->toBe(201)
        ->and($creato->header('Location'))->toBe("/v1/crm/indirizzi-spedizione/{$id}")
        ->and($creato->json('data.predefinito'))->toBeTrue()
        ->and($tolta->json('errors.0.pointer'))->toBe('#/via')
        ->and($note->json('data'))->toMatchArray(['note_consegna' => 'Citofono 3', 'destinatario' => null, 'via' => 'Via Dante 2'])
        ->and($archivia->json('data.predefinito'))->toBeFalse()
        ->and($mostra->json('data.archiviato_il'))->not->toBeNull();
});

it('il paese di un indirizzo (del profilo e di spedizione) è un codice ISO assegnato: «ZZ» è 422 sul suo pointer', function () {
    [, $gettoni] = studioColCrm();
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla'])->json('data.id');
    $profilo = alFinto('POST', '/v1/crm/profili-fatturazione', profiloDelFinto($azienda), $gettoni['carla'])->json('data.id');
    $spedizione = alFinto('POST', '/v1/crm/indirizzi-spedizione', indirizzoDiSpedizioneDelFinto($azienda), $gettoni['carla'])->json('data.id');

    $nelProfilo = alFinto('PATCH', "/v1/crm/profili-fatturazione/{$profilo}", ['indirizzo' => ['paese' => 'ZZ']], $gettoni['carla']);
    $inCreazione = alFinto('POST', '/v1/crm/indirizzi-spedizione', indirizzoDiSpedizioneDelFinto($azienda, ['paese' => 'ZZ']), $gettoni['carla']);
    $inModifica = alFinto('PATCH', "/v1/crm/indirizzi-spedizione/{$spedizione}", ['paese' => 'ZZ'], $gettoni['carla']);

    expect($nelProfilo->status())->toBe(422)
        ->and($nelProfilo->json('errors.0.pointer'))->toBe('#/indirizzo/paese')
        ->and($inCreazione->json('errors.0.pointer'))->toBe('#/paese')
        ->and($inModifica->json('errors.0'))->toMatchArray(['pointer' => '#/paese', 'regola' => 'valore_non_ammesso'])
        ->and(alFinto('PATCH', "/v1/crm/indirizzi-spedizione/{$spedizione}", ['paese' => 'DE'], $gettoni['carla'])->status())->toBe(200);
});

it('un contatto anonimizzato perde i dati dei suoi profili e indirizzi, che si archiviano, e non ne riceve di nuovi (PF.11)', function () {
    [, $gettoni] = studioColCrm();
    $persona = alFinto('POST', '/v1/crm/persone', ['nome' => 'Mario'], $gettoni['carla'])->json('data.id');
    $profilo = alFinto('POST', '/v1/crm/profili-fatturazione', ['persona_id' => $persona, 'tipo_intestatario' => 'privato', 'paese' => 'IT', 'intestatario' => 'Mario Rossi', 'codice_fiscale' => CODICE_FISCALE_DEL_FINTO, 'email_fatture' => ['mario@example.com']], $gettoni['carla'])->json('data.id');
    $indirizzo = alFinto('POST', '/v1/crm/indirizzi-spedizione', ['persona_id' => $persona, 'via' => 'Via Dante 2', 'cap' => '10121', 'citta' => 'Torino', 'paese' => 'IT', 'destinatario' => 'Mario Rossi'], $gettoni['carla'])->json('data.id');

    alFinto('POST', "/v1/crm/persone/{$persona}/anonimizzazione", null, $gettoni['anna']);

    $profiloDopo = alFinto('GET', "/v1/crm/profili-fatturazione/{$profilo}", null, $gettoni['anna'])->json('data');
    $indirizzoDopo = alFinto('GET', "/v1/crm/indirizzi-spedizione/{$indirizzo}", null, $gettoni['anna'])->json('data');
    $nuovo = alFinto('POST', '/v1/crm/indirizzi-spedizione', ['persona_id' => $persona, 'via' => 'Via Roma', 'cap' => '00100', 'citta' => 'Roma', 'paese' => 'IT'], $gettoni['carla']);
    $modifica = alFinto('PATCH', "/v1/crm/profili-fatturazione/{$profilo}", ['nome_profilo' => 'Nuovo'], $gettoni['carla']);

    expect($profiloDopo)->toMatchArray(['intestatario' => null, 'codice_fiscale' => null, 'email_fatture' => [], 'predefinito' => false, 'paese' => 'IT', 'tipo_intestatario' => 'privato'])
        ->and($profiloDopo['archiviato_il'])->not->toBeNull()
        ->and($indirizzoDopo)->toMatchArray(['via' => null, 'destinatario' => null, 'citta' => null, 'predefinito' => false, 'paese' => 'IT'])
        ->and($indirizzoDopo['archiviato_il'])->not->toBeNull()
        ->and($nuovo->status())->toBe(409)
        ->and($nuovo->json('codice'))->toBe('persona_anonimizzata')
        ->and($modifica->status())->toBe(409);
});

it('un profilo o un indirizzo di un altro workspace, o che non c\'è, è 404 con lo stesso corpo; il CRM spento è 403 (PF.12)', function () {
    [$finto, $gettoni] = studioColCrm();
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla'])->json('data.id');
    $profilo = alFinto('POST', '/v1/crm/profili-fatturazione', profiloDelFinto($azienda), $gettoni['carla'])->json('data.id');
    $altro = $finto->workspace('Altro studio', $finto->persona('dario@example.com', PASSWORD, 'Dario'));
    $finto->attivaApp($altro, 'crm');
    $dario = alFinto('POST', '/v1/gettoni', ['workspace_id' => $altro['id']], entraNelFinto('dario@example.com')['gettone']['gettone'])->json('data.gettone');

    $estraneo = alFinto('GET', "/v1/crm/profili-fatturazione/{$profilo}", null, $dario);
    $manca = alFinto('GET', '/v1/crm/profili-fatturazione/01k7c4d6f8h0k2n4q6s8v0x2za', null, $dario);
    $indirizzoEstraneo = alFinto('PATCH', "/v1/crm/indirizzi-spedizione/{$profilo}", ['via' => 'Via X'], $dario);

    expect($estraneo->status())->toBe(404)
        ->and($estraneo->json('codice'))->toBe('non_trovato')
        ->and($estraneo->json('detail'))->toBe($manca->json('detail'))
        ->and($indirizzoEstraneo->status())->toBe(404);
});

it('la verifica VIES: valida con la proposta (non scritta sul profilo), non_valida per un numero che il VIES non riconosce, da_verificare se è guasto; il profilo non si blocca mai (VI.1)', function () {
    [$finto, $gettoni] = studioColCrm();
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla'])->json('data.id');
    $creato = alFinto('POST', '/v1/crm/profili-fatturazione', profiloDelFinto($azienda), $gettoni['carla']);
    $profilo = $creato->json('data.id');
    $url = "/v1/crm/profili-fatturazione/{$profilo}/verifica-vies";

    $sconosciuta = alFinto('POST', $url, null, $gettoni['carla']);
    $finto->viesValide(['it 123 45678-903' => ['nome' => 'ROSSI SRL', 'indirizzo' => "VIA ROMA 1\n20121 MILANO MI"]]);
    $valida = alFinto('POST', $url, null, $gettoni['carla']);
    $riletta = alFinto('GET', "/v1/crm/profili-fatturazione/{$profilo}", null, $gettoni['anna']);
    $finto->guastaVies();
    $guasta = alFinto('POST', $url, null, $gettoni['bruno']);

    expect($sconosciuta->status())->toBe(200)
        ->and($sconosciuta->json('data.vies.esito'))->toBe('non_valida')
        ->and($sconosciuta->json('data.vies.verificato_il'))->not->toBeNull()
        ->and($sconosciuta->json('proposta'))->toBeNull()
        ->and($valida->status())->toBe(200)
        ->and($valida->json('data.vies.esito'))->toBe('valida')
        ->and($valida->json('proposta'))->toBe(['intestatario' => 'ROSSI SRL', 'indirizzo' => "VIA ROMA 1\n20121 MILANO MI"])
        ->and($valida->json('data.intestatario'))->toBe('Rossi Srl')
        ->and($valida->header('ETag'))->not->toBe($creato->header('ETag'))
        ->and($riletta->json('data.vies'))->toBe($valida->json('data.vies'))
        ->and($guasta->status())->toBe(200)
        ->and($guasta->json('data.vies'))->toBe(['esito' => 'da_verificare', 'verificato_il' => null])
        ->and($guasta->json('proposta'))->toBeNull()
        ->and($guasta->json('data.partita_iva'))->toBe('IT'.PARTITA_IVA_DEL_FINTO);
});

it('la verifica VIES di un paese che non dà nome e indirizzo ha la proposta a null (VI.2)', function () {
    [$finto, $gettoni] = studioColCrm();
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla'])->json('data.id');
    $profilo = alFinto('POST', '/v1/crm/profili-fatturazione', ['organizzazione_id' => $azienda, 'tipo_intestatario' => 'azienda', 'paese' => 'DE', 'partita_iva' => 'DE123456789'], $gettoni['carla'])->json('data.id');
    $finto->viesValide(['DE123456789' => []]);

    $risposta = alFinto('POST', "/v1/crm/profili-fatturazione/{$profilo}/verifica-vies", null, $gettoni['carla']);

    expect($risposta->json('data.vies.esito'))->toBe('valida')->and($risposta->json('proposta'))->toBeNull();
});

it('un profilo senza partita IVA, o di un paese fuori dall\'UE, è 422 sul pointer della partita IVA e non si verifica (VI.3)', function () {
    [, $gettoni] = studioColCrm();
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla'])->json('data.id');
    $senza = alFinto('POST', '/v1/crm/profili-fatturazione', ['organizzazione_id' => $azienda, 'tipo_intestatario' => 'azienda', 'paese' => 'IT'], $gettoni['carla'])->json('data.id');
    $fuori = alFinto('POST', '/v1/crm/profili-fatturazione', ['organizzazione_id' => $azienda, 'tipo_intestatario' => 'azienda', 'paese' => 'US', 'partita_iva' => 'AB123456'], $gettoni['carla'])->json('data.id');

    foreach ([$senza, $fuori] as $id) {
        $risposta = alFinto('POST', "/v1/crm/profili-fatturazione/{$id}/verifica-vies", null, $gettoni['carla']);

        expect($risposta->status())->toBe(422)
            ->and($risposta->json('codice'))->toBe('dati_non_validi')
            ->and($risposta->json('errors.0.pointer'))->toBe('#/partita_iva')
            ->and($risposta->json('errors.0.regola'))->toBe('campo_indispensabile');
    }
});

it('cambiare il paese o la partita IVA riporta il VIES a non_verificata; un altro campo non lo tocca (VI.4)', function () {
    [$finto, $gettoni] = studioColCrm();
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla'])->json('data.id');
    $profilo = alFinto('POST', '/v1/crm/profili-fatturazione', profiloDelFinto($azienda), $gettoni['carla'])->json('data.id');
    $finto->viesValide(['IT'.PARTITA_IVA_DEL_FINTO => []]);
    alFinto('POST', "/v1/crm/profili-fatturazione/{$profilo}/verifica-vies", null, $gettoni['carla']);

    $altro = alFinto('PATCH', "/v1/crm/profili-fatturazione/{$profilo}", ['intestatario' => 'Rossi 2 Srl'], $gettoni['carla']);
    $numero = alFinto('PATCH', "/v1/crm/profili-fatturazione/{$profilo}", ['partita_iva' => '01234567897'], $gettoni['carla']);

    expect($altro->json('data.vies.esito'))->toBe('valida')
        ->and($numero->json('data.vies'))->toBe(['esito' => 'non_verificata', 'verificato_il' => null]);
});

it('l\'oblio del contatto riporta a non_verificata il VIES dei suoi profili (VI.5)', function () {
    [$finto, $gettoni] = studioColCrm();
    $persona = alFinto('POST', '/v1/crm/persone', ['nome' => 'Giulia'], $gettoni['carla'])->json('data.id');
    $profilo = alFinto('POST', '/v1/crm/profili-fatturazione', ['persona_id' => $persona, 'tipo_intestatario' => 'professionista', 'paese' => 'IT', 'partita_iva' => PARTITA_IVA_DEL_FINTO], $gettoni['carla'])->json('data.id');
    $finto->viesValide(['IT'.PARTITA_IVA_DEL_FINTO => []]);
    alFinto('POST', "/v1/crm/profili-fatturazione/{$profilo}/verifica-vies", null, $gettoni['carla']);

    alFinto('POST', "/v1/crm/persone/{$persona}/anonimizzazione", null, $gettoni['anna']);

    expect(alFinto('GET', "/v1/crm/profili-fatturazione/{$profilo}", null, $gettoni['anna'])->json('data.vies'))->toBe(['esito' => 'non_verificata', 'verificato_il' => null]);
});

it('i campi «tuoi» valgono per i profili e gli indirizzi: oggetti fatturazione e spedizione, valori in crea, modifica e mostra, in elenco solo gli in_elenco, mai nella ricerca; l\'oblio li toglie (#2755)', function () {
    [, $gettoni] = studioColCrm();
    $carla = $gettoni['carla'];
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $carla)->json('data.id');
    $sezioni = alFinto('GET', '/v1/crm/sezioni?oggetto=fatturazione', null, $carla);
    $sigla = alFinto('POST', '/v1/crm/campi', ['oggetto' => 'fatturazione', 'nome' => ['it' => 'Sigla'], 'tipo' => 'testo', 'in_elenco' => true], $gettoni['anna']);
    alFinto('POST', '/v1/crm/campi', ['oggetto' => 'fatturazione', 'nome' => ['it' => 'Nota'], 'tipo' => 'testo'], $gettoni['anna']);
    alFinto('POST', '/v1/crm/campi', ['oggetto' => 'spedizione', 'nome' => ['it' => 'Orario'], 'tipo' => 'testo'], $gettoni['anna']);

    expect(collect($sezioni->json('data'))->pluck('id')->all())->toBe(['fatturazione.principali'])
        ->and($sigla->status())->toBe(201)
        ->and($sigla->json('data.id'))->toBe('fatturazione.sigla');

    $senza = alFinto('POST', '/v1/crm/profili-fatturazione', profiloDelFinto($azienda), $carla);
    $creato = alFinto('POST', '/v1/crm/profili-fatturazione', profiloDelFinto($azienda, ['campi' => ['sigla' => 'RS', 'nota' => 'x']]), $carla);
    $id = $creato->json('data.id');

    expect($senza->json('data.campi'))->toBe([])
        ->and($creato->json('data.campi'))->toBe(['sigla' => 'RS', 'nota' => 'x'])
        ->and(alFinto('POST', '/v1/crm/profili-fatturazione', profiloDelFinto($azienda, ['campi' => ['sigla' => 5]]), $carla)->status())->toBe(422)
        ->and(alFinto('POST', '/v1/crm/profili-fatturazione', profiloDelFinto($azienda, ['campi' => ['estraneo' => 'x']]), $carla)->json('errors.0.pointer'))->toBe('#/campi/estraneo');

    $prima = alFinto('GET', "/v1/crm/profili-fatturazione/{$id}", null, $carla);
    $modificato = alFinto('PATCH', "/v1/crm/profili-fatturazione/{$id}", ['campi' => ['sigla' => 'RSS', 'nota' => null]], $carla);
    $uguale = alFinto('PATCH', "/v1/crm/profili-fatturazione/{$id}", ['campi' => ['sigla' => 'RSS']], $carla);

    expect($modificato->json('data.campi'))->toBe(['sigla' => 'RSS'])
        ->and($modificato->json('data.aggiornato_il'))->toBeGreaterThan($prima->json('data.aggiornato_il'))
        ->and($modificato->header('ETag'))->not->toBe($prima->header('ETag'))
        ->and($uguale->json('data.aggiornato_il'))->toBe($modificato->json('data.aggiornato_il'));

    $elenco = alFinto('GET', "/v1/crm/profili-fatturazione?organizzazione_id={$azienda}", null, $carla)->json('data');
    $ricerca = alFinto('POST', '/v1/crm/profili-fatturazione/ricerca', ['partita_iva' => PARTITA_IVA_DEL_FINTO], $carla)->json('data.0');

    expect(collect($elenco)->firstWhere('id', $id)['campi'])->toBe(['sigla' => 'RSS'])
        ->and(array_key_exists('campi', $ricerca))->toBeFalse();

    $indirizzo = alFinto('POST', '/v1/crm/indirizzi-spedizione', indirizzoDiSpedizioneDelFinto($azienda, ['campi' => ['orario' => 'mattina']]), $carla);

    expect($indirizzo->json('data.campi'))->toBe(['orario' => 'mattina'])
        ->and(alFinto('PATCH', '/v1/crm/indirizzi-spedizione/'.$indirizzo->json('data.id'), ['campi' => ['orario' => null]], $carla)->json('data.campi'))->toBe([]);
});
