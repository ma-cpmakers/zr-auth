<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #1942 (backoffice, T1, T2.2): board.schede.elenca con `per_lista` dà le prime N schede della board in una risposta sola, con la
// `versione` della lettura intera e `successivo` null; i parametri che non vanno insieme sono 422. Che le risposte e gli errori siano
// quelli del backoffice lo prova il FintoTest del backoffice.

/**
 * Un workspace con `pm` attiva, tre schede di una board e il gettone di Anna: [gettone, a, b, c, board].
 *
 * @return array{string, array<string, mixed>, array<string, mixed>, array<string, mixed>, string}
 */
function conTreSchedePerLista(): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD, 'Anna');
    $studio = $finto->workspace('Studio', $anna);
    $finto->attivaApp($studio, 'pm');
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');

    return [$gettone, $finto->scheda($studio, 'A'), $finto->scheda($studio, 'B'), $finto->scheda($studio, 'C'), 'board-di-prova'];
}

test('per_lista dà le prime N schede nell\'ordine, successivo null e la versione della lettura intera (T1.1, T1.3)', function () {
    [$gettone, $a, $b, , $board] = conTreSchedePerLista();

    $prime = alFinto('GET', "/v1/board/schede?board_id={$board}&per_lista=2", null, $gettone);
    $intera = alFinto('GET', "/v1/board/schede?board_id={$board}", null, $gettone);

    expect(array_column($prime->json('data'), 'id'))->toBe([$a['id'], $b['id']])
        ->and($prime->json('data'))->toBe(array_slice($intera->json('data'), 0, 2))
        ->and($prime->json('successivo'))->toBeNull()
        ->and($prime->json('versione'))->toBe($intera->json('versione'))
        ->and($prime->json('data.0'))->not->toHaveKey('descrizione');
});

test('per_lista fuori da 1-100 o non intero è 422 dati_non_validi, con un altro parametro campo_vietato (T1.4)', function () {
    [$gettone, , , , $board] = conTreSchedePerLista();

    foreach (['0', '101', 'abc', '2.5'] as $valore) {
        $risposta = alFinto('GET', "/v1/board/schede?board_id={$board}&per_lista={$valore}", null, $gettone);

        expect($risposta->status())->toBe(422)
            ->and($risposta->json('codice'))->toBe('dati_non_validi')
            ->and($risposta->json('errors.0.parameter'))->toBe('per_lista');
    }

    foreach (['&limite=10', '&cursore=x', '&archiviate=true', '&numero=1', '&assegnatario_id=abc', '&cambiate_dopo=1'] as $altro) {
        $errori = alFinto('GET', "/v1/board/schede?board_id={$board}&per_lista=5{$altro}", null, $gettone);

        expect($errori->status())->toBe(422)
            ->and(collect($errori->json('errors'))->contains(fn (array $errore) => $errore['parameter'] === 'per_lista' && $errore['regola'] === 'campo_vietato'))->toBeTrue($altro);
    }
});
