<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #3299 (zr-board #3289): il padre di una scheda nel finto, padre_id, figli e discendenti. Qui ciò che un frontend vede dal suo lato; che le risposte siano
// quelle del backoffice lo prova il FintoTest del backoffice.

/**
 * Un workspace con `pm` attiva, una board e il gettone di Anna.
 *
 * @return array{BackofficeFinto, array<string, mixed>, array<string, mixed>, string}
 */
function conLaBoardDeiPadri(): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio', $anna);
    $finto->attivaApp($studio, 'pm');
    $board = $finto->board($studio, 'Piano', $finto->cartella($studio, 'Prodotto'));
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');

    return [$finto, $studio, $board, $gettone];
}

it('i padri: padre_id, figli e discendenti in ogni scheda; i conti non contano le archiviate; fatte sono le completate; i discendenti scendono a ogni livello', function () {
    [$finto, $studio, $board, $gettone] = conLaBoardDeiPadri();
    $radice = $finto->scheda($studio, 'Radice', $board['id']);
    $a = $finto->scheda($studio, 'A', $board['id'], padre: $radice['id']);
    $finto->scheda($studio, 'B', $board['id'], padre: $radice['id']);
    $a1 = $finto->scheda($studio, 'A1', $board['id'], padre: $a['id']);
    $libera = $finto->scheda($studio, 'Libera', $board['id']);

    $leggi = fn (string $id) => alFinto('GET', "/v1/board/schede/{$id}", null, $gettone)->json('data');

    expect($leggi($radice['id']))->toMatchArray(['padre_id' => null, 'figli' => ['totale' => 2, 'fatte' => 0], 'discendenti' => ['totale' => 3, 'fatte' => 0]])
        ->and($leggi($a['id']))->toMatchArray(['padre_id' => $radice['id'], 'figli' => ['totale' => 1, 'fatte' => 0], 'discendenti' => ['totale' => 1, 'fatte' => 0]])
        ->and($leggi($libera['id']))->toMatchArray(['padre_id' => null, 'figli' => ['totale' => 0, 'fatte' => 0], 'discendenti' => ['totale' => 0, 'fatte' => 0]]);

    expect(alFinto('POST', "/v1/board/schede/{$a1['id']}/completamento", null, $gettone)->status())->toBe(200);
    expect($leggi($radice['id'])['discendenti'])->toBe(['totale' => 3, 'fatte' => 1])->and($leggi($radice['id'])['figli'])->toBe(['totale' => 2, 'fatte' => 0]);
});

it('scheda() rifiuta un padre che non è una scheda non archiviata di quella board', function () {
    [$finto, $studio, $board] = conLaBoardDeiPadri();
    $padre = $finto->scheda($studio, 'Padre', $board['id']);

    expect(fn () => $finto->scheda($studio, 'Figlio', 'altra-board', padre: $padre['id']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finto->scheda($studio, 'Figlio', $board['id'], padre: '01k6w6a2c4e6g8j0m2p4r6t8v1'))->toThrow(InvalidArgumentException::class);
});
