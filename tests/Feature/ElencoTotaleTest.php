<?php

use Illuminate\Support\Facades\Http;
use Zeiras\Auth\Api;

// #3773: Elenco::$totale è il `totale` della risposta se è un intero ≥ 0 (dall'ultima pagina letta), altrimenti null.

it('Elenco::$totale è null se la risposta porta un totale che non è un intero ≥ 0', function (mixed $totale) {
    apriSessione(false);
    Http::fake([API.'/*' => Http::response(['data' => [['id' => 'a']], 'successivo' => null, 'totale' => $totale], 200)]);

    $elenco = Api::persona()->elenco('/v1/staff/registro');

    expect($elenco->totale)->toBeNull()->and($elenco->elementi)->toHaveCount(1);
})->with(['negativo' => [-1], 'testo' => ['5'], 'decimale' => [3.5], 'null' => [null], 'lista' => [[1]]]);

it('Elenco::$totale è 0 per una lista vuota che lo dichiara, e null se la risposta non lo ha', function () {
    apriSessione(false);
    Http::fake([API.'/*' => Http::sequence()->push(['data' => [], 'successivo' => null, 'totale' => 0], 200)->push(['data' => [], 'successivo' => null], 200)]);

    expect(Api::persona()->elenco('/v1/staff/registro')->totale)->toBe(0)
        ->and(Api::persona()->elenco('/v1/staff')->totale)->toBeNull();
});
