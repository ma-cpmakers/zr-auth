<?php

// #2753 (CRM-12 F3): gli elenchi di valori condivisi del CRM e i campi che li usano, nel finto. Qui ciò che un frontend vede dal suo
// lato; che le risposte e gli errori siano quelli del backoffice lo prova il suo FintoTest.

/** @return array<string, mixed> un elenco di prova, con i valori dati per chiave => nome `it` */
function elencoDelFinto(string $gettone, string $nome = 'Livelli', array $valori = ['alfa' => 'Alfa', 'beta' => 'Beta', 'gamma' => 'Gamma']): array
{
    $righe = [];

    foreach ($valori as $chiave => $it) {
        $righe[] = ['chiave' => (string) $chiave, 'nome' => ['it' => $it]];
    }

    $risposta = alFinto('POST', '/v1/crm/elenchi', ['nome' => ['it' => $nome], 'valori' => $righe], $gettone);
    expect($risposta->status())->toBe(201, $risposta->body());

    return $risposta->json('data');
}

/** @return list<string> le chiavi dei valori di un elenco, nell'ordine in cui l'API li dà */
function chiaviDelFinto(string $gettone, string $elenco): array
{
    return array_column(alFinto('GET', "/v1/crm/elenchi/{$elenco}", null, $gettone)->json('data.valori'), 'chiave');
}

it('un elenco nasce con 201, Location ed ETag; i valori hanno posizione da 1, colore minuscolo e archiviato_il null (EF.1)', function () {
    [, $gettoni] = studioColCrm();

    $creato = alFinto('POST', '/v1/crm/elenchi', [
        'nome' => ['it' => 'Livelli', 'en' => 'Levels'],
        'valori' => [['chiave' => 'alfa', 'nome' => ['it' => 'Alfa'], 'colore' => '#A1B2C3'], ['chiave' => 'beta', 'nome' => ['it' => 'Beta', 'es' => 'Beta es']]],
    ], $gettoni['anna']);
    $elenco = $creato->json('data');

    expect($creato->status())->toBe(201)
        ->and($creato->header('Location'))->toBe("/v1/crm/elenchi/{$elenco['id']}")
        ->and($creato->header('ETag'))->toStartWith('"')
        ->and(array_keys($elenco))->toBe(['id', 'nome', 'valori', 'archiviato_il', 'creato_il', 'aggiornato_il'])
        ->and($elenco['nome'])->toBe(['it' => 'Livelli', 'en' => 'Levels', 'es' => null])
        ->and(array_keys($elenco['valori'][0]))->toBe(['id', 'chiave', 'nome', 'colore', 'posizione', 'archiviato_il'])
        ->and($elenco['valori'][0]['colore'])->toBe('#a1b2c3')
        ->and($elenco['valori'][1]['nome'])->toBe(['it' => 'Beta', 'en' => null, 'es' => 'Beta es'])
        ->and(array_column($elenco['valori'], 'posizione'))->toBe([1, 2])
        ->and(alFinto('GET', "/v1/crm/elenchi/{$elenco['id']}", null, $gettoni['carla'])->json('data'))->toBe($elenco);

    expect(alFinto('POST', '/v1/crm/elenchi', ['nome' => ['it' => 'Vuoto']], $gettoni['anna'])->json('data.valori'))->toBe([]);
});

it('il membro legge e non scrive; l\'amministratore scrive; un elenco che non c\'è è 404 (EF.2)', function () {
    [, $gettoni] = studioColCrm();
    $elenco = elencoDelFinto($gettoni['anna']);

    expect(alFinto('POST', '/v1/crm/elenchi', ['nome' => ['it' => 'Nuovo']], $gettoni['carla'])->status())->toBe(403)
        ->and(alFinto('PATCH', "/v1/crm/elenchi/{$elenco['id']}", ['nome' => ['it' => 'Cambiato']], $gettoni['carla'])->json('codice'))->toBe('permesso_negato')
        ->and(alFinto('GET', '/v1/crm/elenchi', null, $gettoni['carla'])->status())->toBe(200)
        ->and(alFinto('GET', "/v1/crm/elenchi/{$elenco['id']}", null, $gettoni['carla'])->status())->toBe(200)
        ->and(alFinto('POST', '/v1/crm/elenchi', ['nome' => ['it' => 'Di Bruno']], $gettoni['bruno'])->status())->toBe(201)
        ->and(alFinto('GET', '/v1/crm/elenchi/01k6w6a2c4e6g8j0m2p4r6t8v1', null, $gettoni['anna'])->status())->toBe(404)
        ->and(alFinto('PATCH', '/v1/crm/elenchi/01k6w6a2c4e6g8j0m2p4r6t8v1', ['archiviato' => true], $gettoni['anna'])->status())->toBe(404);
});

it('il corpo sbagliato è 422 col suo pointer e non scrive niente (EF.1)', function () {
    [, $gettoni] = studioColCrm();
    $nome = ['it' => 'Livelli'];
    $casi = [
        [[], '#/nome'],
        [['nome' => ['en' => 'Levels']], '#/nome/it'],
        [['nome' => $nome, 'altro' => 1], '#/altro'],
        [['nome' => $nome, 'valori' => [['nome' => ['it' => 'Alfa']]]], '#/valori/0/chiave'],
        [['nome' => $nome, 'valori' => [['chiave' => 'Alfa Uno', 'nome' => ['it' => 'Alfa']]]], '#/valori/0/chiave'],
        [['nome' => $nome, 'valori' => [['chiave' => 'alfa', 'nome' => ['it' => 'Alfa'], 'colore' => 'verde']]], '#/valori/0/colore'],
        [['nome' => $nome, 'valori' => [['chiave' => 'alfa', 'nome' => ['it' => 'Alfa']], ['chiave' => 'alfa', 'nome' => ['it' => 'Beta']]]], '#/valori/1/chiave'],
    ];

    foreach ($casi as [$corpo, $pointer]) {
        $r = alFinto('POST', '/v1/crm/elenchi', $corpo, $gettoni['anna']);

        expect($r->status())->toBe(422)->and($r->json('errors.0.pointer'))->toBe($pointer);
    }

    expect(alFinto('GET', '/v1/crm/elenchi', null, $gettoni['anna'])->json('data'))->toBe([]);
});

it('i valori di una modifica sono un merge: si aggiunge in fondo, si cambia per id o per chiave, si sposta, e il resto resta com\'era (EF.3)', function () {
    [, $gettoni] = studioColCrm();
    $elenco = elencoDelFinto($gettoni['anna']);
    $beta = collect($elenco['valori'])->firstWhere('chiave', 'beta');

    $r = alFinto('PATCH', "/v1/crm/elenchi/{$elenco['id']}", ['valori' => [['chiave' => 'delta', 'nome' => ['it' => 'Delta'], 'colore' => '#00FF00']]], $gettoni['anna']);

    expect($r->status())->toBe(200)
        ->and(array_column($r->json('data.valori'), 'chiave'))->toBe(['alfa', 'beta', 'gamma', 'delta'])
        ->and(array_slice($r->json('data.valori'), 0, 3))->toBe($elenco['valori'])
        ->and($r->json('data.valori.3.colore'))->toBe('#00ff00');

    $r = alFinto('PATCH', "/v1/crm/elenchi/{$elenco['id']}", ['valori' => [['id' => $beta['id'], 'nome' => ['it' => 'Beta due', 'en' => 'Beta two'], 'posizione' => 1]]], $gettoni['anna']);

    expect(chiaviDelFinto($gettoni['anna'], $elenco['id']))->toBe(['beta', 'alfa', 'gamma', 'delta'])
        ->and(collect($r->json('data.valori'))->firstWhere('chiave', 'beta'))->toMatchArray(['id' => $beta['id'], 'nome' => ['it' => 'Beta due', 'en' => 'Beta two', 'es' => null], 'posizione' => 1]);

    $errori = [
        [['valori' => [['id' => $beta['id'], 'chiave' => 'altra']]], '#/valori/0/chiave'],
        [['valori' => [['id' => '01k6w6a2c4e6g8j0m2p4r6t8v1', 'nome' => ['it' => 'X']]]], '#/valori/0/id'],
        [['valori' => [['chiave' => 'epsilon']]], '#/valori/0/nome/it'],
        [['valori' => ['chiave' => 'epsilon']], '#/valori'],
        [['nome' => ['it' => null]], '#/nome/it'],
        [['altro' => 1], '#/altro'],
    ];

    foreach ($errori as [$corpo, $pointer]) {
        $r = alFinto('PATCH', "/v1/crm/elenchi/{$elenco['id']}", $corpo, $gettoni['anna']);

        expect($r->status())->toBe(422, $r->body())->and($r->json('errors.0.pointer'))->toBe($pointer);
    }

    expect(chiaviDelFinto($gettoni['anna'], $elenco['id']))->toBe(['beta', 'alfa', 'gamma', 'delta']);
});

it('archiviare un valore lo porta in fondo con posizione null; ripristinarlo lo rimette in fondo ai vivi o al posto chiesto (EF.4)', function () {
    [, $gettoni] = studioColCrm();
    $elenco = elencoDelFinto($gettoni['anna']);
    $alfa = collect($elenco['valori'])->firstWhere('chiave', 'alfa');

    $r = alFinto('PATCH', "/v1/crm/elenchi/{$elenco['id']}", ['valori' => [['id' => $alfa['id'], 'archiviato' => true]]], $gettoni['anna']);

    expect(array_column($r->json('data.valori'), 'chiave'))->toBe(['beta', 'gamma', 'alfa'])
        ->and(array_column($r->json('data.valori'), 'posizione'))->toBe([1, 2, null])
        ->and($r->json('data.valori.2.archiviato_il'))->not->toBeNull();

    $conPosto = alFinto('PATCH', "/v1/crm/elenchi/{$elenco['id']}", ['valori' => [['id' => $alfa['id'], 'archiviato' => true, 'posizione' => 1]]], $gettoni['anna']);
    $nuovoArchiviato = alFinto('PATCH', "/v1/crm/elenchi/{$elenco['id']}", ['valori' => [['chiave' => 'nuovo', 'nome' => ['it' => 'Nuovo'], 'archiviato' => true]]], $gettoni['anna']);

    expect($conPosto->status())->toBe(422)->and($conPosto->json('errors.0.pointer'))->toBe('#/valori/0/posizione')
        ->and($nuovoArchiviato->status())->toBe(422)->and($nuovoArchiviato->json('errors.0.pointer'))->toBe('#/valori/0/archiviato');

    $r = alFinto('PATCH', "/v1/crm/elenchi/{$elenco['id']}", ['valori' => [['id' => $alfa['id'], 'archiviato' => false, 'posizione' => 1]]], $gettoni['anna']);

    expect(array_column($r->json('data.valori'), 'chiave'))->toBe(['alfa', 'beta', 'gamma'])
        ->and(array_column($r->json('data.valori'), 'archiviato_il'))->toBe([null, null, null]);
});

it('i valori sono al più 500, archiviati compresi, e gli elenchi 200 per workspace: il successivo è 409 limite_raggiunto (EF.5)', function () {
    [, $gettoni] = studioColCrm();
    $valori = [];

    for ($i = 1; $i <= 500; $i++) {
        $valori["v{$i}"] = "Valore {$i}";
    }

    $grande = elencoDelFinto($gettoni['anna'], 'Grande', $valori);
    $r = alFinto('PATCH', "/v1/crm/elenchi/{$grande['id']}", ['valori' => [['chiave' => 'v501', 'nome' => ['it' => 'Valore 501']]]], $gettoni['anna']);

    expect($r->status())->toBe(409)->and($r->json('codice'))->toBe('limite_raggiunto')->and($r->json('limite'))->toBe(500)
        ->and(alFinto('PATCH', "/v1/crm/elenchi/{$grande['id']}", ['valori' => [['id' => $grande['valori'][0]['id'], 'archiviato' => true]]], $gettoni['anna'])->status())->toBe(200)
        ->and(alFinto('PATCH', "/v1/crm/elenchi/{$grande['id']}", ['valori' => [['chiave' => 'v501', 'nome' => ['it' => 'Valore 501']]]], $gettoni['anna'])->status())->toBe(409);

    for ($i = 2; $i <= 200; $i++) {
        expect(alFinto('POST', '/v1/crm/elenchi', ['nome' => ['it' => "Elenco {$i}"]], $gettoni['anna'])->status())->toBe(201);
    }

    $oltre = alFinto('POST', '/v1/crm/elenchi', ['nome' => ['it' => 'Il 201°']], $gettoni['anna']);

    expect($oltre->status())->toBe(409)->and($oltre->json('limite'))->toBe(200);
});

it('elenca va a cursore dal più vecchio e dà gli archiviati solo con archiviati=true (EF.6)', function () {
    [, $gettoni] = studioColCrm();
    $uno = elencoDelFinto($gettoni['anna'], 'Uno');
    $due = elencoDelFinto($gettoni['anna'], 'Due');
    $tre = elencoDelFinto($gettoni['anna'], 'Tre');
    alFinto('PATCH', "/v1/crm/elenchi/{$due['id']}", ['archiviato' => true], $gettoni['anna']);

    $prima = alFinto('GET', '/v1/crm/elenchi?limite=1', null, $gettoni['anna']);
    $seconda = alFinto('GET', '/v1/crm/elenchi?limite=1&cursore='.$prima->json('successivo'), null, $gettoni['anna']);

    expect(array_column(alFinto('GET', '/v1/crm/elenchi', null, $gettoni['anna'])->json('data'), 'id'))->toBe([$uno['id'], $tre['id']])
        ->and(array_column(alFinto('GET', '/v1/crm/elenchi?archiviati=true', null, $gettoni['anna'])->json('data'), 'id'))->toBe([$due['id']])
        ->and(array_column($prima->json('data'), 'id'))->toBe([$uno['id']])
        ->and(array_column($seconda->json('data'), 'id'))->toBe([$tre['id']])
        ->and($seconda->json('successivo'))->toBeNull()
        ->and(alFinto('GET', '/v1/crm/elenchi?archiviati=forse', null, $gettoni['anna'])->status())->toBe(422);
});

it('mostra dà l\'ETag e 304 con If-None-Match; modifica con If-Match vecchio è 412 e un corpo che non cambia niente lascia lo stesso ETag (EF.7)', function () {
    [, $gettoni] = studioColCrm();
    $elenco = elencoDelFinto($gettoni['anna']);
    $etag = alFinto('GET', "/v1/crm/elenchi/{$elenco['id']}", null, $gettoni['anna'])->header('ETag');

    $nessunCambio = alFinto('PATCH', "/v1/crm/elenchi/{$elenco['id']}", ['nome' => ['it' => 'Livelli'], 'valori' => [['chiave' => 'alfa', 'nome' => ['it' => 'Alfa']]]], $gettoni['anna']);
    $nuovo = alFinto('PATCH', "/v1/crm/elenchi/{$elenco['id']}", ['nome' => ['it' => 'Uno']], $gettoni['anna'], null, ['If-Match' => $etag]);
    $vecchio = alFinto('PATCH', "/v1/crm/elenchi/{$elenco['id']}", ['nome' => ['it' => 'Due']], $gettoni['anna'], null, ['If-Match' => $etag]);

    expect(alFinto('GET', "/v1/crm/elenchi/{$elenco['id']}", null, $gettoni['anna'], null, ['If-None-Match' => $etag])->status())->toBe(304)
        ->and($nessunCambio->header('ETag'))->toBe($etag)
        ->and($nuovo->status())->toBe(200)->and($nuovo->header('ETag'))->not->toBe($etag)
        ->and($vecchio->status())->toBe(412)->and($vecchio->json('codice'))->toBe('precondizione_fallita')
        ->and(alFinto('GET', "/v1/crm/elenchi/{$elenco['id']}", null, $gettoni['anna'])->json('data.nome.it'))->toBe('Uno');
});

it('un campo «tuo» usa un elenco condiviso: elenco_id, opzioni dell\'elenco nel suo ordine, e un elenco archiviato o che non c\'è è 422 (EF.8)', function () {
    [, $gettoni] = studioColCrm();
    $elenco = elencoDelFinto($gettoni['anna']);
    $archiviato = elencoDelFinto($gettoni['anna'], 'Archiviato');
    alFinto('PATCH', "/v1/crm/elenchi/{$archiviato['id']}", ['archiviato' => true], $gettoni['anna']);

    $creato = alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Livello'], 'tipo' => 'elenco', 'opzioni' => ['elenco_id' => $elenco['id']]], $gettoni['anna']);
    $multipla = alFinto('POST', '/v1/crm/campi', ['oggetto' => 'organizzazione', 'nome' => ['it' => 'Zona'], 'tipo' => 'scelta_multipla', 'opzioni' => ['elenco_id' => $elenco['id']]], $gettoni['anna']);

    expect($creato->status())->toBe(201)
        ->and($creato->json('data.elenco_id'))->toBe($elenco['id'])
        ->and(array_column($creato->json('data.opzioni'), 'id'))->toBe(array_column($elenco['valori'], 'id'))
        ->and($multipla->status())->toBe(201)
        ->and(collect(alFinto('GET', '/v1/crm/campi?oggetto=persona', null, $gettoni['anna'])->json('data'))->firstWhere('id', 'persona.livello')['elenco_id'])->toBe($elenco['id']);

    foreach ([$archiviato['id'], '01k6w6a2c4e6g8j0m2p4r6t8v1'] as $id) {
        $r = alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Altro'], 'tipo' => 'elenco', 'opzioni' => ['elenco_id' => $id]], $gettoni['anna']);

        expect($r->status())->toBe(422)->and($r->json('errors.0.pointer'))->toBe('#/opzioni/elenco_id')->and($r->json('errors.0.regola'))->toBe('valore_non_ammesso');
    }

    $testo = alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Testo'], 'tipo' => 'testo', 'opzioni' => ['elenco_id' => $elenco['id']]], $gettoni['anna']);
    $misto = alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Misto'], 'tipo' => 'elenco', 'opzioni' => ['elenco_id' => $elenco['id'], 'altro' => 1]], $gettoni['anna']);

    expect($testo->status())->toBe(422)->and($testo->json('errors.0.regola'))->toBe('campo_vietato')
        ->and($misto->status())->toBe(422)
        ->and(alFinto('POST', '/v1/crm/campi/persona.livello/opzioni', ['nome' => ['it' => 'Nuova']], $gettoni['anna'])->status())->toBe(404);
});

it('i record salvano l\'id del valore: rinominarlo lo cambia nel campo, uno archiviato resta a chi lo ha e nessun altro lo prende (EF.9)', function () {
    [, $gettoni] = studioColCrm();
    $elenco = elencoDelFinto($gettoni['anna']);
    alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Livello'], 'tipo' => 'elenco', 'opzioni' => ['elenco_id' => $elenco['id']]], $gettoni['anna']);
    $beta = collect($elenco['valori'])->firstWhere('chiave', 'beta');
    $giulia = alFinto('POST', '/v1/crm/persone', ['nome' => 'Giulia', 'campi' => ['livello' => $beta['id']]], $gettoni['anna']);
    $marta = alFinto('POST', '/v1/crm/persone', ['nome' => 'Marta'], $gettoni['anna'])->json('data.id');

    expect($giulia->status())->toBe(201, $giulia->body())->and($giulia->json('data.campi.livello'))->toBe($beta['id']);

    alFinto('PATCH', "/v1/crm/elenchi/{$elenco['id']}", ['valori' => [['id' => $beta['id'], 'nome' => ['it' => 'Beta nuovo']]]], $gettoni['anna']);
    $opzioni = collect(alFinto('GET', '/v1/crm/campi?oggetto=persona', null, $gettoni['anna'])->json('data'))->firstWhere('id', 'persona.livello')['opzioni'];

    expect(collect($opzioni)->firstWhere('id', $beta['id'])['nome']['it'])->toBe('Beta nuovo');

    alFinto('PATCH', "/v1/crm/elenchi/{$elenco['id']}", ['valori' => [['id' => $beta['id'], 'archiviato' => true]]], $gettoni['anna']);
    $presa = alFinto('PATCH', "/v1/crm/persone/{$marta}", ['campi' => ['livello' => $beta['id']]], $gettoni['anna']);
    $ridata = alFinto('PATCH', "/v1/crm/persone/{$giulia->json('data.id')}", ['campi' => ['livello' => $beta['id']], 'nome' => 'Giulia B'], $gettoni['anna']);

    expect($presa->status())->toBe(422)->and($presa->json('errors.0.pointer'))->toBe('#/campi/livello')
        ->and($ridata->status())->toBe(200, $ridata->body());
});

it('un elenco usato da un campo vivo non si archivia: 409 elenco_in_uso coi campi; senza campi vivi sì (EF.10)', function () {
    [, $gettoni] = studioColCrm();
    $elenco = elencoDelFinto($gettoni['anna']);

    foreach ([['persona', 'Zona'], ['persona', 'Livello'], ['organizzazione', 'Livello']] as [$oggetto, $nome]) {
        alFinto('POST', '/v1/crm/campi', ['oggetto' => $oggetto, 'nome' => ['it' => $nome], 'tipo' => 'elenco', 'opzioni' => ['elenco_id' => $elenco['id']]], $gettoni['anna']);
    }

    $r = alFinto('PATCH', "/v1/crm/elenchi/{$elenco['id']}", ['archiviato' => true], $gettoni['anna']);

    expect($r->status())->toBe(409)->and($r->json('codice'))->toBe('elenco_in_uso')
        ->and($r->json('campi'))->toBe(['organizzazione.livello', 'persona.livello', 'persona.zona'])
        ->and(alFinto('PATCH', "/v1/crm/elenchi/{$elenco['id']}", ['nome' => ['it' => 'Cambiato'], 'archiviato' => true], $gettoni['anna'])->status())->toBe(409)
        ->and(alFinto('GET', "/v1/crm/elenchi/{$elenco['id']}", null, $gettoni['anna'])->json('data.nome.it'))->toBe('Livelli');

    foreach (['persona.zona', 'persona.livello', 'organizzazione.livello'] as $id) {
        expect(alFinto('DELETE', "/v1/crm/campi/{$id}", null, $gettoni['anna'])->status())->toBe(204);
    }

    expect(alFinto('PATCH', "/v1/crm/elenchi/{$elenco['id']}", ['archiviato' => true], $gettoni['anna'])->json('data.archiviato_il'))->not->toBeNull();
});
