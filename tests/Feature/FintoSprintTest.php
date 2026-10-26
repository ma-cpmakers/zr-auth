<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #2664 (zr-board, Area 4c): gli sprint di una board Scrum nel finto. Qui ciò che un frontend vede dal suo lato (la forma, l'ordine
// dell'elenco, la velocità, la chiusura che sposta le schede, i 409 e i pointer dei 422); che le risposte e gli errori siano quelli del
// backoffice lo prova il FintoTest del backoffice, che confronta finto e backoffice sulle stesse chiamate.

/**
 * Un workspace con `pm` attiva e una board Scrum: [finto, workspace, board, gettone del workspace].
 *
 * @return array{BackofficeFinto, array<string, mixed>, array<string, mixed>, string}
 */
function conLaBoardScrum(string $metodo = 'scrum', string $stato = 'attiva'): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio', $anna);
    $finto->attivaApp($studio, 'pm');
    $board = $finto->board($studio, 'Prodotto', null, $stato, $metodo);
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');

    return [$finto, $studio, $board, $gettone];
}

/** Il corpo di board.sprint.crea di una board. */
function corpoDelloSprint(array $board, array $altri = []): array
{
    return ['board_id' => $board['id'], 'nome' => 'Sprint 1', 'inizio' => '2026-11-02', 'durata_settimane' => 2, ...$altri];
}

it('crea uno sprint pianificato: 201 senza Location, la forma del backoffice con la fine calcolata; il secondo pianificato è 409 limite_raggiunto', function () {
    [, , $board, $gettone] = conLaBoardScrum();

    $nato = alFinto('POST', '/v1/board/sprint', corpoDelloSprint($board, ['obiettivo' => 'Il carrello']), $gettone);

    expect($nato->status())->toBe(201)->and($nato->header('Location'))->toBe('')
        ->and(array_keys($nato->json('data')))->toBe(['id', 'board_id', 'nome', 'obiettivo', 'stato', 'inizio', 'fine', 'durata_settimane', 'avviato_il', 'chiuso_il', 'obiettivo_raggiunto', 'velocita', 'velocita_unita', 'creata_il', 'aggiornata_il'])
        ->and($nato->json('data'))->toMatchArray([
            'board_id' => $board['id'], 'nome' => 'Sprint 1', 'obiettivo' => 'Il carrello', 'stato' => 'pianificato', 'inizio' => '2026-11-02', 'fine' => '2026-11-15',
            'durata_settimane' => 2, 'avviato_il' => null, 'chiuso_il' => null, 'obiettivo_raggiunto' => null, 'velocita' => null, 'velocita_unita' => null,
        ]);

    $secondo = alFinto('POST', '/v1/board/sprint', corpoDelloSprint($board, ['nome' => 'Sprint 2']), $gettone);
    expect($secondo->status())->toBe(409)->and($secondo->json('codice'))->toBe('limite_raggiunto');
});

it('crea: il corpo sbagliato è 422 sul suo campo, una board che non è del workspace è 422 su #/board_id, il campo in più è rifiutato', function () {
    [$finto, , $board, $gettone] = conLaBoardScrum();
    $bruno = $finto->persona('bruno@example.com', PASSWORD, 'Bruno');
    $altroStudio = $finto->workspace('Altro', $bruno);
    $finto->attivaApp($altroStudio, 'pm');
    $dellAltro = $finto->board($altroStudio, 'Estranea', null, 'attiva', 'scrum');

    $pointer = fn (array $corpo) => alFinto('POST', '/v1/board/sprint', $corpo, $gettone)->json('errors.0.pointer');

    expect($pointer(corpoDelloSprint($board, ['nome' => ''])))->toBe('#/nome')
        ->and($pointer(corpoDelloSprint($board, ['inizio' => '02/11/2026'])))->toBe('#/inizio')
        ->and($pointer(corpoDelloSprint($board, ['durata_settimane' => 5])))->toBe('#/durata_settimane')
        ->and($pointer(corpoDelloSprint($board, ['durata_settimane' => '2'])))->toBe('#/durata_settimane')
        ->and($pointer(corpoDelloSprint($board, ['colore' => 'pine'])))->toBe('#/colore')
        ->and($pointer(corpoDelloSprint($dellAltro)))->toBe('#/board_id')
        ->and($pointer(corpoDelloSprint(['id' => '01k6w9a1c3e5g7j9m1p3r5t7v9'])))->toBe('#/board_id');
});

it('una board senza metodo o con un altro metodo è 409 board_senza_metodo a ogni metodo; una chiusa o nel cestino, a chi scrive, 409 board_chiusa', function () {
    [$finto, $studio, $senza, $gettone] = conLaBoardScrum('waterfall');
    $chiusa = $finto->board($studio, 'Chiusa', null, 'chiusa', 'scrum');
    $cestino = $finto->board($studio, 'Cestino', null, 'cestino', 'scrum');

    expect(alFinto('GET', "/v1/board/sprint?board_id={$senza['id']}", null, $gettone)->json('codice'))->toBe('board_senza_metodo')
        ->and(alFinto('POST', '/v1/board/sprint', corpoDelloSprint($senza), $gettone)->json('codice'))->toBe('board_senza_metodo');

    foreach ([$chiusa, $cestino] as $board) {
        $risposta = alFinto('POST', '/v1/board/sprint', corpoDelloSprint($board), $gettone);
        expect($risposta->status())->toBe(409)->and($risposta->json('codice'))->toBe('board_chiusa');
    }

    // Una board chiusa si legge, come nel backoffice: l'elenco non guarda lo stato.
    $finto->sprint($studio, $chiusa, 'Vecchio', 'chiuso');
    expect(alFinto('GET', "/v1/board/sprint?board_id={$chiusa['id']}", null, $gettone)->status())->toBe(200);
});

it('l\'elenco dà l\'attivo, il pianificato, poi i chiusi dal più recente, a cursore, con la velocità media dell\'unità dell\'ultimo chiuso', function () {
    [$finto, $studio, $board, $gettone] = conLaBoardScrum();
    $finto->sprint($studio, $board, 'Primo', 'chiuso', '2026-09-07', 2, null, 10, 'schede');
    $finto->sprint($studio, $board, 'Secondo', 'chiuso', '2026-09-21', 2, null, 20, 'punti');
    $finto->sprint($studio, $board, 'Terzo', 'chiuso', '2026-10-05', 2, null, 30, 'punti');
    $finto->sprint($studio, $board, 'Quarto', 'attivo', '2026-10-19');
    $finto->sprint($studio, $board, 'Quinto', 'pianificato', '2026-11-02');

    $tutti = alFinto('GET', "/v1/board/sprint?board_id={$board['id']}", null, $gettone);

    expect($tutti->status())->toBe(200)
        ->and(array_column($tutti->json('data'), 'nome'))->toBe(['Quarto', 'Quinto', 'Terzo', 'Secondo', 'Primo'])
        ->and($tutti->json('successivo'))->toBeNull()
        ->and($tutti->json('meta'))->toBe(['velocita_media' => 25, 'velocita_media_unita' => 'punti']);

    $prima = alFinto('GET', "/v1/board/sprint?board_id={$board['id']}&limite=2", null, $gettone);
    $seconda = alFinto('GET', "/v1/board/sprint?board_id={$board['id']}&limite=2&cursore=".urlencode($prima->json('successivo')), null, $gettone);
    $terza = alFinto('GET', "/v1/board/sprint?board_id={$board['id']}&limite=2&cursore=".urlencode($seconda->json('successivo')), null, $gettone);

    expect(array_column($prima->json('data'), 'nome'))->toBe(['Quarto', 'Quinto'])
        ->and(array_column($seconda->json('data'), 'nome'))->toBe(['Terzo', 'Secondo'])
        ->and(array_column($terza->json('data'), 'nome'))->toBe(['Primo'])
        ->and($terza->json('successivo'))->toBeNull();

    $chiusi = alFinto('GET', "/v1/board/sprint?board_id={$board['id']}&stato=chiuso", null, $gettone);
    expect(array_column($chiusi->json('data'), 'nome'))->toBe(['Terzo', 'Secondo', 'Primo']);
});

it('l\'elenco senza chiusi ha la velocità media null; senza board_id, con una board di un altro workspace o con uno stato fuori elenco è 422 sul parametro', function () {
    [, , $board, $gettone] = conLaBoardScrum();

    expect(alFinto('GET', "/v1/board/sprint?board_id={$board['id']}", null, $gettone)->json('meta'))->toBe(['velocita_media' => null, 'velocita_media_unita' => null]);

    foreach (['', '?board_id=01k6w9a1c3e5g7j9m1p3r5t7v9', "?board_id={$board['id']}&stato=archiviato"] as $query) {
        $risposta = alFinto('GET', '/v1/board/sprint'.$query, null, $gettone);
        expect($risposta->status())->toBe(422)->and($risposta->json('errors.0.parameter'))->toBe(str_contains($query, 'stato') ? 'stato' : 'board_id');
    }
});

it('modifica cambia nome e obiettivo (null lo toglie); su uno sprint attivo inizio e durata diversi sono 409 sprint_attivo, uno chiuso è 409 sprint_chiuso', function () {
    [$finto, $studio, $board, $gettone] = conLaBoardScrum();
    $pianificato = $finto->sprint($studio, $board, 'Futuro', 'pianificato', '2026-11-02', 2, 'Da fare');
    $chiuso = $finto->sprint($studio, $board, 'Passato', 'chiuso');

    $cambiato = alFinto('PATCH', "/v1/board/sprint/{$pianificato['id']}", ['nome' => 'Prossimo', 'obiettivo' => null, 'inizio' => '2026-11-09', 'durata_settimane' => 1], $gettone);

    expect($cambiato->status())->toBe(200)
        ->and($cambiato->json('data'))->toMatchArray(['nome' => 'Prossimo', 'obiettivo' => null, 'inizio' => '2026-11-09', 'fine' => '2026-11-15', 'durata_settimane' => 1]);

    expect(alFinto('POST', "/v1/board/sprint/{$pianificato['id']}/avvio", null, $gettone)->status())->toBe(201);

    $uguali = alFinto('PATCH', "/v1/board/sprint/{$pianificato['id']}", ['inizio' => '2026-11-09', 'durata_settimane' => 1, 'nome' => 'Attivo'], $gettone);
    $diversi = alFinto('PATCH', "/v1/board/sprint/{$pianificato['id']}", ['durata_settimane' => 3], $gettone);
    $inChiuso = alFinto('PATCH', "/v1/board/sprint/{$chiuso['id']}", ['nome' => 'Altro'], $gettone);

    expect($uguali->status())->toBe(200)->and($uguali->json('data.nome'))->toBe('Attivo')
        ->and($diversi->status())->toBe(409)->and($diversi->json('codice'))->toBe('sprint_attivo')
        ->and($inChiuso->status())->toBe(409)->and($inChiuso->json('codice'))->toBe('sprint_chiuso');
});

it('modifica: un corpo senza campi è 422 su #, il campo in più è rifiutato; uno sprint che non c\'è o di un altro workspace è 404', function () {
    [$finto, $studio, $board, $gettone] = conLaBoardScrum();
    $sprint = $finto->sprint($studio, $board, 'Futuro');
    $bruno = $finto->persona('bruno@example.com', PASSWORD, 'Bruno');
    $altroStudio = $finto->workspace('Altro', $bruno);
    $finto->attivaApp($altroStudio, 'pm');
    $altroGettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $altroStudio['id']], entraNelFinto('bruno@example.com')['gettone']['gettone'])->json('data.gettone');

    expect(alFinto('PATCH', "/v1/board/sprint/{$sprint['id']}", [], $gettone)->json('errors.0.pointer'))->toBe('#')
        ->and(alFinto('PATCH', "/v1/board/sprint/{$sprint['id']}", ['stato' => 'attivo'], $gettone)->json('errors.0.pointer'))->toBe('#/stato')
        ->and(alFinto('PATCH', '/v1/board/sprint/01k6w9a1c3e5g7j9m1p3r5t7v9', ['nome' => 'X'], $gettone)->status())->toBe(404)
        ->and(alFinto('PATCH', "/v1/board/sprint/{$sprint['id']}", ['nome' => 'X'], $altroGettone)->status())->toBe(404);
});

it('avvio passa a attivo con avviato_il; un altro sprint già attivo è 409 sprint_attivo, uno non pianificato è 409 sprint_non_pianificato', function () {
    [$finto, $studio, $board, $gettone] = conLaBoardScrum();
    $attivo = $finto->sprint($studio, $board, 'In corso', 'attivo');
    $pianificato = $finto->sprint($studio, $board, 'Dopo');

    expect(alFinto('POST', "/v1/board/sprint/{$pianificato['id']}/avvio", null, $gettone)->json('codice'))->toBe('sprint_attivo')
        ->and(alFinto('POST', "/v1/board/sprint/{$attivo['id']}/avvio", null, $gettone)->json('codice'))->toBe('sprint_non_pianificato');

    expect(alFinto('POST', "/v1/board/sprint/{$attivo['id']}/chiusura", ['schede' => []], $gettone)->status())->toBe(201);

    $avviato = alFinto('POST', "/v1/board/sprint/{$pianificato['id']}/avvio", null, $gettone);
    expect($avviato->status())->toBe(201)->and($avviato->json('data.stato'))->toBe('attivo')->and($avviato->json('data.avviato_il'))->not->toBeNull();
});

it('la chiusura manda le schede aperte nel backlog o nello sprint dopo, tiene le completate, registra la velocità in punti e risponde 201', function () {
    [$finto, $studio, $board, $gettone] = conLaBoardScrum();
    $attivo = $finto->sprint($studio, $board, 'In corso', 'attivo');
    $dopo = $finto->sprint($studio, $board, 'Dopo');
    $fatta = $finto->scheda($studio, 'Fatta', $board['id'], punti: 5, sprint: $attivo['id']);
    $fattaSenzaPunti = $finto->scheda($studio, 'Fatta senza punti', $board['id'], sprint: $attivo['id']);
    $resta = $finto->scheda($studio, 'Resta', $board['id'], punti: 3, sprint: $attivo['id']);
    $esce = $finto->scheda($studio, 'Esce', $board['id'], punti: 8, sprint: $attivo['id']);
    $fuori = $finto->scheda($studio, 'Fuori dallo sprint', $board['id']);
    alFinto('POST', "/v1/board/schede/{$fatta['id']}/completamento", null, $gettone);
    alFinto('POST', "/v1/board/schede/{$fattaSenzaPunti['id']}/completamento", null, $gettone);

    $chiuso = alFinto('POST', "/v1/board/sprint/{$attivo['id']}/chiusura", [
        'schede' => [['id' => $resta['id'], 'destinazione' => 'sprint_dopo'], ['id' => $esce['id'], 'destinazione' => 'backlog']],
        'obiettivo_raggiunto' => true,
    ], $gettone);

    expect($chiuso->status())->toBe(201)
        ->and($chiuso->json('data'))->toMatchArray(['stato' => 'chiuso', 'velocita' => 5, 'velocita_unita' => 'punti', 'obiettivo_raggiunto' => true])
        ->and($chiuso->json('data.chiuso_il'))->not->toBeNull();

    $sprintDelleSchede = fn (string $scheda) => alFinto('GET', "/v1/board/schede/{$scheda}", null, $gettone)->json('data.sprint_id');

    expect($sprintDelleSchede($resta['id']))->toBe($dopo['id'])
        ->and($sprintDelleSchede($esce['id']))->toBeNull()
        ->and($sprintDelleSchede($fatta['id']))->toBe($attivo['id'])
        ->and($sprintDelleSchede($fuori['id']))->toBeNull()
        ->and(alFinto('GET', "/v1/board/schede/{$resta['id']}", null, $gettone)->json('data.nello_sprint_dal'))->not->toBeNull()
        ->and(alFinto('GET', "/v1/board/schede/{$esce['id']}", null, $gettone)->json('data.nello_sprint_dal'))->toBeNull();

    expect(alFinto('POST', "/v1/board/sprint/{$attivo['id']}/chiusura", ['schede' => []], $gettone)->json('codice'))->toBe('sprint_non_attivo');
});

it('la chiusura senza punti conta le schede completate, in unità schede; senza schede la velocità è 0', function () {
    [$finto, $studio, $board, $gettone] = conLaBoardScrum();
    $attivo = $finto->sprint($studio, $board, 'In corso', 'attivo');
    foreach (['Una', 'Due'] as $titolo) {
        $scheda = $finto->scheda($studio, $titolo, $board['id'], sprint: $attivo['id']);
        alFinto('POST', "/v1/board/schede/{$scheda['id']}/completamento", null, $gettone);
    }

    $chiuso = alFinto('POST', "/v1/board/sprint/{$attivo['id']}/chiusura", ['schede' => []], $gettone);

    expect($chiuso->json('data'))->toMatchArray(['velocita' => 2, 'velocita_unita' => 'schede', 'obiettivo_raggiunto' => null]);
});

it('la chiusura rifiuta una scheda aperta non nominata (#/schede), una che non è dello sprint o nominata due volte (#/schede/N/id) e sprint_dopo senza uno sprint pianificato', function () {
    [$finto, $studio, $board, $gettone] = conLaBoardScrum();
    $attivo = $finto->sprint($studio, $board, 'In corso', 'attivo');
    $una = $finto->scheda($studio, 'Una', $board['id'], sprint: $attivo['id']);
    $estranea = $finto->scheda($studio, 'Estranea', $board['id']);
    $chiudi = fn (array $corpo) => alFinto('POST', "/v1/board/sprint/{$attivo['id']}/chiusura", $corpo, $gettone);

    $manca = $chiudi(['schede' => []]);
    $nonSua = $chiudi(['schede' => [['id' => $una['id'], 'destinazione' => 'backlog'], ['id' => $estranea['id'], 'destinazione' => 'backlog']]]);
    $doppia = $chiudi(['schede' => [['id' => $una['id'], 'destinazione' => 'backlog'], ['id' => $una['id'], 'destinazione' => 'backlog']]]);
    $senzaDopo = $chiudi(['schede' => [['id' => $una['id'], 'destinazione' => 'sprint_dopo']]]);

    expect([$manca->status(), $manca->json('errors.0.pointer'), $manca->json('errors.0.regola')])->toBe([422, '#/schede', 'obbligatorio'])
        ->and([$nonSua->status(), $nonSua->json('errors.0.pointer'), $nonSua->json('errors.0.regola')])->toBe([422, '#/schede/1/id', 'non_trovato'])
        ->and($doppia->json('errors.0.pointer'))->toBe('#/schede/1/id')
        ->and([$senzaDopo->status(), $senzaDopo->json('errors.0.pointer'), $senzaDopo->json('errors.0.regola')])->toBe([422, '#/schede/0/destinazione', 'valore_non_ammesso'])
        ->and($chiudi(['schede' => [['id' => $una['id'], 'destinazione' => 'altrove']]])->json('errors.0.pointer'))->toBe('#/schede/0/destinazione')
        ->and($chiudi([])->json('errors.0.pointer'))->toBe('#/schede');

    // Niente è cambiato: lo sprint è ancora attivo e la scheda ci sta.
    expect(alFinto('GET', "/v1/board/schede/{$una['id']}", null, $gettone)->json('data.sprint_id'))->toBe($attivo['id']);
});

it('le schede portano sprint_id e nello_sprint_dal nelle letture; scheda() rifiuta uno sprint chiuso o di un\'altra board', function () {
    [$finto, $studio, $board, $gettone] = conLaBoardScrum();
    $attivo = $finto->sprint($studio, $board, 'In corso', 'attivo');
    $chiuso = $finto->sprint($studio, $board, 'Passato', 'chiuso');
    $dentro = $finto->scheda($studio, 'Dentro', $board['id'], sprint: $attivo['id']);
    $fuori = $finto->scheda($studio, 'Fuori', $board['id']);

    $mostrata = alFinto('GET', "/v1/board/schede/{$dentro['id']}", null, $gettone)->json('data');
    $elenco = collect(alFinto('GET', "/v1/board/schede?board_id={$board['id']}", null, $gettone)->json('data'))->keyBy('id');

    expect($mostrata['sprint_id'])->toBe($attivo['id'])->and($mostrata['nello_sprint_dal'])->toMatch('/^\d{4}-\d{2}-\d{2}T.*Z$/')
        ->and($elenco[$dentro['id']]['sprint_id'])->toBe($attivo['id'])
        ->and($elenco[$fuori['id']]['sprint_id'])->toBeNull()->and($elenco[$fuori['id']]['nello_sprint_dal'])->toBeNull();

    expect(fn () => $finto->scheda($studio, 'In un chiuso', $board['id'], sprint: $chiuso['id']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finto->scheda($studio, 'In un\'altra board', 'altra-board', sprint: $attivo['id']))->toThrow(InvalidArgumentException::class);
});

it('sprint() vuole una board Scrum e al più uno sprint attivo e uno pianificato per board', function () {
    [$finto, $studio, $board] = conLaBoardScrum();
    $altra = $finto->board($studio, 'Senza metodo');
    $finto->sprint($studio, $board, 'Attivo', 'attivo');
    $finto->sprint($studio, $board, 'Pianificato');

    expect(fn () => $finto->sprint($studio, $altra, 'X'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finto->sprint($studio, $board, 'Un altro attivo', 'attivo'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finto->sprint($studio, $board, 'Un altro pianificato'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finto->sprint($studio, $board, 'Un altro chiuso', 'chiuso'))->not->toThrow(InvalidArgumentException::class);
});

it('con l\'app pm spenta ogni metodo è 403 app_non_attiva; la Idempotency-Key ripete lo stesso 201 senza un secondo sprint', function () {
    [, , $board, $gettone] = conLaBoardScrum();
    $corpo = corpoDelloSprint($board);
    $uguale = fn (string $chiave, array $corpo) => alFinto('POST', '/v1/board/sprint', $corpo, $gettone, null, ['Idempotency-Key' => $chiave]);

    $prima = $uguale('chiave-1', $corpo);
    $seconda = $uguale('chiave-1', $corpo);
    $diversa = $uguale('chiave-1', corpoDelloSprint($board, ['nome' => 'Altro']));

    expect($prima->status())->toBe(201)->and($seconda->status())->toBe(201)->and($seconda->json('data.id'))->toBe($prima->json('data.id'))
        ->and($diversa->status())->toBe(422)->and($diversa->json('codice'))->toBe('chiave_idempotenza_riusata')
        ->and(count(alFinto('GET', "/v1/board/sprint?board_id={$board['id']}", null, $gettone)->json('data')))->toBe(1);

    expect(alFinto('PATCH', '/v1/app/pm', ['stato' => 'disponibile'], $gettone)->status())->toBe(200);

    expect(alFinto('GET', "/v1/board/sprint?board_id={$board['id']}", null, $gettone)->json('codice'))->toBe('app_non_attiva')
        ->and(alFinto('POST', '/v1/board/sprint', $corpo, $gettone)->json('codice'))->toBe('app_non_attiva');
});
