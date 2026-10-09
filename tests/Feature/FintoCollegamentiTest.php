<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #1457 T3 (T3.9, G28): i collegamenti «aspetta» nel finto. Qui ciò che un frontend vede dal suo lato (i tetti, il giro, la
// catena, il doppione, i testi e i membri estesi del 409); che le risposte e gli errori siano quelli del backoffice, e che il
// gate 2 le passi, lo prova il FintoTest del backoffice (G12).

/**
 * Un workspace con `pm` attiva e `$quante` schede di una stessa board: [finto, schede, gettone del workspace].
 *
 * @return array{BackofficeFinto, list<array<string, mixed>>, string}
 */
function conLeSchede(int $quante, bool $pm = true): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio', $anna);

    if ($pm) {
        $finto->attivaApp($studio, 'pm');
    }

    $schede = array_map(fn (int $n) => $finto->scheda($studio, "Scheda {$n}"), range(1, $quante));
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone.gettone');

    return [$finto, $schede, $gettone];
}

function aspettaNelFinto(string $gettone, array $da, array $a)
{
    return alFinto('POST', "/v1/board/schede/{$da['id']}/collegamenti", ['scheda_aspettata_id' => $a['id']], $gettone);
}

it('una scheda aspetta un\'altra: 201, la Location e la forma del backoffice; il doppione è 409 e l\'elenco la dà nei due versi', function () {
    [, [$a, $b], $gettone] = conLeSchede(2);

    $prima = aspettaNelFinto($gettone, $a, $b)->assertCreated();

    expect($prima->header('Location'))->toBe("/v1/board/schede/{$a['id']}/collegamenti/".$prima->json('data.id'))
        ->and(array_keys($prima->json('data')))->toBe(['id', 'scheda_id', 'scheda_aspettata_id', 'creato_il', 'scheda', 'scheda_aspettata'])
        ->and($prima->json('data.scheda'))->toMatchArray(['id' => $a['id'], 'numero' => 1, 'titolo' => 'Scheda 1', 'completata_il' => null, 'archiviata_il' => null]);
    expect(aspettaNelFinto($gettone, $a, $b)->json('codice'))->toBe('collegamento_esistente');
    expect(alFinto('GET', "/v1/board/schede/{$a['id']}/collegamenti", null, $gettone)->json('data.0.scheda_aspettata_id'))->toBe($b['id'])
        ->and(alFinto('GET', "/v1/board/schede/{$b['id']}/collegamenti", null, $gettone)->json('data'))->toBe([])
        ->and(alFinto('GET', "/v1/board/schede/{$b['id']}/collegamenti?direzione=in_entrata", null, $gettone)->json('data.0.scheda_id'))->toBe($a['id']);
});

it('l\'undicesima attesa in uscita è 409 limite_raggiunto con limite 10 e direzione in_uscita (T3.9, deve fallire se è 201)', function () {
    [, $schede, $gettone] = conLeSchede(13);
    $prima = array_shift($schede);

    foreach (array_slice($schede, 0, 10) as $altra) {
        aspettaNelFinto($gettone, $prima, $altra)->assertCreated();
    }

    $fermata = aspettaNelFinto($gettone, $prima, $schede[10])->assertStatus(409);
    expect($fermata->json('codice'))->toBe('limite_raggiunto')->and($fermata->json('limite'))->toBe(10)->and($fermata->json('direzione'))->toBe('in_uscita');
});

it('il giro e la catena: sé stessa e il ritorno sono 422 collegamento_circolare, la ventunesima catena 422 catena_troppo_lunga (T3.9)', function () {
    [, $schede, $gettone] = conLeSchede(23);

    expect(aspettaNelFinto($gettone, $schede[0], $schede[0])->json('codice'))->toBe('collegamento_circolare');

    foreach (range(0, 19) as $i) {
        aspettaNelFinto($gettone, $schede[$i], $schede[$i + 1])->assertCreated();
    }

    expect(aspettaNelFinto($gettone, $schede[20], $schede[0])->json('codice'))->toBe('collegamento_circolare')
        ->and(aspettaNelFinto($gettone, $schede[20], $schede[21])->json('codice'))->toBe('catena_troppo_lunga');
});

it('la cinquantunesima attesa in entrata è 409 limite_raggiunto con limite 50 e direzione in_entrata (T3.9)', function () {
    [, $schede, $gettone] = conLeSchede(52);
    $aspettata = array_shift($schede);

    foreach (array_slice($schede, 0, 50) as $altra) {
        aspettaNelFinto($gettone, $altra, $aspettata)->assertCreated();
    }

    $fermata = aspettaNelFinto($gettone, $schede[50], $aspettata)->assertStatus(409);
    expect($fermata->json('codice'))->toBe('limite_raggiunto')->and($fermata->json('limite'))->toBe(50)->and($fermata->json('direzione'))->toBe('in_entrata');
});

it('elimina toglie l\'attesa (204), e la stessa coppia si ricollega; una scheda archiviata è 409 (T3.9)', function () {
    [$finto, [$a, $b, $c], $gettone] = conLeSchede(3);
    $id = aspettaNelFinto($gettone, $a, $b)->json('data.id');

    alFinto('DELETE', "/v1/board/schede/{$a['id']}/collegamenti/{$id}", null, $gettone)->assertNoContent();
    expect(alFinto('DELETE', "/v1/board/schede/{$a['id']}/collegamenti/{$id}", null, $gettone)->status())->toBe(404)
        ->and(aspettaNelFinto($gettone, $a, $b)->status())->toBe(201);

    $finto->segnaScheda($c, 'archiviata');
    expect(aspettaNelFinto($gettone, $a, $c)->json('codice'))->toBe('scheda_archiviata');
});

it('con l\'app pm spenta è 403 app_non_attiva (T3.9)', function () {
    [, [$a, $b], $gettone] = conLeSchede(2, pm: false);

    expect(aspettaNelFinto($gettone, $a, $b)->json('codice'))->toBe('app_non_attiva');
});
