<?php

// #2554: crm.catalogo.ricerca.crea nel finto. Qui ciò che un frontend vede dal suo lato; che le risposte e gli errori siano quelli del
// backoffice lo prova il suo FintoTest.

/** @return list<string> i nomi trovati, nell'ordine */
function nomiCercatiNelFinto(array $corpo, string $gettone): array
{
    $risposta = alFinto('POST', '/v1/crm/catalogo/ricerca', $corpo, $gettone);

    expect($risposta->status())->toBe(200);

    return array_column($risposta->json('data'), 'nome');
}

it('q cerca nel nome senza maiuscole né accenti, ogni parola deve starci, e {} dà le voci dell\'elenco (CR.1, CR.2)', function () {
    [, $gettoni] = studioColCrm();
    foreach (['Corso PM base', 'Corso di cucina', 'Èsame di PM'] as $nome) {
        expect(alFinto('POST', '/v1/crm/catalogo', ['tipo' => 'servizio', 'nome' => $nome], $gettoni['anna'])->status())->toBe(201);
    }

    $elenco = array_column(alFinto('GET', '/v1/crm/catalogo', null, $gettoni['carla'])->json('data'), 'nome');

    expect(nomiCercatiNelFinto(['q' => 'CORSO pm'], $gettoni['carla']))->toBe(['Corso PM base'])
        ->and(nomiCercatiNelFinto(['q' => 'esame'], $gettoni['carla']))->toBe(['Èsame di PM'])
        ->and(nomiCercatiNelFinto(['q' => 'corso'], $gettoni['carla']))->toBe(['Corso di cucina', 'Corso PM base'])
        ->and(nomiCercatiNelFinto(['q' => 'corso inesistente'], $gettoni['carla']))->toBe([])
        ->and(nomiCercatiNelFinto([], $gettoni['carla']))->toBe($elenco);
});

it('i filtri, il totale e il cursore valgono come in elenca; un cursore con altri filtri è 422 su #/cursore (CR.2)', function () {
    [, $gettoni] = studioColCrm();
    foreach ([['Corso uno', 'servizio'], ['Corso due', 'prodotto'], ['Corso tre', 'servizio']] as [$nome, $tipo]) {
        alFinto('POST', '/v1/crm/catalogo', ['tipo' => $tipo, 'nome' => $nome], $gettoni['anna']);
    }

    $prima = alFinto('POST', '/v1/crm/catalogo/ricerca', ['q' => 'corso', 'tipo' => ['servizio'], 'limite' => 1, 'totale' => true], $gettoni['carla']);
    $seconda = alFinto('POST', '/v1/crm/catalogo/ricerca', ['q' => 'corso', 'tipo' => ['servizio'], 'limite' => 1, 'cursore' => $prima->json('successivo')], $gettoni['carla']);
    $altri = alFinto('POST', '/v1/crm/catalogo/ricerca', ['q' => 'corso', 'limite' => 1, 'cursore' => $prima->json('successivo')], $gettoni['carla']);

    expect(array_column($prima->json('data'), 'nome'))->toBe(['Corso tre'])
        ->and($prima->json('totale'))->toBe(2)
        ->and(array_column($seconda->json('data'), 'nome'))->toBe(['Corso uno'])
        ->and($seconda->json('successivo'))->toBeNull()
        ->and($altri->status())->toBe(422)
        ->and($altri->json('errors.0.pointer'))->toBe('#/cursore');
});

it('q vuoto o non testo è 422 su #/q, e q nella query è 422 sul parametro senza ripetere il valore, in ricerca e in elenca (CR.4)', function () {
    [, $gettoni] = studioColCrm();
    alFinto('POST', '/v1/crm/catalogo', ['tipo' => 'servizio', 'nome' => 'Segretissimo'], $gettoni['anna']);

    foreach ([str_repeat('a', 101), '', '   ', null, 12] as $q) {
        $risposta = alFinto('POST', '/v1/crm/catalogo/ricerca', ['q' => $q], $gettoni['carla']);

        expect($risposta->status())->toBe(422)->and($risposta->json('errors.0.pointer'))->toBe('#/q');
    }

    $elenca = alFinto('GET', '/v1/crm/catalogo?q=Segretissimo', null, $gettoni['carla']);
    $cerca = alFinto('POST', '/v1/crm/catalogo/ricerca?q=Segretissimo', ['q' => 'x'], $gettoni['carla']);

    expect($elenca->status())->toBe(422)->and($elenca->json('errors.0.parameter'))->toBe('q')->and($elenca->body())->not->toContain('Segretissimo')
        ->and($cerca->status())->toBe(422)->and($cerca->json('errors.0.parameter'))->toBe('q')->and($cerca->body())->not->toContain('Segretissimo');
});
