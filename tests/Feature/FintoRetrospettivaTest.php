<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #2667 (zr-board, Area 4f): la retrospettiva di uno sprint chiuso, i voti alle sue schede, `dalla_scheda_id` e `trasformata_in` nel finto. Qui ciò che un frontend vede dal suo
// lato (le forme, i 409, i 422); che i testi e i codici siano quelli del backoffice lo provano lang/ copiata e il FintoTest del backoffice.

/**
 * Un workspace con `pm` attiva, una board Scrum con uno sprint chiuso e due membri: [finto, workspace, board, sprint, gettone di Anna, gettone di Bruno].
 *
 * @return array{BackofficeFinto, array<string, mixed>, array<string, mixed>, array<string, mixed>, string, string}
 */
function conLoSprintChiuso(string $stato = 'chiuso'): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $bruno = $finto->persona('bruno@example.com', PASSWORD, 'Bruno');
    $studio = $finto->workspace('Studio', $anna);
    $finto->membro($studio, $bruno, 'membro');
    $finto->attivaApp($studio, 'pm');
    $board = $finto->board($studio, 'Prodotto', null, 'attiva', 'scrum');
    $sprint = $finto->sprint($studio, $board, 'Sprint 12', $stato);
    $gettone = fn (string $email) => alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto($email)['gettone']['gettone'])->json('data.gettone');

    return [$finto, $studio, $board, $sprint, $gettone('anna@example.com'), $gettone('bruno@example.com')];
}

it('la retrospettiva risponde 201 con la board intera, tre liste nella lingua di chi chiama, la terza col ruolo azioni, Location e il legame sullo sprint', function () {
    [, , $board, $sprint, $gettone] = conLoSprintChiuso();

    $risposta = alFinto('POST', "/v1/board/sprint/{$sprint['id']}/retrospettiva", [], $gettone, 'it');
    $dati = $risposta->json('data');

    expect($risposta->status())->toBe(201)
        ->and($risposta->header('Location'))->toBe("/v1/board/board/{$dati['id']}")
        ->and(array_keys($dati))->toBe(['id', 'nome', 'cartella_id', 'metodo', 'funzione_attiva', 'creata_il', 'aggiornata_il', 'numero_schede', 'descrizione', 'sfondo', 'stato', 'applica_regole', 'chiusa_il', 'nel_cestino_il', 'cancellata_il', 'tipi', 'retrospettiva_di', 'liste', 'etichette', 'io'])
        ->and($dati['nome'])->toBe('Retrospettiva: Sprint 12')
        ->and($dati['metodo'])->toBeNull()
        ->and($dati['retrospettiva_di'])->toBe($sprint['id'])
        ->and(array_column($dati['liste'], 'nome'))->toBe(['Cosa è andato bene', 'Da migliorare', 'Azioni'])
        ->and(array_column($dati['liste'], 'ruolo'))->toBe([null, null, 'azioni'])
        ->and(array_keys($dati['liste'][0]))->toBe(['id', 'board_id', 'nome', 'ruolo', 'soglia', 'limite', 'blocca', 'passaggi', 'requisiti', 'completa_all_arrivo', 'numero_schede', 'archiviata_il', 'creata_il', 'aggiornata_il']);

    $sprintDopo = alFinto('GET', "/v1/board/sprint?board_id={$board['id']}", null, $gettone)->json('data.0');

    expect($sprintDopo['retrospettiva_board_id'])->toBe($dati['id'])
        ->and(array_keys($sprintDopo))->toContain('retrospettiva_board_id');
});

it('la lingua della richiesta dà i nomi della board e delle liste', function () {
    [, , , $sprint, $gettone] = conLoSprintChiuso();

    $dati = alFinto('POST', "/v1/board/sprint/{$sprint['id']}/retrospettiva", [], $gettone, 'en')->json('data');

    expect($dati['nome'])->toBe('Retrospective: Sprint 12')
        ->and(array_column($dati['liste'], 'nome'))->toBe(['What went well', 'To improve', 'Actions']);
});

it('la retrospettiva è una per sprint e solo dello sprint chiuso: 409 retrospettiva_esistente, sprint_non_chiuso; un campo è 422, uno sprint che non c\'è 404', function () {
    [$finto, $studio, $board, $sprint, $gettone] = conLoSprintChiuso();
    $attivo = $finto->sprint($studio, $board, 'Sprint 13', 'attivo');

    $estraneo = alFinto('POST', "/v1/board/sprint/{$sprint['id']}/retrospettiva", ['nome' => 'x'], $gettone);

    expect($estraneo->status())->toBe(422)
        ->and($estraneo->json('errors.0'))->toMatchArray(['regola' => 'campo_estraneo', 'pointer' => '#/nome'])
        ->and(alFinto('POST', "/v1/board/sprint/{$attivo['id']}/retrospettiva", [], $gettone)->json('codice'))->toBe('sprint_non_chiuso')
        ->and(alFinto('POST', '/v1/board/sprint/01k6w9a1c3e5g7j9m1p3r5t7v9/retrospettiva', [], $gettone)->status())->toBe(404)
        ->and(alFinto('POST', "/v1/board/sprint/{$sprint['id']}/retrospettiva", [], $gettone)->status())->toBe(201);

    $seconda = alFinto('POST', "/v1/board/sprint/{$sprint['id']}/retrospettiva", [], $gettone);

    expect($seconda->status())->toBe(409)->and($seconda->json('codice'))->toBe('retrospettiva_esistente');
});

it('il voto: 201 con la scheda e voti 1, l\'elenco dice chi ha votato, un solo voto per persona (409 voto_gia_dato), e un altro membro vota a parte', function () {
    [$finto, $studio, , $sprint, $anna, $bruno] = conLoSprintChiuso();
    $retro = alFinto('POST', "/v1/board/sprint/{$sprint['id']}/retrospettiva", [], $anna)->json('data.id');
    $scheda = $finto->scheda($studio, 'Più demo', $retro);

    $voto = alFinto('POST', "/v1/board/schede/{$scheda['id']}/voti", [], $anna);

    expect($voto->status())->toBe(201)
        ->and($voto->json('data.voti'))->toBe(1)
        ->and($voto->json('data.id'))->toBe($scheda['id'])
        ->and(alFinto('POST', "/v1/board/schede/{$scheda['id']}/voti", [], $anna)->json('codice'))->toBe('voto_gia_dato')
        ->and(alFinto('POST', "/v1/board/schede/{$scheda['id']}/voti", [], $bruno)->json('data.voti'))->toBe(2);

    $elenco = alFinto('GET', "/v1/board/schede/{$scheda['id']}/voti", null, $anna)->json('data');

    expect(array_keys($elenco[0]))->toBe(['utente_id', 'creato_il'])
        ->and(count($elenco))->toBe(2)
        ->and(alFinto('GET', "/v1/board/schede/{$scheda['id']}", null, $anna)->json('data.voti'))->toBe(2);
});

it('togliere il voto: 200 con la scheda e un voto in meno, si può rivotare; chi non aveva votato è 404', function () {
    [$finto, $studio, , $sprint, $anna, $bruno] = conLoSprintChiuso();
    $retro = alFinto('POST', "/v1/board/sprint/{$sprint['id']}/retrospettiva", [], $anna)->json('data.id');
    $scheda = $finto->scheda($studio, 'Più demo', $retro);

    expect(alFinto('DELETE', "/v1/board/schede/{$scheda['id']}/voti", null, $anna)->status())->toBe(404);

    alFinto('POST', "/v1/board/schede/{$scheda['id']}/voti", [], $anna);
    alFinto('POST', "/v1/board/schede/{$scheda['id']}/voti", [], $bruno);
    $tolto = alFinto('DELETE', "/v1/board/schede/{$scheda['id']}/voti", null, $anna);

    expect($tolto->status())->toBe(200)
        ->and($tolto->json('data.voti'))->toBe(1)
        ->and(alFinto('POST', "/v1/board/schede/{$scheda['id']}/voti", [], $anna)->json('data.voti'))->toBe(2);
});

it('i voti di una scheda che non è di una retrospettiva sono 409 scheda_non_di_retrospettiva, una archiviata 409 scheda_archiviata, un corpo con un campo 422, una scheda che non c\'è 404', function () {
    [$finto, $studio, $board, $sprint, $anna] = conLoSprintChiuso();
    $retro = alFinto('POST', "/v1/board/sprint/{$sprint['id']}/retrospettiva", [], $anna)->json('data.id');
    $normale = $finto->scheda($studio, 'Normale', $board['id']);
    $archiviata = $finto->scheda($studio, 'Vecchia', $retro);
    $buona = $finto->scheda($studio, 'Buona', $retro);
    $finto->segnaScheda($archiviata, 'archiviata');

    foreach (['GET' => null, 'POST' => [], 'DELETE' => null] as $metodo => $corpo) {
        expect(alFinto($metodo, "/v1/board/schede/{$normale['id']}/voti", $corpo, $anna)->json('codice'))->toBe('scheda_non_di_retrospettiva');
    }

    $estraneo = alFinto('POST', "/v1/board/schede/{$buona['id']}/voti", ['x' => 1], $anna);

    expect(alFinto('POST', "/v1/board/schede/{$archiviata['id']}/voti", [], $anna)->json('codice'))->toBe('scheda_archiviata')
        ->and($estraneo->status())->toBe(422)
        ->and($estraneo->json('errors.0.pointer'))->toBe('#/x')
        ->and(alFinto('POST', '/v1/board/schede/01k6w9a1c3e5g7j9m1p3r5t7v9/voti', [], $anna)->status())->toBe(404);
});

it('il voto cambia l\'aggiornata_il e l\'ETag della scheda', function () {
    [$finto, $studio, , $sprint, $anna] = conLoSprintChiuso();
    $retro = alFinto('POST', "/v1/board/sprint/{$sprint['id']}/retrospettiva", [], $anna)->json('data.id');
    $scheda = $finto->scheda($studio, 'Più demo', $retro);
    $prima = alFinto('GET', "/v1/board/schede/{$scheda['id']}", null, $anna);

    $this->travel(2)->seconds();
    $dopo = alFinto('POST', "/v1/board/schede/{$scheda['id']}/voti", [], $anna)->json('data');

    expect($dopo['aggiornata_il'])->not->toBe($prima->json('data.aggiornata_il'))
        ->and($dopo['etag'])->not->toBe($prima->json('data.etag'));
});

it('dalla_scheda_id e trasformata_in: la scheda nata da un\'azione la dice, l\'azione elenca le figlie vive nell\'ordine di nascita, l\'archiviata esce', function () {
    [$finto, $studio, $board, $sprint, $anna] = conLoSprintChiuso();
    $retro = alFinto('POST', "/v1/board/sprint/{$sprint['id']}/retrospettiva", [], $anna)->json('data.id');
    $azione = $finto->scheda($studio, 'Fare le demo', $retro);
    $prima = $finto->scheda($studio, 'Prima', $board['id'], dallaScheda: $azione['id']);
    $seconda = $finto->scheda($studio, 'Seconda', $board['id'], dallaScheda: $azione['id']);
    $libera = alFinto('GET', "/v1/board/schede/{$prima['id']}", null, $anna)->json('data');

    expect($libera['dalla_scheda_id'])->toBe($azione['id'])
        ->and($libera['trasformata_in'])->toBe([])
        ->and(array_keys($libera))->toContain('dalla_scheda_id', 'trasformata_in', 'voti')
        ->and(alFinto('GET', "/v1/board/schede/{$azione['id']}", null, $anna)->json('data.dalla_scheda_id'))->toBeNull()
        ->and(alFinto('GET', "/v1/board/schede/{$azione['id']}", null, $anna)->json('data.trasformata_in'))->toBe([$prima['id'], $seconda['id']]);

    $finto->segnaScheda($prima, 'archiviata');

    expect(alFinto('GET', "/v1/board/schede/{$azione['id']}", null, $anna)->json('data.trasformata_in'))->toBe([$seconda['id']]);

    expect(fn () => $finto->scheda($studio, 'Orfana', $board['id'], dallaScheda: $seconda['id']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finto->scheda($studio, 'Orfana', $board['id'], dallaScheda: 'non-c-e'))->toThrow(InvalidArgumentException::class);
});
