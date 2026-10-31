<?php

use Illuminate\Support\Carbon;
use Zeiras\Auth\Testing\BackofficeFinto;

// #2666 (zr-board, Area 4e): la pianificazione e il flusso di una board Scrumban nel finto, e l'orizzonte delle schede. Qui ciò che un frontend vede dal suo lato
// (le forme, il periodo, i 409 e i 422); che i testi e i codici siano quelli del backoffice lo provano lang/ copiata e il FintoTest del backoffice.

/**
 * Un workspace con `pm` attiva e una board Scrumban: [finto, workspace, board, gettone del workspace].
 *
 * @return array{BackofficeFinto, array<string, mixed>, array<string, mixed>, string}
 */
function conLaBoardScrumban(string $metodo = 'scrumban', string $stato = 'attiva'): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio', $anna);
    $finto->attivaApp($studio, 'pm');
    $board = $finto->board($studio, 'Prodotto', null, $stato, $metodo);
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');

    return [$finto, $studio, $board, $gettone];
}

it('la pianificazione risponde 201 con le schede seminate, senza Location, e le consuma: la seconda è 409 nessuna_pianificazione', function () {
    [$finto, , $board, $gettone] = conLaBoardScrumban();
    $mosse = [
        ['id' => '01k7a2c4e6g8j0m2p4r6t8v0w1', 'lista_id' => '01k6w4a2c6e8g0j2m4p6r8t0w2', 'posizione' => 4096],
        ['id' => '01k7a2c4e7g9j1m3p5r7t9w1x2', 'lista_id' => '01k6w4a2c6e8g0j2m4p6r8t0w2', 'posizione' => 5120],
    ];

    expect(alFinto('POST', "/v1/board/board/{$board['id']}/pianificazione", [], $gettone)->json('codice'))->toBe('nessuna_pianificazione');

    $finto->pianificazione($board, $mosse);
    $fatta = alFinto('POST', "/v1/board/board/{$board['id']}/pianificazione", [], $gettone);

    expect($fatta->status())->toBe(201)->and($fatta->header('Location'))->toBe('')->and($fatta->json('data'))->toBe($mosse);

    $seconda = alFinto('POST', "/v1/board/board/{$board['id']}/pianificazione", [], $gettone);

    expect($seconda->status())->toBe(409)->and($seconda->json('codice'))->toBe('nessuna_pianificazione');
});

it('la pianificazione: un campo nel corpo è 422 campo_estraneo, una board che non è Scrumban 409 board_senza_metodo, una chiusa 409 board_chiusa, una che non c\'è 404', function () {
    [$finto, $studio, $board, $gettone] = conLaBoardScrumban();
    $scrum = $finto->board($studio, 'Sprint', null, 'attiva', 'scrum');
    $chiusa = $finto->board($studio, 'Chiusa', null, 'chiusa', 'scrumban');

    $estraneo = alFinto('POST', "/v1/board/board/{$board['id']}/pianificazione", ['soglia' => 3], $gettone);

    expect($estraneo->status())->toBe(422)->and($estraneo->json('codice'))->toBe('dati_non_validi')
        ->and($estraneo->json('errors.0'))->toMatchArray(['regola' => 'campo_estraneo', 'pointer' => '#/soglia'])
        ->and(alFinto('POST', "/v1/board/board/{$scrum['id']}/pianificazione", [], $gettone)->json('codice'))->toBe('board_senza_metodo')
        ->and(alFinto('POST', "/v1/board/board/{$chiusa['id']}/pianificazione", [], $gettone)->json('codice'))->toBe('board_chiusa')
        ->and(alFinto('POST', '/v1/board/board/01k6w9a1c3e5g7j9m1p3r5t7v9/pianificazione', [], $gettone)->status())->toBe(404);

    expect(fn () => $finto->pianificazione($scrum, [['id' => 'a', 'lista_id' => 'b', 'posizione' => 1]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finto->pianificazione($board, []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finto->pianificazione($board, [['id' => 'a']]))->toThrow(InvalidArgumentException::class);
});

it('il flusso senza semina è vuoto sugli ultimi 30 giorni; con solo `a` sono i 30 prima di lui; porta l\'ETag', function () {
    Carbon::setTestNow('2026-10-26 12:00:00');
    [, , $board, $gettone] = conLaBoardScrumban();

    $flusso = alFinto('GET', "/v1/board/board/{$board['id']}/flusso", null, $gettone);

    expect($flusso->status())->toBe(200)
        ->and(array_keys($flusso->json('data')))->toBe(['da', 'a', 'lead_time', 'throughput', 'cumulativo', 'senza_storia'])
        ->and($flusso->json('data'))->toMatchArray(['da' => '2026-09-27', 'a' => '2026-10-26', 'lead_time' => ['mediana' => null, 'p85' => null, 'schede' => 0], 'senza_storia' => 0])
        ->and($flusso->json('data.cumulativo.giorni'))->toHaveCount(30)
        ->and($flusso->json('data.throughput.0'))->toBe(['settimana' => '2026-09-21', 'completate' => 0])
        ->and($flusso->header('ETag'))->toMatch('/^"[0-9a-f]{32}"$/')
        ->and(alFinto('GET', "/v1/board/board/{$board['id']}/flusso?a=2026-10-10", null, $gettone)->json('data.da'))->toBe('2026-09-11');

    Carbon::setTestNow();
});

it('il flusso dà i numeri seminati dal test per il periodo chiesto, e l\'ETag cambia con il periodo', function () {
    Carbon::setTestNow('2026-10-26 12:00:00');
    [$finto, , $board, $gettone] = conLaBoardScrumban();
    $seme = [
        'lead_time' => ['mediana' => 6.5, 'p85' => 9, 'schede' => 4],
        'throughput' => [['settimana' => '2026-10-19', 'completate' => 4]],
        'cumulativo' => ['liste' => [['id' => 'l1', 'nome' => 'Backlog']], 'giorni' => [['data' => '2026-10-20', 'liste' => [['lista_id' => 'l1', 'schede' => 12]]]]],
        'senza_storia' => 1,
    ];
    $finto->flusso($board, $seme);

    $a = alFinto('GET', "/v1/board/board/{$board['id']}/flusso?da=2026-10-20&a=2026-10-22", null, $gettone);
    $b = alFinto('GET', "/v1/board/board/{$board['id']}/flusso?da=2026-10-20&a=2026-10-23", null, $gettone);

    expect($a->json('data'))->toBe(['da' => '2026-10-20', 'a' => '2026-10-22', ...$seme])
        ->and($a->header('ETag'))->not->toBe($b->header('ETag'));

    expect(fn () => $finto->flusso($board, ['altro' => 1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finto->flusso($board, ['lead_time' => ['mediana' => 1]]))->toThrow(InvalidArgumentException::class);

    Carbon::setTestNow();
});

it('il periodo del flusso: 90 giorni sì e 91 no, da dopo a no, una data che non è YYYY-MM-DD no (422 sul parametro); una board che non è Scrumban è 409, una che non c\'è 404', function () {
    Carbon::setTestNow('2026-10-26 12:00:00');
    [$finto, $studio, $board, $gettone] = conLaBoardScrumban();
    $scrum = $finto->board($studio, 'Sprint', null, 'attiva', 'scrum');

    expect(alFinto('GET', "/v1/board/board/{$board['id']}/flusso?da=2026-07-29&a=2026-10-26", null, $gettone)->json('data.cumulativo.giorni'))->toHaveCount(90);

    foreach ([['da=2026-07-28&a=2026-10-26', 'da'], ['da=2026-10-20&a=2026-10-19', 'da'], ['da=ieri', 'da'], ['a=2026-13-40', 'a'], ['da=2026-10-01&a=', 'a']] as [$query, $parametro]) {
        $rifiutato = alFinto('GET', "/v1/board/board/{$board['id']}/flusso?{$query}", null, $gettone);

        expect($rifiutato->status())->toBe(422)->and($rifiutato->json('codice'))->toBe('dati_non_validi')->and($rifiutato->json('errors.0.parameter'))->toBe($parametro);
    }

    expect(alFinto('GET', "/v1/board/board/{$scrum['id']}/flusso", null, $gettone)->json('codice'))->toBe('board_senza_metodo')
        ->and(alFinto('GET', '/v1/board/board/01k6w9a1c3e5g7j9m1p3r5t7v9/flusso', null, $gettone)->status())->toBe(404);

    Carbon::setTestNow();
});

it('con l\'app pm spenta pianificazione e flusso sono 403 app_non_attiva; l\'orizzonte seminato con scheda() è nelle letture, null per le altre', function () {
    [$finto, $studio, $board, $gettone] = conLaBoardScrumban();
    $conOrizzonte = $finto->scheda($studio, 'Da fare fra tre mesi', $board['id'], orizzonte: '3_mesi');
    $senza = $finto->scheda($studio, 'Senza orizzonte', $board['id']);

    $elenco = alFinto('GET', "/v1/board/schede?board_id={$board['id']}", null, $gettone)->json('data');
    $orizzonti = array_column($elenco, 'orizzonte', 'id');

    expect($orizzonti[$conOrizzonte['id']])->toBe('3_mesi')->and($orizzonti[$senza['id']])->toBeNull()
        ->and(alFinto('GET', "/v1/board/schede/{$conOrizzonte['id']}", null, $gettone)->json('data.orizzonte'))->toBe('3_mesi');

    expect(fn () => $finto->scheda($studio, 'Sbagliata', $board['id'], orizzonte: '2_mesi'))->toThrow(InvalidArgumentException::class);

    expect(alFinto('PATCH', '/v1/app/pm', ['stato' => 'disponibile'], $gettone)->status())->toBe(200);

    expect(alFinto('POST', "/v1/board/board/{$board['id']}/pianificazione", [], $gettone)->json('codice'))->toBe('app_non_attiva')
        ->and(alFinto('GET', "/v1/board/board/{$board['id']}/flusso", null, $gettone)->json('codice'))->toBe('app_non_attiva');
});
