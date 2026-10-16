<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// Le tre letture della Dashboard di zr-home (#1563): board.cartelle.elenca, board.board.elenca e board.schede.elenca nel finto.
// Qui ciò che un frontend vede dal suo lato; che le risposte e gli errori siano quelli del backoffice, e che il gate 2 le passi,
// lo prova il FintoTest del backoffice (G12).

/**
 * Un workspace con `pm` attiva, due persone e il gettone di Anna: [finto, workspace, anna, bruno, gettone di Anna].
 *
 * @return array{BackofficeFinto, array<string, mixed>, array<string, mixed>, array<string, mixed>, string}
 */
function conLeBoard(bool $pm = true): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD, 'Anna');
    $bruno = $finto->persona('bruno@example.com', PASSWORD, 'Bruno');
    $studio = $finto->workspace('Studio', $anna);
    $finto->membro($studio, $bruno, 'membro');

    if ($pm) {
        $finto->attivaApp($studio, 'pm');
    }

    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');

    return [$finto, $studio, $anna, $bruno, $gettone];
}

it('board.cartelle.elenca dà le cartelle del workspace per nome, senza maiuscole né accenti, a cursore', function () {
    [$finto, $studio, , , $gettone] = conLeBoard();
    $finto->cartella($studio, 'Vendite', 'coral');
    $finto->cartella($studio, 'àmbito', 'sky');
    $finto->cartella($studio, 'Marketing');
    $altro = $finto->workspace('Altro', $finto->persona('carla@example.com', PASSWORD, 'Carla'));
    $finto->cartella($altro, 'Di un altro workspace');

    $prima = alFinto('GET', '/v1/board/cartelle?limite=2', null, $gettone);

    expect($prima->status())->toBe(200)
        ->and(array_column($prima->json('data'), 'nome'))->toBe(['àmbito', 'Marketing'])
        ->and(array_keys($prima->json('data.0')))->toBe(['id', 'nome', 'colore', 'creata_il', 'aggiornata_il', 'stato', 'nel_cestino_il', 'cancellata_il'])
        ->and($prima->json('data.0.colore'))->toBe('sky')
        ->and($prima->json('successivo'))->not->toBeNull();

    $seconda = alFinto('GET', '/v1/board/cartelle?limite=2&cursore='.urlencode($prima->json('successivo')), null, $gettone);

    expect(array_column($seconda->json('data'), 'nome'))->toBe(['Vendite'])
        ->and($seconda->json('successivo'))->toBeNull();
});

it('le letture board vogliono un workspace con pm: 403 app_non_attiva con l\'app spenta, gettone_senza_workspace con quello dell\'accesso', function (string $percorso) {
    [, , , , $gettone] = conLeBoard(pm: false);

    expect(alFinto('GET', $percorso, null, $gettone)->json('codice'))->toBe('app_non_attiva');

    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];

    expect(alFinto('GET', $percorso, null, $accesso)->json('codice'))->toBe('gettone_senza_workspace');
})->with(['/v1/board/cartelle', '/v1/board/board', '/v1/board/schede?assegnatario_id=x']);

it('board.board.elenca dà le board attive con la cartella e il conto delle schede; chiuse e cestino si chiedono con stato', function () {
    [$finto, $studio, , , $gettone] = conLeBoard();
    $marketing = $finto->cartella($studio, 'Marketing');
    $lancio = $finto->board($studio, 'Lancio', $marketing);
    $finto->board($studio, 'Archivio', $marketing, 'chiusa');
    $finto->board($studio, 'Vecchia', $marketing, 'cestino');
    $finto->scheda($studio, 'Uno', $lancio['id']);
    $archiviata = $finto->scheda($studio, 'Due', $lancio['id']);
    $finto->segnaScheda($archiviata, 'archiviata');

    $attive = alFinto('GET', '/v1/board/board', null, $gettone);

    expect(array_column($attive->json('data'), 'nome'))->toBe(['Lancio'])
        ->and(array_keys($attive->json('data.0')))->toBe(['id', 'nome', 'cartella_id', 'creata_il', 'aggiornata_il', 'numero_schede', 'descrizione', 'sfondo', 'stato', 'applica_regole', 'chiusa_il', 'nel_cestino_il', 'cancellata_il'])
        ->and($attive->json('data.0.cartella_id'))->toBe($marketing['id'])
        ->and($attive->json('data.0.numero_schede'))->toBe(1);

    $cestino = alFinto('GET', '/v1/board/board?stato=cestino', null, $gettone);

    expect($cestino->json('data.0.nome'))->toBe('Vecchia')
        ->and($cestino->json('data.0.nel_cestino_il'))->not->toBeNull()
        ->and($cestino->json('data.0.cancellata_il'))->not->toBeNull()
        ->and(alFinto('GET', '/v1/board/board?stato=chiusa', null, $gettone)->json('data.0.nome'))->toBe('Archivio');

    $storta = alFinto('GET', '/v1/board/board?stato=aperta', null, $gettone);

    expect($storta->status())->toBe(422)->and($storta->json('errors.0.parameter'))->toBe('stato');
});

it('board.schede.elenca con assegnatario_id dà le aperte per scadenza, le senza data in fondo, e il totale', function () {
    [$finto, $studio, $anna, $bruno, $gettone] = conLeBoard();
    $lancio = $finto->board($studio, 'Lancio');
    $chiusa = $finto->board($studio, 'Chiusa', null, 'chiusa');
    $senza = $finto->scheda($studio, 'Senza data', $lancio['id'], null, [$anna]);
    $tardi = $finto->scheda($studio, 'Tardi', $lancio['id'], '2026-10-30', [$anna]);
    $presto = $finto->scheda($studio, 'Presto', $lancio['id'], '2026-10-12', [$anna['id']]);
    $finto->scheda($studio, 'Di Bruno', $lancio['id'], '2026-10-01', [$bruno]);
    $finto->scheda($studio, 'In una board chiusa', $chiusa['id'], '2026-10-02', [$anna]);
    $fatta = $finto->scheda($studio, 'Fatta', $lancio['id'], '2026-10-03', [$anna]);
    $finto->segnaScheda($fatta, 'completata');

    $risposta = alFinto('GET', '/v1/board/schede?assegnatario_id='.$anna['id'], null, $gettone);

    expect($risposta->status())->toBe(200)
        ->and(array_column($risposta->json('data'), 'titolo'))->toBe(['Presto', 'Tardi', 'Senza data'])
        ->and($risposta->json('totale'))->toBe(3)
        ->and(array_keys($risposta->json('data.0')))->not->toContain('descrizione')
        ->and($risposta->json('data.0.scadenza'))->toBe('2026-10-12')
        ->and($risposta->json('data.0.assegnatari'))->toBe([$anna['id']]);

    $pagine = alFinto('GET', '/v1/board/schede?limite=2&assegnatario_id='.$anna['id'], null, $gettone);
    $dopo = alFinto('GET', '/v1/board/schede?limite=2&assegnatario_id='.$anna['id'].'&cursore='.urlencode($pagine->json('successivo')), null, $gettone);

    expect(array_column($pagine->json('data'), 'titolo'))->toBe(['Presto', 'Tardi'])
        ->and(array_column($dopo->json('data'), 'titolo'))->toBe(['Senza data'])
        ->and($dopo->json('successivo'))->toBeNull()
        ->and($dopo->json('totale'))->toBe(3);

    $completate = alFinto('GET', '/v1/board/schede?completate=true&assegnatario_id='.$anna['id'], null, $gettone);

    expect(array_column($completate->json('data'), 'titolo'))->toBe(['Fatta'])
        ->and($completate->json('totale'))->toBe(1);

    $dellaChiusa = alFinto('GET', '/v1/board/schede?assegnatario_id='.$anna['id'].'&board_id='.$chiusa['id'], null, $gettone);

    expect(array_column($dellaChiusa->json('data'), 'titolo'))->toBe(['In una board chiusa']);
    expect([$senza['id'], $tardi['id'], $presto['id']])->each->toBeString();
});

it('board.schede.elenca con board_id dà le schede non archiviate della board nell\'ordine del numero, senza totale', function () {
    [$finto, $studio, , , $gettone] = conLeBoard();
    $lancio = $finto->board($studio, 'Lancio');
    $altra = $finto->board($studio, 'Altra');
    $finto->scheda($studio, 'Uno', $lancio['id']);
    $due = $finto->scheda($studio, 'Due', $lancio['id']);
    $finto->scheda($studio, 'Tre', $lancio['id']);
    $finto->scheda($studio, 'Di un\'altra', $altra['id']);
    $finto->segnaScheda($due, 'archiviata');

    $risposta = alFinto('GET', '/v1/board/schede?board_id='.$lancio['id'], null, $gettone);

    expect(array_column($risposta->json('data'), 'numero'))->toBe([1, 3])
        ->and(array_key_exists('totale', $risposta->json()))->toBeFalse();
});

it('board.schede.elenca rifiuta con 422 sul parametro: niente board né persona, una board o una persona che non sono del workspace, completate senza persona', function (string $query, string $parametro) {
    [$finto, , , , $gettone] = conLeBoard();
    $altro = $finto->workspace('Altro', $finto->persona('carla@example.com', PASSWORD, 'Carla'));
    $query = str_replace('{altra_board}', $finto->board($altro, 'Di un altro')['id'], $query);

    $risposta = alFinto('GET', "/v1/board/schede{$query}", null, $gettone);

    expect($risposta->status())->toBe(422)
        ->and($risposta->json('codice'))->toBe('dati_non_validi')
        ->and(collect($risposta->json('errors'))->pluck('parameter')->all())->toContain($parametro);
})->with([
    'senza niente' => ['', 'board_id'],
    'una board di un altro workspace' => ['?board_id={altra_board}', 'board_id'],
    'una persona che non è del workspace' => ['?assegnatario_id=01k6zzzzzzzzzzzzzzzzzzzzzz', 'assegnatario_id'],
    'completate senza persona' => ['?board_id=x&completate=true', 'assegnatario_id'],
]);

it('board.schede.elenca non fa archiviate né numero: è un errore di chi usa il finto, non una risposta', function (string $query) {
    [$finto, $studio, , , $gettone] = conLeBoard();
    $lancio = $finto->board($studio, 'Lancio');

    expect(fn () => alFinto('GET', "/v1/board/schede?board_id={$lancio['id']}&{$query}", null, $gettone))->toThrow(LogicException::class);
})->with(['archiviate=true', 'numero=1']);

it('board.schede.elenca guarda insieme il limite e i parametri, come la richiesta del backoffice: due errori, il limite per primo', function () {
    [, , , , $gettone] = conLeBoard();

    $risposta = alFinto('GET', '/v1/board/schede?limite=500&assegnatario_id=01k6w6a2c4e6g8j0m2p4r6t8v2', null, $gettone);

    expect($risposta->status())->toBe(422)
        ->and(array_map(fn (array $errore) => [$errore['parameter'], $errore['regola']], $risposta->json('errors')))
        ->toBe([['limite', 'fuori_intervallo'], ['assegnatario_id', 'non_trovato']]);
});

it('board.schede.elenca ammette un limite fino a 1.000, come il backoffice (#1940), e rifiuta 1.001; gli altri elenchi restano a 100', function () {
    [$finto, $studio, , , $gettone] = conLeBoard();
    $lancio = $finto->board($studio, 'Lancio');
    $finto->scheda($studio, 'Uno', $lancio['id']);

    expect(alFinto('GET', '/v1/board/schede?board_id='.$lancio['id'].'&limite=1000', null, $gettone)->status())->toBe(200);

    $troppo = alFinto('GET', '/v1/board/schede?board_id='.$lancio['id'].'&limite=1001', null, $gettone);

    expect($troppo->status())->toBe(422)
        ->and($troppo->json('errors.0.parameter'))->toBe('limite')
        ->and($troppo->json('errors.0.detail'))->toBe('Il campo limite deve essere fra 1 e 1000.')
        ->and(alFinto('GET', '/v1/board/board?limite=101', null, $gettone)->status())->toBe(422)
        ->and(alFinto('GET', '/v1/board/schede?assegnatario_id=x&limite=101', null, $gettone)->json('errors.0.detail'))->toBe('Il campo limite deve essere fra 1 e 100.');
});

// #2218 (FI.1-FI.2): `ordina` di board.cartelle.elenca e board.board.elenca (#1574): `nome` (se manca), `aggiornata_il`, `-aggiornata_il`.
// Il verso vale per l'istante e per l'id insieme; il cursore vale solo per l'ordine che l'ha dato.

/** Tre cartelle e tre board con istanti diversi (più un pari istante): per nome vanno in un ordine, per aggiornamento in un altro. */
function conGliOrdini(): array
{
    [$finto, $studio, , , $gettone] = conLeBoard();
    $cartelle = $board = [];

    foreach ([['Zeta', '10:00:00'], ['Alfa', '10:01:00'], ['Beta', '10:02:00'], ['Gamma', '10:02:00']] as [$nome, $ora]) {
        Carbon\Carbon::setTestNow("2026-10-16 {$ora}");
        $cartelle[$nome] = $finto->cartella($studio, $nome);
        $board[$nome] = $finto->board($studio, $nome, $cartelle[$nome]);
    }

    Carbon\Carbon::setTestNow();

    // A pari istante (Beta e Gamma) decide l'id, nello stesso verso dell'istante.
    $pari = [$board['Beta']['id'], $board['Gamma']['id']];
    sort($pari);
    $pariCartelle = [$cartelle['Beta']['id'], $cartelle['Gamma']['id']];
    sort($pariCartelle);

    return [$gettone, $pariCartelle, $pari, array_column($cartelle, 'id', 'nome'), array_column($board, 'id', 'nome')];
}

it('board.cartelle.elenca e board.board.elenca ordinano per aggiornata_il, a pari istante per id nello stesso verso (FI.1)', function (string $percorso, int $dove) {
    $uso = conGliOrdini();
    $gettone = $uso[0];
    $pari = $uso[$dove];
    $ids = $uso[$dove + 2];
    $dopo = [$ids['Zeta'], $ids['Alfa']];

    $crescente = array_column(alFinto('GET', "{$percorso}?ordina=aggiornata_il", null, $gettone)->json('data'), 'id');
    $decrescente = array_column(alFinto('GET', "{$percorso}?ordina=-aggiornata_il", null, $gettone)->json('data'), 'id');

    expect($crescente)->toBe([...$dopo, ...$pari])
        ->and($decrescente)->toBe(array_reverse([...$dopo, ...$pari]));
})->with([
    'cartelle' => ['/v1/board/cartelle', 1],
    'board' => ['/v1/board/board', 2],
]);

it('senza ordina o con ordina=nome l\'ordine è quello di sempre; il cursore di un ordine per aggiornata_il riprende da dove era (FI.1)', function (string $percorso) {
    [$gettone] = conGliOrdini();

    $nomi = fn (string $query) => array_column(alFinto('GET', $percorso.$query, null, $gettone)->json('data'), 'nome');

    expect($nomi(''))->toBe(['Alfa', 'Beta', 'Gamma', 'Zeta'])
        ->and($nomi('?ordina=nome'))->toBe(['Alfa', 'Beta', 'Gamma', 'Zeta']);

    $prima = alFinto('GET', "{$percorso}?ordina=-aggiornata_il&limite=3", null, $gettone);
    $seconda = alFinto('GET', "{$percorso}?ordina=-aggiornata_il&limite=3&cursore=".urlencode($prima->json('successivo')), null, $gettone);

    expect(array_column($prima->json('data'), 'nome'))->toBe(array_slice($nomi('?ordina=-aggiornata_il'), 0, 3))
        ->and(array_column($prima->json('data'), 'nome')[2])->toBe('Alfa')
        ->and($seconda->json('data'))->toHaveCount(1)
        ->and($seconda->json('data.0.nome'))->toBe('Zeta')
        ->and($seconda->json('successivo'))->toBeNull();

    // Un cursore che cade fra due righe dello stesso istante (Beta e Gamma): dopo l'id più alto viene il più basso, poi le altre.
    $cursore = null;
    $ids = [];

    do {
        $pagina = alFinto('GET', "{$percorso}?ordina=-aggiornata_il&limite=1".($cursore === null ? '' : '&cursore='.urlencode($cursore)), null, $gettone);
        $ids[] = $pagina->json('data.0.nome');
        $cursore = $pagina->json('successivo');
    } while ($cursore !== null);

    $pari = array_slice($ids, 0, 2);
    $dalLista = array_column(alFinto('GET', "{$percorso}?ordina=-aggiornata_il", null, $gettone)->json('data'), 'nome');

    expect($ids)->toBe($dalLista)
        ->and($pari)->toEqualCanonicalizing(['Beta', 'Gamma'])
        ->and(array_slice($ids, 2))->toBe(['Alfa', 'Zeta']);
})->with(['/v1/board/cartelle', '/v1/board/board']);

it('un ordina fuori dai tre valori è 422 sul parametro; il cursore di un ordine con un altro è 422 sul cursore (FI.2)', function (string $percorso) {
    [$gettone] = conGliOrdini();

    foreach (['?ordina=creata_il', '?ordina=', '?ordina=-nome', '?ordina=AGGIORNATA_IL'] as $query) {
        $risposta = alFinto('GET', $percorso.$query, null, $gettone);

        expect($risposta->status())->toBe(422, $query)
            ->and($risposta->json('codice'))->toBe('dati_non_validi')
            ->and(collect($risposta->json('errors'))->pluck('parameter')->all())->toContain('ordina');
    }

    // Come nel backoffice, `limite`, `cursore` e `ordina` si guardano insieme: un solo 422 con gli errori di tutti.
    $insieme = alFinto('GET', "{$percorso}?ordina=creata_il&limite=0", null, $gettone);

    expect($insieme->status())->toBe(422)
        ->and(collect($insieme->json('errors'))->pluck('parameter')->sort()->values()->all())->toBe(['limite', 'ordina']);

    $perNome = alFinto('GET', "{$percorso}?limite=2", null, $gettone)->json('successivo');
    $perData = alFinto('GET', "{$percorso}?ordina=aggiornata_il&limite=2", null, $gettone)->json('successivo');

    foreach ([["?ordina=aggiornata_il&cursore=", $perNome], ["?ordina=-aggiornata_il&cursore=", $perData], ["?cursore=", $perData]] as [$query, $cursore]) {
        $risposta = alFinto('GET', $percorso.$query.urlencode($cursore), null, $gettone);

        expect($risposta->status())->toBe(422, $query)
            ->and(collect($risposta->json('errors'))->pluck('parameter')->all())->toContain('cursore');
    }
})->with(['/v1/board/cartelle', '/v1/board/board']);
