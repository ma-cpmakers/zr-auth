<?php

use Carbon\CarbonImmutable;

// #3525 (Finanze, tappa 4): gli avvisi nel finto, dal lato di un frontend. Che le risposte e gli errori siano quelli del backoffice lo prova il suo FintoTest.

/** Un conto con un solo movimento vecchio di sessanta giorni: dà un avviso «estratto fermo». */
function contoFermoDelFinto(string $gettone, string $visibilita): string
{
    $conto = contoDelFinto($gettone, $visibilita, 'Fermo '.$visibilita)['id'];
    $vecchio = CarbonImmutable::now('Europe/Rome')->subDays(60)->format('Y-m-d');
    expect(alFinto('POST', '/v1/flow/movimenti', ['conto_id' => $conto, 'data' => $vecchio, 'importo' => -500, 'descrizione' => 'Vecchio'], $gettone)->status())->toBe(201);

    return $conto;
}

it('un conto fermo da più di trentacinque giorni dà un avviso aperto, con la sua forma e il suo ETag (FL.22)', function () {
    [, $gettoni] = studioConFinanze();
    $conto = contoFermoDelFinto($gettoni['anna'], 'comune');

    $elenco = alFinto('GET', '/v1/flow/avvisi?visibilita=comune', null, $gettoni['bruno']);
    expect($elenco->status())->toBe(200, $elenco->body())
        ->and($elenco->json('data'))->toHaveCount(1)
        ->and(array_keys($elenco->json('data.0')))->toBe(['id', 'tipo', 'riferimento', 'importo', 'mese', 'creato_il', 'stato', 'silenziato', 'etag'])
        ->and($elenco->json('data.0.tipo'))->toBe('estratto_fermo')
        ->and($elenco->json('data.0.riferimento'))->toBe($conto)
        ->and($elenco->json('data.0.importo'))->toBeNull()
        ->and($elenco->json('data.0.stato'))->toBe('aperto')
        ->and($elenco->json('successivo'))->toBeNull();

    $id = $elenco->json('data.0.id');
    $mostra = alFinto('GET', "/v1/flow/avvisi/{$id}", null, $gettoni['bruno']);
    expect($mostra->status())->toBe(200)->and($mostra->header('ETag'))->toBe($elenco->json('data.0.etag'));

    // Chiamare di nuovo non lo riscrive.
    expect(alFinto('GET', '/v1/flow/avvisi?visibilita=comune', null, $gettoni['anna'])->json('data'))->toHaveCount(1);
});

it('lo stato di un avviso è della persona; il silenzio lo chiude; If-Match vuole la versione letta (FL.22)', function () {
    [, $gettoni] = studioConFinanze();
    contoFermoDelFinto($gettoni['anna'], 'comune');
    $id = alFinto('GET', '/v1/flow/avvisi?visibilita=comune', null, $gettoni['anna'])->json('data.0.id');
    $versione = alFinto('GET', "/v1/flow/avvisi/{$id}", null, $gettoni['anna'])->header('ETag');

    $letto = alFinto('PATCH', "/v1/flow/avvisi/{$id}", ['stato' => 'letto'], $gettoni['anna'], null, ['If-Match' => $versione]);
    expect($letto->status())->toBe(200, $letto->body())->and($letto->json('data.stato'))->toBe('letto')->and($letto->header('ETag'))->not->toBe($versione);
    expect(alFinto('GET', "/v1/flow/avvisi/{$id}", null, $gettoni['bruno'])->json('data.stato'))->toBe('aperto');
    expect(alFinto('PATCH', "/v1/flow/avvisi/{$id}", ['stato' => 'chiuso'], $gettoni['anna'], null, ['If-Match' => $versione])->status())->toBe(412);
    expect(alFinto('PATCH', "/v1/flow/avvisi/{$id}", ['stato' => 'chiuso'], $gettoni['anna'], null, ['If-Match' => 'senza-virgolette'])->status())->toBe(422);

    // Silenziare chiude; con `stato: letto` è 422; togliere il silenzio non riapre.
    expect(alFinto('PATCH', "/v1/flow/avvisi/{$id}", ['silenzia' => true, 'stato' => 'letto'], $gettoni['anna'])->status())->toBe(422);
    $silenziato = alFinto('PATCH', "/v1/flow/avvisi/{$id}", ['silenzia' => true], $gettoni['anna']);
    expect($silenziato->json('data.stato'))->toBe('chiuso')->and($silenziato->json('data.silenziato'))->toBeTrue();
    expect(alFinto('GET', '/v1/flow/avvisi?visibilita=comune', null, $gettoni['anna'])->json('data'))->toBe([])
        ->and(alFinto('GET', '/v1/flow/avvisi?visibilita=comune&stato=chiusi', null, $gettoni['anna'])->json('data'))->toHaveCount(1)
        ->and(alFinto('GET', '/v1/flow/avvisi?visibilita=comune', null, $gettoni['carla'])->json('data'))->toHaveCount(1);
    $riaperto = alFinto('PATCH', "/v1/flow/avvisi/{$id}", ['silenzia' => false], $gettoni['anna']);
    expect($riaperto->json('data.silenziato'))->toBeFalse()->and($riaperto->json('data.stato'))->toBe('chiuso');

    foreach ([[], ['colore' => 'blu'], ['stato' => 'aperto'], ['silenzia' => 'si']] as $corpo) {
        expect(alFinto('PATCH', "/v1/flow/avvisi/{$id}", $corpo, $gettoni['anna'])->status())->toBe(422);
    }
});

it('un avviso di un conto personale lo vede solo il titolare: per ogni altro è 404, e l\'elenco lo salta (FL.22)', function () {
    [, $gettoni] = studioConFinanze();
    contoFermoDelFinto($gettoni['carla'], 'personale');
    $id = alFinto('GET', '/v1/flow/avvisi?visibilita=personale', null, $gettoni['carla'])->json('data.0.id');

    expect($id)->toBeString()
        ->and(alFinto('GET', '/v1/flow/avvisi?visibilita=personale', null, $gettoni['anna'])->json('data'))->toBe([])
        ->and(alFinto('GET', '/v1/flow/avvisi?visibilita=comune', null, $gettoni['carla'])->json('data'))->toBe([]);

    foreach (['anna', 'bruno'] as $chi) {
        expect(alFinto('GET', "/v1/flow/avvisi/{$id}", null, $gettoni[$chi])->status())->toBe(404)
            ->and(alFinto('PATCH', "/v1/flow/avvisi/{$id}", ['stato' => 'letto'], $gettoni[$chi])->status())->toBe(404);
    }

    foreach (['xyz', '01k6w4a2c4e6g8j0m2p4r6t8v0'] as $inesistente) {
        expect(alFinto('GET', "/v1/flow/avvisi/{$inesistente}", null, $gettoni['anna'])->status())->toBe(404);
    }
});

it('l\'elenco degli avvisi vuole la vista e rifiuta ogni altro parametro; la modifica frena a centoventi all\'ora (FL.22)', function () {
    [, $gettoni] = studioConFinanze();

    foreach (['', '?visibilita=altro', '?visibilita=comune&stato=tutti', '?visibilita=comune&limite=0', '?visibilita=comune&tipo=saldo_non_basta', '?visibilita=comune&cursore=x'] as $query) {
        expect(alFinto('GET', "/v1/flow/avvisi{$query}", null, $gettoni['anna'])->status())->toBe(422);
    }

    $ultimo = null;

    for ($i = 0; $i < 125; $i++) {
        $ultimo = alFinto('PATCH', '/v1/flow/avvisi/01k6w4a2c4e6g8j0m2p4r6t8v0', ['stato' => 'letto'], $gettoni['bruno']);

        if ($ultimo->status() === 429) {
            break;
        }
    }

    expect($i)->toBe(120)->and($ultimo->status())->toBe(429)->and($ultimo->header('Retry-After'))->not->toBeNull();
});
