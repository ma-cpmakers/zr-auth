<?php

// #4189 (zr-squadra, decisione di zr-pm del 22/11): l'archiviazione di un to-do nel finto — `archiviato_il`, fuori da elenco e riepilogo, 409
// `todo_archiviato` per ogni altro metodo, ripristino col tetto. Che le risposte siano quelle del backoffice lo prova il FintoTest del backoffice.

it('archivia e ripristina un to-do: archiviato_il, fuori da elenco e riepilogo, mostra lo legge, ripetuto non cambia niente', function () {
    [, $gettoni] = studioColTodo();
    $id = nuovoTodo($gettoni['anna'], ['titolo' => 'Da archiviare']);
    nuovoTodo($gettoni['anna'], ['titolo' => 'Resta']);
    $prima = alFinto('GET', "/v1/board/todo/elementi/{$id}", null, $gettoni['anna']);

    expect($prima->json('data.archiviato_il'))->toBeNull();

    $primo = alFinto('POST', "/v1/board/todo/elementi/{$id}/archiviazione", null, $gettoni['anna']);
    $archiviatoIl = $primo->json('data.archiviato_il');

    expect($primo->status())->toBe(200, $primo->body())
        ->and($archiviatoIl)->not->toBeNull()
        ->and($primo->header('ETag'))->toBe($primo->json('data.etag'))
        ->and(array_keys($primo->json('data')))->toBe(array_keys($prima->json('data')));

    $secondo = alFinto('POST', "/v1/board/todo/elementi/{$id}/archiviazione", null, $gettoni['anna']);

    expect($secondo->status())->toBe(200)
        ->and($secondo->json('data.archiviato_il'))->toBe($archiviatoIl)
        ->and($secondo->json('data.etag'))->toBe($primo->json('data.etag'))
        ->and(titoliDelTodo($gettoni['anna'], 'vista=tutti'))->toBe(['Resta'])
        ->and(alFinto('GET', '/v1/board/todo/riepilogo', null, $gettoni['anna'])->json('data.aperti'))->toBe(1)
        ->and(alFinto('GET', "/v1/board/todo/elementi/{$id}", null, $gettoni['anna'])->json('data.archiviato_il'))->toBe($archiviatoIl);

    $ripristinato = alFinto('DELETE', "/v1/board/todo/elementi/{$id}/archiviazione", null, $gettoni['anna']);

    expect($ripristinato->status())->toBe(200)
        ->and($ripristinato->json('data.archiviato_il'))->toBeNull()
        ->and(titoliDelTodo($gettoni['anna'], 'vista=tutti'))->toEqualCanonicalizing(['Da archiviare', 'Resta']);
});

it('ogni altro metodo su un to-do archiviato è 409 todo_archiviato; uno altrui o che non c\'è è 404', function () {
    [, $gettoni] = studioColTodo();
    $id = nuovoTodo($gettoni['anna'], ['titolo' => 'Archiviato']);
    alFinto('POST', "/v1/board/todo/elementi/{$id}/archiviazione", null, $gettoni['anna']);

    foreach ([
        ['PATCH', "/v1/board/todo/elementi/{$id}", ['titolo' => 'Cambiato']],
        ['DELETE', "/v1/board/todo/elementi/{$id}", null],
        ['POST', "/v1/board/todo/elementi/{$id}/completamento", null],
        ['DELETE', "/v1/board/todo/elementi/{$id}/completamento", null],
    ] as [$metodo, $percorso, $corpo]) {
        $risposta = alFinto($metodo, $percorso, $corpo, $gettoni['anna']);

        expect($risposta->status())->toBe(409, "{$metodo} {$percorso}")
            ->and($risposta->json('codice'))->toBe('todo_archiviato');
    }

    foreach (['POST', 'DELETE'] as $metodo) {
        expect(alFinto($metodo, "/v1/board/todo/elementi/{$id}/archiviazione", null, $gettoni['bruno'])->status())->toBe(404)
            ->and(alFinto($metodo, '/v1/board/todo/elementi/01kzzzzzzzzzzzzzzzzzzzzzzz/archiviazione', null, $gettoni['anna'])->status())->toBe(404);
    }
});
