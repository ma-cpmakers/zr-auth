<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #2816 (C4): i ruoli di una board (board.board.ruoli.*) e le epiche (epica_id, figlie, board.schede.figlie.elenca) nel finto. Qui ciò che un
// frontend vede dal suo lato; che le risposte e gli errori siano quelli del backoffice lo prova il FintoTest del backoffice.

/**
 * Un workspace con `pm` attiva, una board, Bruno membro, e il gettone di Anna.
 *
 * @return array{BackofficeFinto, array<string, mixed>, array<string, mixed>, array<string, mixed>, string}
 */
function conLaBoardDelleEpiche(): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $bruno = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $studio = $finto->workspace('Studio', $anna);
    $finto->membro($studio, $bruno, 'membro');
    $finto->attivaApp($studio, 'pm');
    $board = $finto->board($studio, 'Piano', $finto->cartella($studio, 'Prodotto'));
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');

    return [$finto, $studio, $board, $bruno, $gettone];
}

it('i ruoli della board: nessuno alla nascita, poi {id, nome}, null li toglie, [] e lo stesso valore non cambiano niente', function () {
    [, , $board, $bruno, $gettone] = conLaBoardDelleEpiche();
    $ruoli = "/v1/board/board/{$board['id']}/ruoli";

    expect(alFinto('GET', $ruoli, null, $gettone)->json('data'))->toBe(['product_owner' => null, 'scrum_master' => null, 'modificabile' => true]);

    $scritti = alFinto('PATCH', $ruoli, ['product_owner_id' => $bruno['id']], $gettone);

    expect($scritti->status())->toBe(200)->and($scritti->json('data.product_owner'))->toBe(['id' => $bruno['id'], 'nome' => 'Bruno'])->and($scritti->json('data.scrum_master'))->toBeNull();
    expect(alFinto('PATCH', $ruoli, [], $gettone)->json('data.product_owner.id'))->toBe($bruno['id'])
        ->and(alFinto('PATCH', $ruoli, ['product_owner_id' => $bruno['id']], $gettone)->status())->toBe(200)
        ->and(alFinto('PATCH', $ruoli, ['product_owner_id' => null], $gettone)->json('data.product_owner'))->toBeNull();
});

it('i ruoli della board: una persona che non è del workspace, un campo estraneo e una board che non c\'è sono errori del backoffice', function () {
    [, , $board, , $gettone] = conLaBoardDelleEpiche();
    $ruoli = "/v1/board/board/{$board['id']}/ruoli";

    foreach ([['product_owner_id' => '01k6w6a2c4e6g8j0m2p4r6t8v1', '#/product_owner_id'], ['scrum_master_id' => 'bruno', '#/scrum_master_id'], ['nome' => 'Altro', '#/nome']] as $caso) {
        $risposta = alFinto('PATCH', $ruoli, [array_key_first($caso) => $caso[array_key_first($caso)]], $gettone);

        expect($risposta->status())->toBe(422)->and($risposta->json('codice'))->toBe('dati_non_validi')->and($risposta->json('errors.0.pointer'))->toBe($caso[0]);
    }

    expect(alFinto('GET', '/v1/board/board/01k6w6a2c4e6g8j0m2p4r6t8v1/ruoli', null, $gettone)->status())->toBe(404);
});

it('le epiche: epica_id e figlie in ogni scheda; il conto non conta le archiviate; fatte sono le completate; punti solo nell\'epica; figlie.elenca le dà', function () {
    [$finto, $studio, $board, , $gettone] = conLaBoardDelleEpiche();
    $epica = $finto->scheda($studio, 'Epica', $board['id'], tipo: 'epica');
    $uno = $finto->scheda($studio, 'Uno', $board['id'], tipo: 'storia', punti: 5, epica: $epica['id']);
    $finto->scheda($studio, 'Due', $board['id'], tipo: 'bug', punti: 3, epica: $epica['id']);
    $fuori = $finto->scheda($studio, 'Fuori', $board['id'], tipo: 'storia', punti: 8);

    expect(alFinto('GET', "/v1/board/schede/{$epica['id']}", null, $gettone)->json('data.figlie'))->toBe(['totale' => 2, 'fatte' => 0, 'punti' => 8]);
    expect(alFinto('POST', "/v1/board/schede/{$uno['id']}/completamento", null, $gettone)->status())->toBe(200);

    $letta = alFinto('GET', "/v1/board/schede/{$epica['id']}", null, $gettone)->json('data');
    $figlia = alFinto('GET', "/v1/board/schede/{$uno['id']}", null, $gettone)->json('data');

    expect($letta['figlie'])->toBe(['totale' => 2, 'fatte' => 1, 'punti' => 8])->and($letta['epica_id'])->toBeNull()
        ->and($figlia['epica_id'])->toBe($epica['id'])->and($figlia['figlie'])->toBe(['totale' => 0, 'fatte' => 0])
        ->and(alFinto('GET', "/v1/board/schede/{$fuori['id']}", null, $gettone)->json('data.figlie'))->toBe(['totale' => 0, 'fatte' => 0]);

    $elenco = alFinto('GET', "/v1/board/schede/{$epica['id']}/figlie", null, $gettone);

    expect($elenco->status())->toBe(200)->and(array_column($elenco->json('data'), 'titolo'))->toBe(['Uno', 'Due'])->and($elenco->json('successivo'))->toBeNull()
        ->and($elenco->json('data.0'))->not->toHaveKey('descrizione')
        ->and(alFinto('GET', "/v1/board/schede/{$fuori['id']}/figlie", null, $gettone)->json())->toBe(['data' => [], 'successivo' => null])
        ->and(alFinto('GET', '/v1/board/schede/01k6w6a2c4e6g8j0m2p4r6t8v1/figlie', null, $gettone)->status())->toBe(404);
});

it('scheda() rifiuta un\'epica che non è un\'epica di quella board, e un\'epica con un\'epica', function () {
    [$finto, $studio, $board] = conLaBoardDelleEpiche();
    $storia = $finto->scheda($studio, 'Storia', $board['id'], tipo: 'storia');
    $epica = $finto->scheda($studio, 'Epica', $board['id'], tipo: 'epica');

    expect(fn () => $finto->scheda($studio, 'Figlia', $board['id'], epica: $storia['id']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finto->scheda($studio, 'Figlia', 'altra-board', epica: $epica['id']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finto->scheda($studio, 'Altra epica', $board['id'], tipo: 'epica', epica: $epica['id']))->toThrow(InvalidArgumentException::class);
});
