<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// Le viste salvate di una board (backoffice sprint 25f, #1645-#1648): board.board.viste.elenca|crea|modifica|elimina nel finto.
// Qui ciò che un frontend vede dal suo lato; che le risposte e gli errori siano quelli del backoffice, e che il gate 2 le passi,
// lo prova il FintoTest del backoffice (G12).

/**
 * Un workspace con `pm` attiva, una board, Anna (proprietaria) e Bruno (membro): [finto, id della board, gettone di Anna, gettone di Bruno].
 *
 * @return array{BackofficeFinto, string, string, string}
 */
function conLeViste(): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD, 'Anna');
    $bruno = $finto->persona('bruno@example.com', PASSWORD, 'Bruno');
    $studio = $finto->workspace('Studio', $anna);
    $finto->membro($studio, $bruno, 'membro');
    $finto->attivaApp($studio, 'pm');
    $board = $finto->board($studio, 'Prodotto');
    $gettone = fn (string $email) => alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto($email)['gettone']['gettone'])->json('data.gettone');

    return [$finto, $board['id'], $gettone('anna@example.com'), $gettone('bruno@example.com')];
}

it('board.board.viste.crea salva la vista con i filtri nell\'ordine dei cinque campi, senza quelli vuoti, e la elenca per nome', function () {
    [, $board, $anna] = conLeViste();

    $crea = alFinto('POST', "/v1/board/board/{$board}/viste", ['nome' => '  Urgenti ', 'filtri' => ['testo' => 'rosso', 'etichette' => ['e1'], 'stato' => null, 'persone' => []]], $anna);

    expect($crea->status())->toBe(201)
        ->and($crea->header('Location'))->toBeNull()
        ->and(array_keys($crea->json('data')))->toBe(['id', 'board_id', 'nome', 'filtri', 'condivisa', 'creata_da', 'creata_il', 'aggiornata_il'])
        ->and($crea->json('data.nome'))->toBe('Urgenti')
        ->and($crea->json('data.filtri'))->toBe(['etichette' => ['e1'], 'testo' => 'rosso'])
        ->and($crea->json('data.condivisa'))->toBeFalse();

    alFinto('POST', "/v1/board/board/{$board}/viste", ['nome' => 'Altre'], $anna);
    $elenco = alFinto('GET', "/v1/board/board/{$board}/viste", null, $anna);

    expect(array_column($elenco->json('data'), 'nome'))->toBe(['Altre', 'Urgenti'])
        ->and($elenco->json('data.0.filtri'))->toBe([])
        ->and($elenco->json('successivo'))->toBeNull();
});

it('una vista personale la vede solo chi l\'ha creata e per ogni altro è 404; una condivisa la vedono e la cambiano tutti (G74)', function () {
    [, $board, $anna, $bruno] = conLeViste();
    $personale = alFinto('POST', "/v1/board/board/{$board}/viste", ['nome' => 'Mia'], $anna)->json('data.id');
    $condivisa = alFinto('POST', "/v1/board/board/{$board}/viste", ['nome' => 'Di tutti', 'condivisa' => true], $anna)->json('data.id');

    expect(array_column(alFinto('GET', "/v1/board/board/{$board}/viste", null, $bruno)->json('data'), 'id'))->toBe([$condivisa])
        ->and(alFinto('PATCH', "/v1/board/board/{$board}/viste/{$personale}", ['nome' => 'Rubata'], $bruno)->json('codice'))->toBe('non_trovato')
        ->and(alFinto('DELETE', "/v1/board/board/{$board}/viste/{$personale}", null, $bruno)->status())->toBe(404)
        ->and(alFinto('PATCH', "/v1/board/board/{$board}/viste/{$condivisa}", ['nome' => 'Cambiata da Bruno'], $bruno)->json('data.nome'))->toBe('Cambiata da Bruno');

    // Una board che non è del workspace, o che non c'è, è 404.
    expect(alFinto('GET', '/v1/board/board/01k6r2v8x4c7n3m9p5q1s6t2w8/viste', null, $anna)->status())->toBe(404);
});

it('board.board.viste.modifica: i filtri si sostituiscono per intero, un corpo vuoto è 422 sul corpo, uno che non cambia niente non scrive', function () {
    [, $board, $anna] = conLeViste();
    $vista = alFinto('POST', "/v1/board/board/{$board}/viste", ['nome' => 'Mia', 'filtri' => ['testo' => 'a', 'stato' => 'aperte']], $anna)->json('data');

    $uguale = alFinto('PATCH', "/v1/board/board/{$board}/viste/{$vista['id']}", ['filtri' => ['stato' => 'aperte', 'testo' => 'a', 'persone' => null]], $anna);
    $nuovi = alFinto('PATCH', "/v1/board/board/{$board}/viste/{$vista['id']}", ['filtri' => ['scadenza' => 'oggi']], $anna);
    $vuoto = alFinto('PATCH', "/v1/board/board/{$board}/viste/{$vista['id']}", [], $anna);

    expect($uguale->status())->toBe(200)
        ->and($uguale->json('data.aggiornata_il'))->toBe($vista['aggiornata_il'])
        ->and($nuovi->json('data.filtri'))->toBe(['scadenza' => 'oggi'])
        ->and($vuoto->status())->toBe(422)
        ->and($vuoto->json('errors.0.pointer'))->toBe('#');
});

it('board.board.viste.crea: il corpo sbagliato è 422 sul campo, un campo estraneo nei filtri è campo_estraneo, il tetto è 409 con limite e ambito', function () {
    [, $board, $anna] = conLeViste();

    $senzaNome = alFinto('POST', "/v1/board/board/{$board}/viste", [], $anna);
    $estraneo = alFinto('POST', "/v1/board/board/{$board}/viste", ['nome' => 'X', 'filtri' => ['colore' => 'rosso']], $anna);

    expect($senzaNome->status())->toBe(422)->and($senzaNome->json('errors.0.pointer'))->toBe('#/nome')
        ->and($estraneo->status())->toBe(422)->and($estraneo->json('errors.0.pointer'))->toBe('#/filtri/colore')->and($estraneo->json('errors.0.regola'))->toBe('campo_estraneo');

    foreach (range(1, 25) as $n) {
        expect(alFinto('POST', "/v1/board/board/{$board}/viste", ['nome' => "Vista {$n}"], $anna)->status())->toBe(201);
    }

    $piena = alFinto('POST', "/v1/board/board/{$board}/viste", ['nome' => 'Una di troppo'], $anna);

    expect($piena->status())->toBe(409)->and($piena->json('codice'))->toBe('limite_raggiunto')
        ->and($piena->json('limite'))->toBe(25)->and($piena->json('ambito'))->toBe('personale')
        ->and(alFinto('POST', "/v1/board/board/{$board}/viste", ['nome' => 'Condivisa', 'condivisa' => true], $anna)->status())->toBe(201);
});

it('board.board.viste.elimina toglie la vista: 204, poi 404 su ogni metodo', function () {
    [, $board, $anna] = conLeViste();
    $id = alFinto('POST', "/v1/board/board/{$board}/viste", ['nome' => 'Mia'], $anna)->json('data.id');

    expect(alFinto('DELETE', "/v1/board/board/{$board}/viste/{$id}", null, $anna)->status())->toBe(204)
        ->and(alFinto('DELETE', "/v1/board/board/{$board}/viste/{$id}", null, $anna)->status())->toBe(404)
        ->and(alFinto('PATCH', "/v1/board/board/{$board}/viste/{$id}", ['nome' => 'Y'], $anna)->status())->toBe(404)
        ->and(alFinto('GET', "/v1/board/board/{$board}/viste", null, $anna)->json('data'))->toBe([]);
});

it('le viste vogliono un workspace con pm: 403 app_non_attiva con l\'app spenta, gettone_senza_workspace con quello dell\'accesso', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD, 'Anna');
    $studio = $finto->workspace('Studio', $anna);
    $board = $finto->board($studio, 'Prodotto')['id'];
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];
    $conWorkspace = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso)->json('data.gettone');

    expect(alFinto('GET', "/v1/board/board/{$board}/viste", null, $conWorkspace)->json('codice'))->toBe('app_non_attiva')
        ->and(alFinto('GET', "/v1/board/board/{$board}/viste", null, $accesso)->json('codice'))->toBe('gettone_senza_workspace');
});
