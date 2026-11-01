<?php

// #2790 (zr-board #2458 T2): i promemoria con anticipo di un to-do nel finto — la forma, `invio_il` dal giorno, dall'ora e dal fuso, il doppione, il
// to-do senza giorno, `promemoria_n`. Che le risposte e gli errori siano quelli del backoffice lo prova il FintoTest del backoffice.

/** Un workspace con `pm` attiva e un to-do di Anna con giorno e ora dati: [gettone di Anna, id del to-do]. */
function todoDaRicordareNelFinto(?string $giorno = '2026-11-10', ?string $ora = '09:30'): array
{
    $finto = Zeiras\Auth\Testing\BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD, 'Anna');
    $studio = $finto->workspace('Studio', $anna);
    $finto->attivaApp($studio, 'pm');
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');
    $todo = alFinto('POST', '/v1/board/todo/elementi', ['titolo' => 'Chiamare', ...($giorno === null ? [] : ['giorno' => $giorno]), ...($ora === null ? [] : ['ora' => $ora])], $gettone);
    expect($todo->status())->toBe(201);

    return [$gettone, $todo->json('data.id')];
}

it('aggiunge un promemoria: 201, la Location, {id, anticipo, invio_il} con l\'istante in UTC; un anticipo fuori da 0, 10, 60 e 1440 è 422', function () {
    [$gettone, $todo] = todoDaRicordareNelFinto();

    $nuovo = alFinto('POST', "/v1/board/todo/elementi/{$todo}/promemoria", ['anticipo' => 10], $gettone);

    expect($nuovo->status())->toBe(201)
        ->and($nuovo->header('Location'))->toBe("/v1/board/todo/elementi/{$todo}/promemoria/".$nuovo->json('data.id'))
        ->and(array_keys($nuovo->json('data')))->toBe(['id', 'anticipo', 'invio_il'])
        ->and($nuovo->json('data.invio_il'))->toBe('2026-11-10T08:20:00.000Z');

    foreach ([['anticipo' => 5], ['anticipo' => 'dieci'], []] as $corpo) {
        expect(alFinto('POST', "/v1/board/todo/elementi/{$todo}/promemoria", $corpo, $gettone)->json('errors.0.pointer'))->toBe('#/anticipo');
    }
});

it('un to-do senza giorno è 422 su #/anticipo; con il giorno e senza l\'ora vale le 09:00; lo stesso anticipo due volte è 409 promemoria_gia_presente', function () {
    [$gettone, $senza] = todoDaRicordareNelFinto(null, null);

    expect(alFinto('POST', "/v1/board/todo/elementi/{$senza}/promemoria", ['anticipo' => 10], $gettone)->json('errors.0.pointer'))->toBe('#/anticipo');

    [$gettone, $senzaOra] = todoDaRicordareNelFinto('2026-11-10', null);
    expect(alFinto('POST', "/v1/board/todo/elementi/{$senzaOra}/promemoria", ['anticipo' => 0], $gettone)->json('data.invio_il'))->toBe('2026-11-10T08:00:00.000Z');

    $doppio = alFinto('POST', "/v1/board/todo/elementi/{$senzaOra}/promemoria", ['anticipo' => 0], $gettone);
    expect($doppio->status())->toBe(409)->and($doppio->json('codice'))->toBe('promemoria_gia_presente');
});

it('elenca nell\'ordine di nascita, elimina toglie (204, poi 404) e lo stesso anticipo si rimette; promemoria_n conta i vivi nel to-do e nelle righe', function () {
    [$gettone, $todo] = todoDaRicordareNelFinto();
    $percorso = "/v1/board/todo/elementi/{$todo}/promemoria";
    $dieci = alFinto('POST', $percorso, ['anticipo' => 10], $gettone)->json('data.id');
    alFinto('POST', $percorso, ['anticipo' => 1440], $gettone);

    expect(alFinto('GET', "/v1/board/todo/elementi/{$todo}", null, $gettone)->json('data.promemoria_n'))->toBe(2)
        ->and(alFinto('GET', '/v1/board/todo/elementi?vista=tutti', null, $gettone)->json('data.0.promemoria_n'))->toBe(2)
        ->and(array_column(alFinto('GET', $percorso, null, $gettone)->json('data'), 'anticipo'))->toBe([10, 1440]);

    expect(alFinto('DELETE', "{$percorso}/{$dieci}", null, $gettone)->status())->toBe(204)
        ->and(alFinto('DELETE', "{$percorso}/{$dieci}", null, $gettone)->status())->toBe(404)
        ->and(alFinto('GET', "/v1/board/todo/elementi/{$todo}", null, $gettone)->json('data.promemoria_n'))->toBe(1)
        ->and(alFinto('POST', $percorso, ['anticipo' => 10], $gettone)->status())->toBe(201);
});
