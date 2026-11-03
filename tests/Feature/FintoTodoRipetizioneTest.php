<?php

// #2610 #2922 (zr-board #2459, #2458): l'indirizzo e la ripetizione del to-do nel finto, e la prossima occorrenza che nasce al completamento. Che le risposte
// e gli errori siano quelli del backoffice lo prova il FintoTest del backoffice.

/** Un workspace con `pm` attiva e il gettone di Anna. */
function annaConIlTodoNelFinto(): string
{
    $finto = Zeiras\Auth\Testing\BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD, 'Anna');
    $studio = $finto->workspace('Studio', $anna);
    $finto->attivaApp($studio, 'pm');

    return alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');
}

it('indirizzo e ripetizione: nel 201, in mostra e in elenco, null se mancano; null e "" svuotano l\'indirizzo', function () {
    $gettone = annaConIlTodoNelFinto();

    $nato = alFinto('POST', '/v1/board/todo/elementi', ['titolo' => 'Pagare', 'giorno' => '2026-11-06', 'ripetizione' => 'lavorativa', 'indirizzo' => 'Via Roma 1'], $gettone);
    $senza = alFinto('POST', '/v1/board/todo/elementi', ['titolo' => 'Senza'], $gettone);

    expect($nato->status())->toBe(201)
        ->and($nato->json('data.ripetizione'))->toBe('lavorativa')
        ->and($nato->json('data.indirizzo'))->toBe('Via Roma 1')
        ->and($senza->json('data'))->toHaveKeys(['ripetizione', 'indirizzo'])
        ->and($senza->json('data.ripetizione'))->toBeNull()
        ->and($senza->json('data.indirizzo'))->toBeNull();

    $righe = array_column(alFinto('GET', '/v1/board/todo/elementi?vista=tutti', null, $gettone)->json('data'), null, 'titolo');
    expect($righe['Pagare']['indirizzo'])->toBe('Via Roma 1')->and($righe['Senza'])->toHaveKeys(['ripetizione', 'indirizzo']);

    foreach ([null, ''] as $vuoto) {
        alFinto('PATCH', '/v1/board/todo/elementi/'.$nato->json('data.id'), ['indirizzo' => 'Corso Italia 7'], $gettone);
        expect(alFinto('PATCH', '/v1/board/todo/elementi/'.$nato->json('data.id'), ['indirizzo' => $vuoto], $gettone)->json('data.indirizzo'))->toBeNull();
    }
});

it('un valore fuori elenco, la ripetizione senza giorno e il giorno tolto a un to-do che si ripete sono 422 sul loro pointer; un indirizzo di 301 caratteri è 422', function () {
    $gettone = annaConIlTodoNelFinto();
    $ripetuto = alFinto('POST', '/v1/board/todo/elementi', ['titolo' => 'Ripeti', 'giorno' => '2026-11-06', 'ripetizione' => 'annuale'], $gettone)->json('data.id');
    $senza = alFinto('POST', '/v1/board/todo/elementi', ['titolo' => 'Senza'], $gettone)->json('data.id');

    foreach ([
        ['POST', '/v1/board/todo/elementi', ['titolo' => 'x', 'giorno' => '2026-11-06', 'ripetizione' => 'rara'], '#/ripetizione'],
        ['POST', '/v1/board/todo/elementi', ['titolo' => 'x', 'ripetizione' => 'annuale'], '#/ripetizione'],
        ['POST', '/v1/board/todo/elementi', ['titolo' => 'x', 'indirizzo' => str_repeat('a', 301)], '#/indirizzo'],
        ['PATCH', "/v1/board/todo/elementi/{$senza}", ['ripetizione' => 'annuale'], '#/ripetizione'],
        ['PATCH', "/v1/board/todo/elementi/{$ripetuto}", ['giorno' => null], '#/giorno'],
    ] as [$metodo, $percorso, $corpo, $pointer]) {
        $errore = alFinto($metodo, $percorso, $corpo, $gettone);

        expect($errore->status())->toBe(422)->and($errore->json('errors.0.pointer'))->toBe($pointer);
    }

    expect(alFinto('PATCH', "/v1/board/todo/elementi/{$ripetuto}", ['giorno' => null, 'ripetizione' => null], $gettone)->status())->toBe(200);
});

it('la serie mensile tiene il giorno d\'origine: 31/1, 28/2, 31/3, 30/4; l\'annuale del 29/2 torna nel bisestile', function () {
    $gettone = annaConIlTodoNelFinto();

    foreach ([['mensile', '2027-01-31', ['2027-02-28', '2027-03-31', '2027-04-30']], ['annuale', '2028-02-29', ['2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29']]] as [$ripetizione, $partenza, $attese]) {
        $id = alFinto('POST', '/v1/board/todo/elementi', ['titolo' => "Serie {$ripetizione}", 'giorno' => $partenza, 'ripetizione' => $ripetizione], $gettone)->json('data.id');

        foreach ($attese as $atteso) {
            alFinto('POST', "/v1/board/todo/elementi/{$id}/completamento", null, $gettone);
            $riga = array_values(array_filter(alFinto('GET', '/v1/board/todo/elementi?vista=tutti', null, $gettone)->json('data'), fn (array $r) => $r['titolo'] === "Serie {$ripetizione}"))[0];
            $id = $riga['id'];

            expect($riga['giorno'])->toBe($atteso);
        }
    }
});

it('completare un to-do che si ripete fa nascere il prossimo: giorno spostato di un passo, stessi campi, promemoria; una volta sola anche riaprendolo', function (string $ripetizione, string $giorno, string $prossimo) {
    $gettone = annaConIlTodoNelFinto();
    $id = alFinto('POST', '/v1/board/todo/elementi', ['titolo' => 'Ripeti', 'giorno' => $giorno, 'ora' => '15:30', 'ripetizione' => $ripetizione, 'tag' => ['a'], 'note' => 'Le note', 'indirizzo' => 'Via Roma 1'], $gettone)->json('data.id');
    alFinto('POST', "/v1/board/todo/elementi/{$id}/promemoria", ['anticipo' => 10], $gettone);

    $chiuso = alFinto('POST', "/v1/board/todo/elementi/{$id}/completamento", null, $gettone);
    $aperti = alFinto('GET', '/v1/board/todo/elementi?vista=tutti', null, $gettone)->json('data');

    expect($chiuso->json('data.id'))->toBe($id)->and($chiuso->json('data.fatto_il'))->not->toBeNull()
        ->and($aperti)->toHaveCount(1)
        ->and($aperti[0]['id'])->not->toBe($id)
        ->and($aperti[0]['giorno'])->toBe($prossimo)
        ->and($aperti[0]['ora'])->toBe('15:30')
        ->and($aperti[0]['ripetizione'])->toBe($ripetizione)
        ->and($aperti[0]['indirizzo'])->toBe('Via Roma 1')
        ->and($aperti[0]['tag'])->toBe(['a'])
        ->and($aperti[0]['promemoria_n'])->toBe(1);

    alFinto('DELETE', "/v1/board/todo/elementi/{$id}/completamento", null, $gettone);
    alFinto('POST', "/v1/board/todo/elementi/{$id}/completamento", null, $gettone);

    expect(alFinto('GET', '/v1/board/todo/elementi?vista=tutti', null, $gettone)->json('data'))->toHaveCount(1);
})->with([
    'giornaliera' => ['giornaliera', '2026-11-03', '2026-11-04'],
    'lavorativa dal venerdì' => ['lavorativa', '2026-11-06', '2026-11-09'],
    'settimanale' => ['settimanale', '2026-11-03', '2026-11-10'],
    'mensile dal 31 gennaio' => ['mensile', '2027-01-31', '2027-02-28'],
    'annuale dal 29 febbraio' => ['annuale', '2028-02-29', '2029-02-28'],
]);
