<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// Sprint 28 (#1622, #1725): ricerca.elenca e ricerca.crea nel finto. Qui ciò che un frontend vede dal suo lato; che le risposte e
// gli errori siano quelli del backoffice, e che il gate 2 le passi, lo prova il FintoTest del backoffice (G12, G67).

/**
 * Un workspace con `pm` attiva e il gettone di Anna: [finto, workspace, gettone].
 *
 * @return array{BackofficeFinto, array<string, mixed>, string}
 */
function conLaRicerca(bool $pm = true): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD, 'Anna');
    $studio = $finto->workspace('Studio', $anna);

    if ($pm) {
        $finto->attivaApp($studio, 'pm');
    }

    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');

    return [$finto, $studio, $gettone];
}

it('ricerca.crea cerca nel corpo: schede prima, poi board e cartelle, ognuna con contesto e indirizzo', function () {
    [$finto, $studio, $gettone] = conLaRicerca();
    $cartella = $finto->cartella($studio, 'Piano commerciale');
    $board = $finto->board($studio, 'Piano di lancio', $cartella);
    $scheda = $finto->scheda($studio, 'Scrivere il piano di lancio', $board['id']);
    $finto->scheda($studio, 'Altro lavoro', $board['id']);

    $risposta = alFinto('POST', '/v1/ricerca', ['q' => 'Piano DI lancio'], $gettone);

    expect($risposta->status())->toBe(200)
        ->and(array_column($risposta->json('data'), 'tipo'))->toBe(['board.scheda', 'board.board'])
        ->and($risposta->json('data.0'))->toBe(['tipo' => 'board.scheda', 'id' => $scheda['id'], 'titolo' => 'Scrivere il piano di lancio', 'contesto' => ['board' => 'Piano di lancio', 'lista' => 'Da fare'], 'indirizzo' => '/v1/board/schede/'.$scheda['id']])
        ->and($risposta->json('data.1'))->toBe(['tipo' => 'board.board', 'id' => $board['id'], 'titolo' => 'Piano di lancio', 'contesto' => null, 'indirizzo' => '/v1/board/board/'.$board['id']]);

    $cartelle = alFinto('POST', '/v1/ricerca', ['q' => 'commerciale'], $gettone);

    expect($cartelle->json('data'))->toBe([['tipo' => 'board.cartelle', 'id' => $cartella['id'], 'titolo' => 'Piano commerciale', 'contesto' => null, 'indirizzo' => '/v1/board/cartelle/'.$cartella['id']]]);
});

it('ricerca.crea non mostra schede archiviate, board nel cestino né risorse di un altro workspace', function () {
    [$finto, $studio, $gettone] = conLaRicerca();
    $board = $finto->board($studio, 'Piano buono');
    $finto->board($studio, 'Piano vecchio', null, 'cestino');
    $archiviata = $finto->scheda($studio, 'Piano archiviato', $board['id']);
    $finto->segnaScheda($archiviata, 'archiviata');
    $altro = $finto->workspace('Altro', $finto->persona('carla@example.com', PASSWORD, 'Carla'));
    $finto->board($altro, 'Piano di Carla');
    $finto->scheda($altro, 'Piano di Carla', 'board-di-carla');

    $titoli = array_column(alFinto('POST', '/v1/ricerca', ['q' => 'piano'], $gettone)->json('data'), 'titolo');

    expect($titoli)->toBe(['Piano buono']);
});

it('ricerca.crea: le fonti nuove stanno nella prima pagina e il cursore vale per il suo termine', function () {
    [$finto, $studio, $gettone] = conLaRicerca();

    foreach (['Uno', 'Due', 'Tre'] as $nome) {
        $finto->cartella($studio, "Piano {$nome}");
    }

    $finto->scheda($studio, 'Piano di una scheda', 'board-di-prova');
    $prima = alFinto('POST', '/v1/ricerca', ['q' => 'piano', 'limite' => 2], $gettone);

    expect(array_column($prima->json('data'), 'tipo'))->toBe(['board.scheda', 'board.cartelle', 'board.cartelle'])
        ->and($prima->json('successivo'))->not->toBeNull();

    $seconda = alFinto('POST', '/v1/ricerca', ['q' => 'piano', 'limite' => 2, 'cursore' => $prima->json('successivo')], $gettone);

    expect(array_column($seconda->json('data'), 'titolo'))->toBe(['Piano Uno'])
        ->and($seconda->json('successivo'))->toBeNull();

    $altroTermine = alFinto('POST', '/v1/ricerca', ['q' => 'piani', 'cursore' => $prima->json('successivo')], $gettone);
    $delGet = alFinto('POST', '/v1/ricerca', ['q' => 'piano', 'cursore' => alFinto('GET', '/v1/ricerca?q=piano&limite=1', null, $gettone)->json('successivo')], $gettone);

    expect($altroTermine->status())->toBe(422)->and($altroTermine->json('errors.0.pointer'))->toBe('#/cursore')
        ->and($delGet->status())->toBe(422)->and($delGet->json('errors.0.pointer'))->toBe('#/cursore');
});

it('ricerca.crea rifiuta un q solo nella query, un campo estraneo e un limite fuori misura, sul loro pointer', function () {
    [, , $gettone] = conLaRicerca();

    $inQuery = alFinto('POST', '/v1/ricerca?q=piano', [], $gettone);
    $breve = alFinto('POST', '/v1/ricerca', ['q' => 'p'], $gettone);
    $estraneo = alFinto('POST', '/v1/ricerca', ['q' => 'piano', 'filtro' => 'x'], $gettone);
    $limite = alFinto('POST', '/v1/ricerca', ['q' => 'piano', 'limite' => 101], $gettone);

    expect($inQuery->status())->toBe(422)->and($inQuery->json('errors.0.pointer'))->toBe('#/q')
        ->and($breve->status())->toBe(422)->and($breve->json('errors.0.pointer'))->toBe('#/q')
        ->and($estraneo->status())->toBe(422)->and($estraneo->json('errors.0.pointer'))->toBe('#/filtro')
        ->and($limite->status())->toBe(422)->and($limite->json('errors.0.pointer'))->toBe('#/limite');
});

it('ricerca.crea ha un freno per persona: 120 al minuto, poi 429 troppe_richieste con Retry-After', function () {
    [, , $gettone] = conLaRicerca();

    foreach (range(1, 120) as $n) {
        expect(alFinto('POST', '/v1/ricerca', ['q' => 'piano'], $gettone)->status())->toBe(200);
    }

    $oltre = alFinto('POST', '/v1/ricerca', ['q' => 'piano'], $gettone);

    expect($oltre->status())->toBe(429)->and($oltre->json('codice'))->toBe('troppe_richieste')->and($oltre->header('Retry-After'))->not->toBe('');
});

it('la ricerca senza pm risponde 200 vuoto, mai 403; al gettone dell\'accesso 403 gettone_senza_workspace; senza gettone 401', function () {
    [, , $gettone] = conLaRicerca(pm: false);
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];

    expect(alFinto('POST', '/v1/ricerca', ['q' => 'piano'], $gettone)->json())->toBe(['data' => [], 'successivo' => null])
        ->and(alFinto('GET', '/v1/ricerca?q=piano', null, $gettone)->json('data'))->toBe([])
        ->and(alFinto('POST', '/v1/ricerca', ['q' => 'piano'], $accesso)->json('codice'))->toBe('gettone_senza_workspace')
        ->and(alFinto('GET', '/v1/ricerca?q=piano', null, $accesso)->json('codice'))->toBe('gettone_senza_workspace')
        ->and(alFinto('POST', '/v1/ricerca', ['q' => 'piano'])->status())->toBe(401);
});

it('ricerca.elenca (deprecato) dà solo board e cartelle, senza maiuscole né accenti, nella forma breve', function () {
    [$finto, $studio, $gettone] = conLaRicerca();
    $finto->cartella($studio, 'Àmbito');
    $finto->board($studio, 'Ambiente', null, 'chiusa');
    $finto->scheda($studio, 'Ambito di una scheda', 'board-di-prova');

    $risposta = alFinto('GET', '/v1/ricerca?q=AMB', null, $gettone);

    expect($risposta->status())->toBe(200)
        ->and(array_column($risposta->json('data'), 'titolo'))->toBe(['Ambiente', 'Àmbito'])
        ->and(array_keys($risposta->json('data.0')))->toBe(['tipo', 'id', 'titolo'])
        ->and(alFinto('GET', '/v1/ricerca?q=a', null, $gettone)->json('errors.0.parameter'))->toBe('q');
});

it('ricerca.crea trova i colleghi per nome anche con pm spenta, con ruolo e indirizzo, mai l\'email (#1774)', function () {
    [, $studio, $gettone] = conLaRicerca(pm: false);

    $risposta = alFinto('POST', '/v1/ricerca', ['q' => 'ANNA'], $gettone);
    $anna = $risposta->json('data.0');

    expect($risposta->status())->toBe(200)
        ->and(array_column($risposta->json('data'), 'tipo'))->toBe(['workspace.membro'])
        ->and(array_keys($anna))->toBe(['tipo', 'id', 'titolo', 'contesto', 'indirizzo'])
        ->and($anna['titolo'])->toBe('Anna')
        ->and($anna['contesto'])->toBe(['ruolo' => 'proprietario'])
        ->and($anna['indirizzo'])->toBe('/v1/workspace/membri/'.$anna['id']);
    // Per nome, non per email: un termine che sta solo nell'email non trova nessuno.
    expect(alFinto('POST', '/v1/ricerca', ['q' => 'example'], $gettone)->json('data'))->toBe([]);
    unset($studio);
});
