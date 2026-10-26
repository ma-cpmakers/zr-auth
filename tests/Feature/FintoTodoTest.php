<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #2464 #2465 #2466 #2467 #2550-#2553 (PM-11, backoffice): il To-do della persona nel finto — «Personale» alla prima lettura, la forma di una
// lista e di un to-do, le viste e gli ordini, il cursore, i tetti, il posto (`dopo_id`, `lista_id`), il completamento, il riepilogo, i tag e
// l'ETag con If-Match. Che le risposte e gli errori siano quelli del backoffice lo prova il FintoTest del backoffice.

/**
 * Un workspace con `pm` attiva e due membri: [finto, gettoni per nome].
 *
 * @return array{BackofficeFinto, array<string, string>}
 */
function studioColTodo(): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD, 'Anna');
    $studio = $finto->workspace('Studio', $anna);
    $finto->membro($studio, $finto->persona('bruno@example.com', PASSWORD, 'Bruno'), 'membro');
    $finto->attivaApp($studio, 'pm');
    $gettoni = [];

    foreach (['anna', 'bruno'] as $nome) {
        $gettoni[$nome] = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto("{$nome}@example.com")['gettone']['gettone'])->json('data.gettone');
    }

    return [$finto, $gettoni];
}

/** Un to-do di Anna (o di chi ha il gettone): l'`id`. */
function nuovoTodo(string $gettone, array $corpo): string
{
    $risposta = alFinto('POST', '/v1/board/todo/elementi', $corpo, $gettone);
    expect($risposta->status())->toBe(201, $risposta->body());

    return $risposta->json('data.id');
}

/** @return list<string> i titoli di una lettura, nell'ordine in cui arrivano */
function titoliDelTodo(string $gettone, string $query): array
{
    $risposta = alFinto('GET', "/v1/board/todo/elementi?{$query}", null, $gettone);
    expect($risposta->status())->toBe(200, $risposta->body());

    return collect($risposta->json('data'))->pluck('titolo')->all();
}

test('«Personale» nasce alla prima lettura, in cima, con il nome della lingua della persona; la lista nuova nasce in coda (TL.1)', function () {
    [, $gettoni] = studioColTodo();

    $prima = alFinto('GET', '/v1/board/todo/liste', null, $gettoni['anna']);
    $creata = alFinto('POST', '/v1/board/todo/liste', ['nome' => 'Spesa', 'colore' => 'sky'], $gettoni['anna']);
    $dopo = alFinto('GET', '/v1/board/todo/liste', null, $gettoni['anna'])->json('data');

    expect($prima->status())->toBe(200)
        ->and($prima->json('data'))->toHaveCount(1)
        ->and($prima->json('data.0.nome'))->toBe('Personale')
        ->and($prima->json('data.0.predefinita'))->toBeTrue()
        ->and($prima->json('data.0.tipo'))->toBe('personale')
        ->and($prima->json('data.0.ordine'))->toBe('posizione')
        ->and($prima->json('data.0.posizione'))->toBe(1024)
        ->and($creata->status())->toBe(201)
        ->and($creata->header('Location'))->toBe('/v1/board/todo/liste/'.$creata->json('data.id'))
        ->and($creata->json('data.predefinita'))->toBeFalse()
        ->and($creata->json('data.posizione'))->toBe(2048)
        ->and(collect($dopo)->pluck('nome')->all())->toBe(['Personale', 'Spesa']);
});

test('una lista e un to-do sono di chi li ha creati: per ogni altro l\'id è 404, e una lista_id altrui è 422 sul campo (TL.2)', function () {
    [, $gettoni] = studioColTodo();
    $lista = alFinto('POST', '/v1/board/todo/liste', ['nome' => 'Mia'], $gettoni['anna'])->json('data.id');
    $todo = nuovoTodo($gettoni['anna'], ['titolo' => 'Solo mio']);

    $bruno = [
        alFinto('PATCH', "/v1/board/todo/liste/{$lista}", ['nome' => 'Rubata'], $gettoni['bruno']),
        alFinto('GET', "/v1/board/todo/elementi/{$todo}", null, $gettoni['bruno']),
        alFinto('DELETE', "/v1/board/todo/elementi/{$todo}", null, $gettoni['bruno']),
        alFinto('POST', "/v1/board/todo/elementi/{$todo}/completamento", null, $gettoni['bruno']),
    ];
    $nonC = alFinto('GET', '/v1/board/todo/elementi/'.str_repeat('0', 26), null, $gettoni['bruno']);
    $inUnaListaAltrui = alFinto('POST', '/v1/board/todo/elementi', ['titolo' => 'X', 'lista_id' => $lista], $gettoni['bruno']);

    expect(collect($bruno)->map->status()->all())->toBe([404, 404, 404, 404])
        ->and($bruno[1]->json('codice'))->toBe($nonC->json('codice'))
        ->and($inUnaListaAltrui->status())->toBe(422)
        ->and($inUnaListaAltrui->json('errors.0.pointer'))->toBe('#/lista_id')
        ->and(alFinto('GET', "/v1/board/todo/elementi?lista_id={$lista}", null, $gettoni['bruno'])->status())->toBe(422);
});

test('crea porta il to-do intero con la Location e l\'ETag, e lo mette in Personale in coda; l\'elenco è in forma breve, senza note (TE.1)', function () {
    [, $gettoni] = studioColTodo();
    $gettone = $gettoni['anna'];

    $primo = alFinto('POST', '/v1/board/todo/elementi', ['titolo' => '  Chiamare  ', 'note' => 'Dopo le tre', 'giorno' => '2030-05-01', 'ora' => '09:30', 'tag' => ['Casa', 'casa', 'Urgente'], 'colore' => 'coral'], $gettone);
    $secondo = alFinto('POST', '/v1/board/todo/elementi', ['titolo' => 'Scrivere'], $gettone);
    $elenco = alFinto('GET', '/v1/board/todo/elementi?vista=tutti', null, $gettone)->json('data');
    $personale = alFinto('GET', '/v1/board/todo/liste', null, $gettone)->json('data.0.id');

    expect($primo->status())->toBe(201)
        ->and($primo->header('Location'))->toBe('/v1/board/todo/elementi/'.$primo->json('data.id'))
        ->and($primo->header('ETag'))->toBe($primo->json('data.etag'))
        ->and($primo->json('data.titolo'))->toBe('Chiamare')
        ->and($primo->json('data.note'))->toBe('Dopo le tre')
        ->and($primo->json('data.tag'))->toBe(['Casa', 'Urgente'])
        ->and($primo->json('data.lista_id'))->toBe($personale)
        ->and($primo->json('data.fatto_il'))->toBeNull()
        ->and([$primo->json('data.posizione'), $secondo->json('data.posizione')])->toBe([1024, 2048])
        ->and($secondo->json('data.note'))->toBe('')
        ->and($elenco)->toHaveCount(2)
        ->and($elenco[0])->not->toHaveKey('note')
        ->and($elenco[0]['titolo'])->toBe('Chiamare');
});

test('i campi che si rifiutano: ora senza giorno, giorno fuori intervallo, fatto_il e posizione, titolo vuoto, tag con # (TE.2)', function () {
    [, $gettoni] = studioColTodo();
    $gettone = $gettoni['anna'];
    $pointer = fn (array $corpo) => alFinto('POST', '/v1/board/todo/elementi', $corpo, $gettone)->json('errors.0.pointer');

    expect($pointer(['titolo' => 'A', 'ora' => '10:00']))->toBe('#/ora')
        ->and($pointer(['titolo' => 'A', 'giorno' => '1999-12-31']))->toBe('#/giorno')
        ->and($pointer(['titolo' => 'A', 'giorno' => '2101-01-01']))->toBe('#/giorno')
        ->and($pointer(['titolo' => 'A', 'giorno' => '2030-02-30']))->toBe('#/giorno')
        ->and($pointer(['titolo' => 'A', 'fatto_il' => '2030-01-01T00:00:00Z']))->toBe('#/fatto_il')
        ->and($pointer(['titolo' => 'A', 'posizione' => 5]))->toBe('#/posizione')
        ->and($pointer(['titolo' => '']))->toBe('#/titolo')
        ->and($pointer(['titolo' => 'A', 'tag' => ['a#b']]))->toBe('#/tag/0')
        ->and($pointer(['titolo' => 'A', 'colore' => 'rosa']))->toBe('#/colore');
});

test('la vista oggi ha in ritardo e di oggi, la settimana da domani a fra sette giorni, tutti gli aperti e fatti i fatti (TV.1)', function () {
    $this->freezeTime();
    [, $gettoni] = studioColTodo();
    $gettone = $gettoni['anna'];
    $oggi = now()->setTimezone('Europe/Rome')->format('Y-m-d');
    $giorno = fn (int $in) => now()->setTimezone('Europe/Rome')->addDays($in)->format('Y-m-d');

    nuovoTodo($gettone, ['titolo' => 'ieri', 'giorno' => $giorno(-1)]);
    nuovoTodo($gettone, ['titolo' => 'oggi', 'giorno' => $oggi]);
    nuovoTodo($gettone, ['titolo' => 'domani', 'giorno' => $giorno(1)]);
    nuovoTodo($gettone, ['titolo' => 'fra sette', 'giorno' => $giorno(7)]);
    nuovoTodo($gettone, ['titolo' => 'fra otto', 'giorno' => $giorno(8)]);
    nuovoTodo($gettone, ['titolo' => 'senza giorno']);
    $fatto = nuovoTodo($gettone, ['titolo' => 'fatto', 'giorno' => $oggi]);
    alFinto('POST', "/v1/board/todo/elementi/{$fatto}/completamento", null, $gettone);

    expect(titoliDelTodo($gettone, 'vista=oggi'))->toBe(['ieri', 'oggi'])
        ->and(titoliDelTodo($gettone, 'vista=settimana'))->toBe(['domani', 'fra sette'])
        ->and(titoliDelTodo($gettone, 'vista=tutti'))->toHaveCount(6)
        ->and(titoliDelTodo($gettone, 'vista=fatti'))->toBe(['fatto']);
});

test('una lettura vuole una vista o una lista, non tutte e due; fatti e ordine solo con la lista; i valori sconosciuti sono 422 (TV.2)', function () {
    [, $gettoni] = studioColTodo();
    $gettone = $gettoni['anna'];
    $personale = alFinto('GET', '/v1/board/todo/liste', null, $gettone)->json('data.0.id');
    $stato = fn (string $query) => alFinto('GET', "/v1/board/todo/elementi?{$query}", null, $gettone)->status();

    expect($stato(''))->toBe(422)
        ->and($stato("vista=oggi&lista_id={$personale}"))->toBe(422)
        ->and($stato('vista=oggi&fatti=true'))->toBe(422)
        ->and($stato('vista=oggi&ordine=giorno'))->toBe(422)
        ->and($stato('vista=domani'))->toBe(422)
        ->and($stato("lista_id={$personale}&ordine=altezza"))->toBe(422)
        ->and($stato("lista_id={$personale}&fatti=forse"))->toBe(422)
        ->and($stato("lista_id={$personale}"))->toBe(200)
        ->and($stato("lista_id={$personale}&fatti=true&ordine=colore"))->toBe(200);
});

test('gli ordini di una lista: per posizione, per data (senza giorno e senza ora in fondo) e per colore (nessuno per ultimo) (TO.1)', function () {
    [, $gettoni] = studioColTodo();
    $gettone = $gettoni['anna'];
    $personale = alFinto('GET', '/v1/board/todo/liste', null, $gettone)->json('data.0.id');

    nuovoTodo($gettone, ['titolo' => 'A', 'giorno' => '2030-06-02', 'colore' => 'neutral']);
    nuovoTodo($gettone, ['titolo' => 'B', 'colore' => 'plum']);
    nuovoTodo($gettone, ['titolo' => 'C', 'giorno' => '2030-06-01', 'colore' => 'coral']);
    nuovoTodo($gettone, ['titolo' => 'D', 'giorno' => '2030-06-01', 'ora' => '08:00', 'colore' => 'pine']);
    nuovoTodo($gettone, ['titolo' => 'E', 'giorno' => '2030-06-02', 'ora' => '07:00', 'colore' => 'citrus']);

    expect(titoliDelTodo($gettone, "lista_id={$personale}"))->toBe(['A', 'B', 'C', 'D', 'E'])
        ->and(titoliDelTodo($gettone, "lista_id={$personale}&ordine=posizione"))->toBe(['A', 'B', 'C', 'D', 'E'])
        ->and(titoliDelTodo($gettone, "lista_id={$personale}&ordine=giorno"))->toBe(['D', 'C', 'E', 'A', 'B'])
        ->and(titoliDelTodo($gettone, "lista_id={$personale}&ordine=colore"))->toBe(['D', 'E', 'C', 'B', 'A']);
});

test('il cursore dà le pagine una dopo l\'altra senza ripetere né perdere, ma vale per un solo filtro e un solo ordine (TC.1)', function () {
    [, $gettoni] = studioColTodo();
    $gettone = $gettoni['anna'];
    $personale = alFinto('GET', '/v1/board/todo/liste', null, $gettone)->json('data.0.id');

    foreach (['uno', 'due', 'tre', 'quattro', 'cinque'] as $titolo) {
        nuovoTodo($gettone, ['titolo' => $titolo]);
    }

    $letti = [];
    $cursore = null;
    $pagine = 0;

    do {
        $risposta = alFinto('GET', "/v1/board/todo/elementi?lista_id={$personale}&limite=2".($cursore === null ? '' : "&cursore={$cursore}"), null, $gettone);
        expect($risposta->status())->toBe(200, $risposta->body());
        $letti = [...$letti, ...collect($risposta->json('data'))->pluck('titolo')->all()];
        $cursore = $risposta->json('successivo');
        $pagine++;
    } while ($cursore !== null && $pagine < 5);

    $primo = alFinto('GET', "/v1/board/todo/elementi?lista_id={$personale}&limite=2", null, $gettone)->json('successivo');
    $altroOrdine = alFinto('GET', "/v1/board/todo/elementi?lista_id={$personale}&ordine=colore&cursore={$primo}", null, $gettone);
    $altraVista = alFinto('GET', "/v1/board/todo/elementi?vista=tutti&cursore={$primo}", null, $gettone);

    expect($letti)->toBe(['uno', 'due', 'tre', 'quattro', 'cinque'])
        ->and($pagine)->toBe(3)
        ->and($altroOrdine->status())->toBe(422)
        ->and($altroOrdine->json('errors.0.parameter'))->toBe('cursore')
        ->and($altraVista->status())->toBe(422);
});

test('modifica con dopo_id mette il to-do dopo un altro, dopo_id null in testa, lista_id in coda all\'altra lista (TD.1)', function () {
    [, $gettoni] = studioColTodo();
    $gettone = $gettoni['anna'];
    $personale = alFinto('GET', '/v1/board/todo/liste', null, $gettone)->json('data.0.id');
    $spesa = alFinto('POST', '/v1/board/todo/liste', ['nome' => 'Spesa'], $gettone)->json('data.id');
    $a = nuovoTodo($gettone, ['titolo' => 'A']);
    $b = nuovoTodo($gettone, ['titolo' => 'B']);
    $c = nuovoTodo($gettone, ['titolo' => 'C']);
    $latte = nuovoTodo($gettone, ['titolo' => 'Latte', 'lista_id' => $spesa]);

    $cDopoA = alFinto('PATCH', "/v1/board/todo/elementi/{$c}", ['dopo_id' => $a], $gettone);
    $inTesta = alFinto('PATCH', "/v1/board/todo/elementi/{$b}", ['dopo_id' => null], $gettone);
    $inSpesa = alFinto('PATCH', "/v1/board/todo/elementi/{$a}", ['lista_id' => $spesa, 'dopo_id' => null], $gettone);

    expect($cDopoA->status())->toBe(200)
        ->and(titoliDelTodo($gettone, "lista_id={$personale}"))->toBe(['B', 'C'])
        ->and($inTesta->json('data.posizione'))->toBeLessThan($cDopoA->json('data.posizione'))
        ->and($inSpesa->json('data.lista_id'))->toBe($spesa)
        ->and(titoliDelTodo($gettone, "lista_id={$spesa}"))->toBe(['A', 'Latte']);
});

test('un dopo_id che è il to-do stesso, che non sta nella lista di arrivo o che non c\'è è 422 su #/dopo_id, mai 404 (TD.2)', function () {
    [, $gettoni] = studioColTodo();
    $gettone = $gettoni['anna'];
    $spesa = alFinto('POST', '/v1/board/todo/liste', ['nome' => 'Spesa'], $gettone)->json('data.id');
    $a = nuovoTodo($gettone, ['titolo' => 'A']);
    $latte = nuovoTodo($gettone, ['titolo' => 'Latte', 'lista_id' => $spesa]);
    $bruno = nuovoTodo($gettoni['bruno'], ['titolo' => 'Di Bruno']);

    foreach ([$a, $latte, $bruno, str_repeat('0', 26)] as $ancora) {
        $risposta = alFinto('PATCH', "/v1/board/todo/elementi/{$a}", ['dopo_id' => $ancora], $gettone);

        expect($risposta->status())->toBe(422)
            ->and($risposta->json('errors.0.pointer'))->toBe('#/dopo_id');
    }
});

test('con la lista senza spazio i vicini si rinumerano di un passo, e il loro ETag non cambia (TD.3)', function () {
    [, $gettoni] = studioColTodo();
    $gettone = $gettoni['anna'];
    $a = nuovoTodo($gettone, ['titolo' => 'A']);
    $b = nuovoTodo($gettone, ['titolo' => 'B']);
    $posto = fn (string $id) => alFinto('GET', "/v1/board/todo/elementi/{$id}", null, $gettone);

    // B e A in testa a turno: lo spazio sopra la prima si dimezza (1024 → 512 → … → 1) e dopo dieci mosse A sta a 1 e B a 2.
    for ($passo = 0; $passo < 10; $passo++) {
        alFinto('PATCH', '/v1/board/todo/elementi/'.($passo % 2 === 0 ? $b : $a), ['dopo_id' => null], $gettone);
    }

    $prima = $posto($a);
    $rinumera = alFinto('PATCH', "/v1/board/todo/elementi/{$b}", ['dopo_id' => null], $gettone);
    $dopo = $posto($a);

    expect($prima->json('data.posizione'))->toBe(1)
        ->and($rinumera->status())->toBe(200)
        ->and([$rinumera->json('data.posizione'), $dopo->json('data.posizione')])->toBe([512, 1024])
        ->and($dopo->header('ETag'))->toBe($prima->header('ETag'))
        ->and($dopo->json('data.aggiornato_il'))->toBe($prima->json('data.aggiornato_il'))
        ->and(titoliDelTodo($gettone, 'vista=tutti'))->toBe(['B', 'A']);
});

test('completare imposta uno stato e non lo inverte; riaprire lo toglie dai fatti e su un aperto non cambia niente (TF.1)', function () {
    $this->freezeTime();
    [, $gettoni] = studioColTodo();
    $gettone = $gettoni['anna'];
    $id = nuovoTodo($gettone, ['titolo' => 'Da fare']);
    $aperto = alFinto('GET', "/v1/board/todo/elementi/{$id}", null, $gettone);

    $riapertoSuAperto = alFinto('DELETE', "/v1/board/todo/elementi/{$id}/completamento", null, $gettone);
    $this->travel(5)->minutes();
    $fatto = alFinto('POST', "/v1/board/todo/elementi/{$id}/completamento", null, $gettone);
    $this->travel(5)->minutes();
    $ancora = alFinto('POST', "/v1/board/todo/elementi/{$id}/completamento", null, $gettone);
    $riaperto = alFinto('DELETE', "/v1/board/todo/elementi/{$id}/completamento", null, $gettone);

    expect($riapertoSuAperto->status())->toBe(200)
        ->and($riapertoSuAperto->header('ETag'))->toBe($aperto->header('ETag'))
        ->and($fatto->status())->toBe(200)
        ->and($fatto->json('data.fatto_il'))->not->toBeNull()
        ->and($ancora->json('data.fatto_il'))->toBe($fatto->json('data.fatto_il'))
        ->and($ancora->header('ETag'))->toBe($fatto->header('ETag'))
        ->and($riaperto->json('data.fatto_il'))->toBeNull()
        ->and(titoliDelTodo($gettone, 'vista=fatti'))->toBe([])
        ->and(titoliDelTodo($gettone, 'vista=tutti'))->toBe(['Da fare']);
});

test('i fatti stanno dal più recente, e un to-do eliminato non si legge più: 204, poi 404 (TF.2)', function () {
    $this->freezeTime();
    [, $gettoni] = studioColTodo();
    $gettone = $gettoni['anna'];
    $uno = nuovoTodo($gettone, ['titolo' => 'uno']);
    $due = nuovoTodo($gettone, ['titolo' => 'due']);
    $tre = nuovoTodo($gettone, ['titolo' => 'tre']);

    foreach ([$uno, $due, $tre] as $id) {
        alFinto('POST', "/v1/board/todo/elementi/{$id}/completamento", null, $gettone);
        $this->travel(1)->minutes();
    }

    $elimina = alFinto('DELETE', "/v1/board/todo/elementi/{$due}", null, $gettone);
    $seconda = alFinto('DELETE', "/v1/board/todo/elementi/{$due}", null, $gettone);

    expect($elimina->status())->toBe(204)
        ->and($seconda->status())->toBe(404)
        ->and(alFinto('GET', "/v1/board/todo/elementi/{$due}", null, $gettone)->status())->toBe(404)
        ->and(titoliDelTodo($gettone, 'vista=fatti'))->toBe(['tre', 'uno']);
});

test('il riepilogo conta in ritardo, di oggi, settimana, aperti e fatti, e per ogni lista nell\'ordine delle liste (TR.1)', function () {
    $this->freezeTime();
    [, $gettoni] = studioColTodo();
    $gettone = $gettoni['anna'];
    $oggi = now()->setTimezone('Europe/Rome')->format('Y-m-d');
    $giorno = fn (int $in) => now()->setTimezone('Europe/Rome')->addDays($in)->format('Y-m-d');
    $spesa = alFinto('POST', '/v1/board/todo/liste', ['nome' => 'Spesa'], $gettone)->json('data.id');

    nuovoTodo($gettone, ['titolo' => 'ieri', 'giorno' => $giorno(-3)]);
    nuovoTodo($gettone, ['titolo' => 'oggi', 'giorno' => $oggi]);
    nuovoTodo($gettone, ['titolo' => 'domani', 'giorno' => $giorno(2), 'lista_id' => $spesa]);
    nuovoTodo($gettone, ['titolo' => 'senza giorno', 'lista_id' => $spesa]);
    $fatto = nuovoTodo($gettone, ['titolo' => 'fatto', 'giorno' => $oggi]);
    alFinto('POST', "/v1/board/todo/elementi/{$fatto}/completamento", null, $gettone);
    $personale = alFinto('GET', '/v1/board/todo/liste', null, $gettone)->json('data.0.id');

    $riepilogo = alFinto('GET', '/v1/board/todo/riepilogo', null, $gettone);

    expect($riepilogo->status())->toBe(200)
        ->and($riepilogo->json('data.oggi'))->toBe($oggi)
        ->and($riepilogo->json('data'))->toMatchArray(['in_ritardo' => 1, 'di_oggi' => 1, 'settimana' => 1, 'aperti' => 4, 'fatti' => 1])
        ->and($riepilogo->json('data.liste'))->toBe([
            ['lista_id' => $personale, 'aperti' => 2, 'fatti' => 1],
            ['lista_id' => $spesa, 'aperti' => 2, 'fatti' => 0],
        ])
        ->and(alFinto('GET', '/v1/board/todo/riepilogo', null, $gettoni['bruno'])->json('data'))->toMatchArray(['aperti' => 0, 'fatti' => 0]);
});

test('i tag: per usi decrescenti e poi per nome, senza distinguere maiuscole e accenti, con la grafia più recente; un parametro è 422 (TG.1)', function () {
    $this->freezeTime();
    [, $gettoni] = studioColTodo();
    $gettone = $gettoni['anna'];

    nuovoTodo($gettone, ['titolo' => '1', 'tag' => ['Città', 'lavoro']]);
    $this->travel(1)->minutes();
    nuovoTodo($gettone, ['titolo' => '2', 'tag' => ['citta', 'Casa', 'casa']]);
    nuovoTodo($gettoni['bruno'], ['titolo' => 'altrui', 'tag' => ['altrui']]);

    $tag = alFinto('GET', '/v1/board/todo/tag', null, $gettone);
    $conParametro = alFinto('GET', '/v1/board/todo/tag?limite=5', null, $gettone);

    expect($tag->status())->toBe(200)
        ->and($tag->json('data'))->toBe([['nome' => 'citta', 'usi' => 2], ['nome' => 'Casa', 'usi' => 1], ['nome' => 'lavoro', 'usi' => 1]])
        ->and($conParametro->status())->toBe(422)
        ->and($conParametro->json('errors.0.regola'))->toBe('campo_estraneo');
});

test('i tag: Łódź e lodz, Øl e ol sono tag diversi, come nel backoffice (SomiglianzaDiNomi::piega toglie i segni, non traslittera) (TG.2)', function () {
    [, $gettoni] = studioColTodo();
    $gettone = $gettoni['anna'];

    nuovoTodo($gettone, ['titolo' => '1', 'tag' => ['Łódź', 'Øl']]);
    nuovoTodo($gettone, ['titolo' => '2', 'tag' => ['lodz', 'ol']]);

    $usi = collect(alFinto('GET', '/v1/board/todo/tag', null, $gettone)->json('data'))->pluck('usi', 'nome')->all();

    // deve fallire se: il finto unisce Łódź a lodz e Øl a ol
    expect($usi)->toEqual(['lodz' => 1, 'Łódź' => 1, 'ol' => 1, 'Øl' => 1]);
});

test('sotto tempo congelato ogni scrittura alza l\'ETag di un millisecondo almeno: modifica e completamento non lasciano il vecchio (TT.5, AP6)', function () {
    $this->freezeTime();
    [, $gettoni] = studioColTodo();
    $gettone = $gettoni['anna'];
    $id = nuovoTodo($gettone, ['titolo' => 'Uno']);
    $percorso = "/v1/board/todo/elementi/{$id}";
    $primo = alFinto('GET', $percorso, null, $gettone)->header('ETag');

    $modificato = alFinto('PATCH', $percorso, ['titolo' => 'Due'], $gettone, intestazioni: ['If-Match' => $primo]);
    $fatto = alFinto('POST', "{$percorso}/completamento", null, $gettone);

    // deve fallire se: un secondo PATCH con l'ETag letto prima passa ancora (nel backoffice è 412)
    expect($modificato->status())->toBe(200)
        ->and($modificato->header('ETag'))->not->toBe($primo)
        ->and($fatto->header('ETag'))->not->toBe($modificato->header('ETag'))
        ->and(alFinto('PATCH', $percorso, ['titolo' => 'Tre'], $gettone, intestazioni: ['If-Match' => $primo])->status())->toBe(412);
});

test('l\'ETag di un to-do è forte e uguale a ogni lettura; If-None-Match lo riconosce (304); If-Match vecchio è 412 prima del corpo (TT.1)', function () {
    [, $gettoni] = studioColTodo();
    $gettone = $gettoni['anna'];
    $id = nuovoTodo($gettone, ['titolo' => 'Uno']);
    $percorso = "/v1/board/todo/elementi/{$id}";
    $letta = alFinto('GET', $percorso, null, $gettone);
    $etag = $letta->header('ETag');

    $stessa = alFinto('GET', $percorso, null, $gettone);
    $trescentoquattro = alFinto('GET', $percorso, null, $gettone, null, ['If-None-Match' => $etag]);
    $giusta = alFinto('PATCH', $percorso, ['titolo' => 'Due'], $gettone, null, ['If-Match' => $etag]);
    $vecchia = alFinto('PATCH', $percorso, ['titolo' => 'Tre'], $gettone, null, ['If-Match' => $etag]);
    $primaDelCorpo = alFinto('PATCH', $percorso, ['titolo' => ''], $gettone, null, ['If-Match' => $etag]);

    expect($etag)->toMatch('/^"[0-9a-f]{32}"$/')
        ->and($stessa->header('ETag'))->toBe($etag)
        ->and($trescentoquattro->status())->toBe(304)
        ->and($trescentoquattro->body())->toBe('')
        ->and($giusta->status())->toBe(200)
        ->and($giusta->header('ETag'))->not->toBe($etag)
        ->and($vecchia->status())->toBe(412)
        ->and($vecchia->json('codice'))->toBe('precondizione_fallita')
        ->and($primaDelCorpo->status())->toBe(412)
        ->and(alFinto('GET', $percorso, null, $gettone)->json('data.titolo'))->toBe('Due');
});

test('un corpo senza campi, o che non cambia niente, non scrive: il primo è 422 su #, il secondo lascia l\'ETag (TT.2)', function () {
    $this->freezeTime();
    [, $gettoni] = studioColTodo();
    $gettone = $gettoni['anna'];
    $id = nuovoTodo($gettone, ['titolo' => 'Uno', 'colore' => 'sky']);
    $percorso = "/v1/board/todo/elementi/{$id}";
    $etag = alFinto('GET', $percorso, null, $gettone)->header('ETag');
    $this->travel(1)->minutes();

    $vuoto = alFinto('PATCH', $percorso, [], $gettone);
    $uguale = alFinto('PATCH', $percorso, ['titolo' => 'Uno', 'colore' => 'sky'], $gettone);

    expect($vuoto->status())->toBe(422)
        ->and($uguale->status())->toBe(200)
        ->and($uguale->header('ETag'))->toBe($etag);
});

test('il tetto di mille aperti per lista è 409 limite_raggiunto con il limite, e i fatti non contano (TL.3)', function () {
    $this->freezeTime();
    [, $gettoni] = studioColTodo();
    $gettone = $gettoni['anna'];
    $fatto = nuovoTodo($gettone, ['titolo' => 'fatto']);
    alFinto('POST', "/v1/board/todo/elementi/{$fatto}/completamento", null, $gettone);

    // Il freno dei to-do è di mille all'ora e quello del gettone di seicento al minuto: i mille aperti si creano in due ore.
    for ($i = 0; $i < 1000; $i++) {
        if ($i % 400 === 0) {
            $this->travel(61)->minutes();
        }

        $risposta = alFinto('POST', '/v1/board/todo/elementi', ['titolo' => "t{$i}"], $gettone);
        expect($risposta->status())->toBe(201, $risposta->body());
    }

    $oltre = alFinto('POST', '/v1/board/todo/elementi', ['titolo' => 'uno di troppo'], $gettone);
    $riaprire = alFinto('DELETE', "/v1/board/todo/elementi/{$fatto}/completamento", null, $gettone);

    expect($oltre->status())->toBe(409)
        ->and($oltre->json('codice'))->toBe('limite_raggiunto')
        ->and($oltre->json('limite'))->toBe(1000)
        ->and($riaprire->status())->toBe(409);
});
