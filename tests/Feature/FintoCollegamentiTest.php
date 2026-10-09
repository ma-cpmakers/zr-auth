<?php

use Illuminate\Http\Client\Response;
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
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');

    return [$finto, $schede, $gettone];
}

/** La richiesta di «da aspetta a»; con `$stato` ne controlla lo stato (la Response del client HTTP non ha gli assert di Laravel). */
function aspettaNelFinto(string $gettone, array $da, array $a, ?int $stato = null): Response
{
    $risposta = alFinto('POST', "/v1/board/schede/{$da['id']}/collegamenti", ['scheda_aspettata_id' => $a['id']], $gettone);

    if ($stato !== null) {
        expect($risposta->status())->toBe($stato);
    }

    return $risposta;
}

it('una scheda aspetta un\'altra: 201, la Location e la forma del backoffice; il doppione è 409 e l\'elenco la dà nei due versi', function () {
    [, [$a, $b], $gettone] = conLeSchede(2);

    $prima = aspettaNelFinto($gettone, $a, $b, 201);

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
        aspettaNelFinto($gettone, $prima, $altra, 201);
    }

    $fermata = aspettaNelFinto($gettone, $prima, $schede[10], 409);
    expect($fermata->json('codice'))->toBe('limite_raggiunto')->and($fermata->json('limite'))->toBe(10)->and($fermata->json('direzione'))->toBe('in_uscita');
});

it('il giro e la catena: sé stessa e il ritorno sono 422 collegamento_circolare, la ventunesima catena 422 catena_troppo_lunga (T3.9)', function () {
    [, $schede, $gettone] = conLeSchede(23);

    expect(aspettaNelFinto($gettone, $schede[0], $schede[0])->json('codice'))->toBe('collegamento_circolare');

    foreach (range(0, 19) as $i) {
        aspettaNelFinto($gettone, $schede[$i], $schede[$i + 1], 201);
    }

    expect(aspettaNelFinto($gettone, $schede[20], $schede[0])->json('codice'))->toBe('collegamento_circolare')
        ->and(aspettaNelFinto($gettone, $schede[20], $schede[21])->json('codice'))->toBe('catena_troppo_lunga');
});

it('la cinquantunesima attesa in entrata è 409 limite_raggiunto con limite 50 e direzione in_entrata (T3.9)', function () {
    [, $schede, $gettone] = conLeSchede(52);
    $aspettata = array_shift($schede);

    foreach (array_slice($schede, 0, 50) as $altra) {
        aspettaNelFinto($gettone, $altra, $aspettata, 201);
    }

    $fermata = aspettaNelFinto($gettone, $schede[50], $aspettata, 409);
    expect($fermata->json('codice'))->toBe('limite_raggiunto')->and($fermata->json('limite'))->toBe(50)->and($fermata->json('direzione'))->toBe('in_entrata');
});

it('elimina toglie l\'attesa (204), e la stessa coppia si ricollega; una scheda archiviata è 409 (T3.9)', function () {
    [$finto, [$a, $b, $c], $gettone] = conLeSchede(3);
    $id = aspettaNelFinto($gettone, $a, $b)->json('data.id');

    expect(alFinto('DELETE', "/v1/board/schede/{$a['id']}/collegamenti/{$id}", null, $gettone)->status())->toBe(204);
    expect(alFinto('DELETE', "/v1/board/schede/{$a['id']}/collegamenti/{$id}", null, $gettone)->status())->toBe(404)
        ->and(aspettaNelFinto($gettone, $a, $b)->status())->toBe(201);

    $finto->segnaScheda($c, 'archiviata');
    expect(aspettaNelFinto($gettone, $a, $c)->json('codice'))->toBe('scheda_archiviata');
});

it('con l\'app pm spenta è 403 app_non_attiva (T3.9)', function () {
    [, [$a, $b], $gettone] = conLeSchede(2, pm: false);

    expect(aspettaNelFinto($gettone, $a, $b)->json('codice'))->toBe('app_non_attiva');
});

// #1457 T4 (T4.6, G28): le attese aperte e le schede sbloccate nel finto. Che siano quelle del backoffice lo prova il FintoTest.

it('mostra dà attese_aperte, e una aspettata completata o archiviata esce dalle attese (T4.6)', function () {
    [$finto, [$a, $b, $c], $gettone] = conLeSchede(3);
    aspettaNelFinto($gettone, $a, $b, 201);
    aspettaNelFinto($gettone, $a, $c, 201);
    $mostra = fn () => alFinto('GET', "/v1/board/schede/{$a['id']}", null, $gettone)->json('data.attese_aperte');

    expect($mostra())->toBe([['id' => $b['id'], 'numero' => 2], ['id' => $c['id'], 'numero' => 3]]);

    $finto->segnaScheda($b, 'completata');
    expect($mostra())->toBe([['id' => $c['id'], 'numero' => 3]]);

    $finto->segnaScheda($c, 'archiviata');
    expect($mostra())->toBe([])
        ->and(alFinto('GET', "/v1/board/schede/{$b['id']}", null, $gettone)->json('data.attese_aperte'))->toBe([]);
});

it('il completamento dice chi sblocca: solo chi non ha altre attese aperte; già completata e riapertura danno [] (T4.6)', function () {
    [, [$x, $solo, $due, $altra], $gettone] = conLeSchede(4);
    aspettaNelFinto($gettone, $solo, $x, 201);
    aspettaNelFinto($gettone, $due, $x, 201);
    aspettaNelFinto($gettone, $due, $altra, 201);

    $prima = alFinto('POST', "/v1/board/schede/{$x['id']}/completamento", null, $gettone);

    expect($prima->status())->toBe(200)
        ->and(array_keys($prima->json()))->toBe(['data', 'sbloccate'])
        ->and($prima->json('sbloccate'))->toBe([['id' => $solo['id'], 'numero' => 2, 'titolo' => 'Scheda 2']])
        ->and(alFinto('POST', "/v1/board/schede/{$x['id']}/completamento", null, $gettone)->json('sbloccate'))->toBe([])
        ->and(alFinto('DELETE', "/v1/board/schede/{$x['id']}/completamento", null, $gettone)->json('sbloccate'))->toBe([])
        ->and(alFinto('POST', "/v1/board/schede/{$altra['id']}/completamento", null, $gettone)->json('sbloccate'))->toBe([['id' => $due['id'], 'numero' => 3, 'titolo' => 'Scheda 3']]);
});

it('mostra e completamento: scheda archiviata 409, scheda che non c\'è 404, app pm spenta 403 (T4.6)', function () {
    [$finto, [$a], $gettone] = conLeSchede(1);
    $finto->segnaScheda($a, 'archiviata');

    expect(alFinto('POST', "/v1/board/schede/{$a['id']}/completamento", null, $gettone)->json('codice'))->toBe('scheda_archiviata')
        ->and(alFinto('GET', "/v1/board/schede/{$a['id']}", null, $gettone)->status())->toBe(200)
        ->and(alFinto('GET', '/v1/board/schede/01k6w6a2c4e6g8j0m2p4r6t8v2', null, $gettone)->json('codice'))->toBe('non_trovato')
        ->and(alFinto('DELETE', '/v1/board/schede/01k6w6a2c4e6g8j0m2p4r6t8v2/completamento', null, $gettone)->json('codice'))->toBe('non_trovato');

    [, [$b], $gettoneSpento] = conLeSchede(1, pm: false);
    expect(alFinto('GET', "/v1/board/schede/{$b['id']}", null, $gettoneSpento)->json('codice'))->toBe('app_non_attiva')
        ->and(alFinto('POST', "/v1/board/schede/{$b['id']}/completamento", null, $gettoneSpento)->json('codice'))->toBe('app_non_attiva');
});
