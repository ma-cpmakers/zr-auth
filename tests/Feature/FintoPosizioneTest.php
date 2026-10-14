<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #2097 (backoffice, PS.1-PS.4): la scheda del finto ha `posizione`, board.schede.spostamento.crea la cambia con lo stesso passo (1024)
// e la stessa rinumerazione dei vicini del backoffice, e ogni scheda che cambia posto è in `cambiate_dopo`. Che le risposte e gli
// errori siano quelli del backoffice lo prova il FintoTest del backoffice.

/**
 * Un workspace con `pm` attiva, tre schede di una board e il gettone di Anna: [finto, gettone, a, b, c, board].
 *
 * @return array{BackofficeFinto, string, array<string, mixed>, array<string, mixed>, array<string, mixed>, string}
 */
function conTreSchede(): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD, 'Anna');
    $studio = $finto->workspace('Studio', $anna);
    $finto->attivaApp($studio, 'pm');
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');

    return [$finto, $gettone, $finto->scheda($studio, 'A'), $finto->scheda($studio, 'B'), $finto->scheda($studio, 'C'), 'board-di-prova'];
}

test('ogni scheda ha la sua posizione, a passo 1024 e in coda, e la scheda intera ha la stessa (PS.1)', function () {
    [, $gettone, $a, $b, $c, $board] = conTreSchede();

    $elenco = alFinto('GET', "/v1/board/schede?board_id={$board}", null, $gettone)->json('data');
    $posti = collect($elenco)->mapWithKeys(fn (array $scheda) => [$scheda['id'] => $scheda['posizione']])->all();

    expect($posti)->toBe([$a['id'] => 1024, $b['id'] => 2048, $c['id'] => 3072])
        ->and(alFinto('GET', "/v1/board/schede/{$b['id']}", null, $gettone)->json('data.posizione'))->toBe(2048);
});

test('spostare in cima, in fondo o dopo una scheda mette la scheda a metà del posto, e la scheda spostata è in cambiate_dopo (PS.1, PS.2)', function () {
    [, $gettone, $a, $b, $c, $board] = conTreSchede();
    $versione = alFinto('GET', "/v1/board/schede?board_id={$board}", null, $gettone)->json('versione');

    $cima = alFinto('POST', "/v1/board/schede/{$c['id']}/spostamento", ['lista_id' => 'lista-di-prova', 'posizione' => 'cima'], $gettone)->json('data.posizione');
    $dopo = alFinto('POST', "/v1/board/schede/{$a['id']}/spostamento", ['lista_id' => 'lista-di-prova', 'dopo_scheda_id' => $b['id']], $gettone)->json('data.posizione');
    $fondo = alFinto('POST', "/v1/board/schede/{$b['id']}/spostamento", ['lista_id' => 'lista-di-prova', 'posizione' => 'fondo'], $gettone)->json('data.posizione');
    $cambiate = alFinto('GET', "/v1/board/schede?board_id={$board}&cambiate_dopo={$versione}", null, $gettone)->json('data');

    // C in cima: 1024 / 2 = 512. A dopo B (che ha ancora 2048, la coda): fra 2048 e niente = 3072. B in fondo: dopo A = 4096.
    expect([$cima, $dopo, $fondo])->toBe([512, 3072, 4096])
        ->and(collect($cambiate)->pluck('id')->sort()->values()->all())->toBe(collect([$a['id'], $b['id'], $c['id']])->sort()->values()->all());
});

test('con la lista senza spazio i vicini si rinumerano, e anche loro sono in cambiate_dopo: la board fusa è quella riletta da capo (PS.2, PS.3)', function () {
    [, $gettone, $a, $b, $c, $board] = conTreSchede();
    $letta = alFinto('GET', "/v1/board/schede?board_id={$board}", null, $gettone)->json();
    $fusa = collect($letta['data'])->keyBy('id')->all();
    $versione = $letta['versione'];
    $rinumerata = false;

    // B e C in cima a turno: ogni volta lo spazio sopra la prima si dimezza, finché non ce n'è più.
    for ($passo = 0; $passo < 14; $passo++) {
        $chi = $passo % 2 === 0 ? $c : $b;
        alFinto('POST', "/v1/board/schede/{$chi['id']}/spostamento", ['lista_id' => 'lista-di-prova', 'posizione' => 'cima'], $gettone);
        $cambiate = alFinto('GET', "/v1/board/schede?board_id={$board}&cambiate_dopo={$versione}", null, $gettone)->json();
        $rinumerata = $rinumerata || in_array($a['id'], array_column($cambiate['data'], 'id'), true);

        foreach ($cambiate['data'] as $scheda) {
            $fusa[$scheda['id']] = $scheda;
        }

        $versione = $cambiate['versione'];
    }

    $daCapo = collect(alFinto('GET', "/v1/board/schede?board_id={$board}", null, $gettone)->json('data'))->keyBy('id')->all();
    $ordine = fn (array $schede) => collect($schede)->sortBy('posizione')->pluck('id')->values()->all();

    expect($rinumerata)->toBeTrue()
        ->and($ordine($fusa))->toBe($ordine($daCapo))
        ->and(collect($fusa)->pluck('posizione', 'id')->all())->toBe(collect($daCapo)->pluck('posizione', 'id')->all())
        ->and(collect($daCapo)->pluck('posizione')->unique())->toHaveCount(3);
});

test('lo spostamento rifiuta una lista che non è del finto, un corpo senza posto o con due, e una scheda archiviata (PS.4)', function () {
    [$finto, $gettone, $a, $b] = conTreSchede();

    expect(alFinto('POST', "/v1/board/schede/{$a['id']}/spostamento", ['lista_id' => 'un-altra', 'posizione' => 'fondo'], $gettone)->status())->toBe(422);
    expect(alFinto('POST', "/v1/board/schede/{$a['id']}/spostamento", ['lista_id' => 'lista-di-prova'], $gettone)->status())->toBe(422);
    expect(alFinto('POST', "/v1/board/schede/{$a['id']}/spostamento", ['lista_id' => 'lista-di-prova', 'posizione' => 'fondo', 'dopo_scheda_id' => $b['id']], $gettone)->status())->toBe(422);
    expect(alFinto('POST', "/v1/board/schede/{$a['id']}/spostamento", ['lista_id' => 'lista-di-prova', 'dopo_scheda_id' => $a['id']], $gettone)->json('codice'))->toBe('posizione_cambiata');

    $finto->segnaScheda($b, 'archiviata');
    expect(alFinto('POST', "/v1/board/schede/{$b['id']}/spostamento", ['lista_id' => 'lista-di-prova', 'posizione' => 'fondo'], $gettone)->json('codice'))->toBe('scheda_archiviata');
});
