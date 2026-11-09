<?php

use Illuminate\Support\Str;
use Zeiras\Auth\Testing\BackofficeFinto;

// #3455-#3479 (Finanze, tappa 1, gruppi 1 e D): conti, caricamenti, categorie, movimenti, regole, spese fisse e conto del mese nel finto. Qui ciò che un frontend vede dal suo lato; che le risposte e gli errori siano quelli del backoffice lo prova
// il suo FintoTest.

/**
 * Un workspace con Finanze aperta e tre membri: Anna (proprietaria), Bruno (amministratore) e Carla (membro).
 *
 * @return array{0: BackofficeFinto, 1: array<string, string>, 2: array<string, mixed>}
 */
function studioConFinanze(bool $acceso = true): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio', $anna);
    $finto->membro($studio, $finto->persona('bruno@example.com', PASSWORD, 'Bruno'), 'amministratore');
    $finto->membro($studio, $finto->persona('carla@example.com', PASSWORD, 'Carla'), 'membro');

    if ($acceso) {
        $finto->conFinanze($studio);
    }

    $gettoni = [];

    foreach (['anna', 'bruno', 'carla'] as $nome) {
        $gettoni[$nome] = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto("{$nome}@example.com")['gettone']['gettone'])->json('data.gettone');
    }

    return [$finto, $gettoni, $studio];
}

/** @return array<string, mixed> */
function contoDelFinto(string $gettone, string $visibilita = 'comune', string $nome = 'Principale'): array
{
    $creato = alFinto('POST', '/v1/flow/conti', ['banca' => 'Banca Uno', 'nome' => $nome, 'tipo' => 'corrente', 'visibilita' => $visibilita], $gettone);
    expect($creato->status())->toBe(201, $creato->body());

    return $creato->json('data');
}

it('l\'app spenta chiude ogni metodo flow.* con 403 app_non_attiva, e un ospite non entra (FL.0)', function () {
    [, $gettoni] = studioConFinanze(false);

    foreach ([['GET', '/v1/flow/conti'], ['GET', '/v1/flow/categorie'], ['GET', '/v1/flow/regole'], ['GET', '/v1/flow/fisse?visibilita=comune'], ['GET', '/v1/flow/bilancio?visibilita=comune'], ['GET', '/v1/flow/movimenti?visibilita=comune']] as [$metodo, $percorso]) {
        $risposta = alFinto($metodo, $percorso, null, $gettoni['anna']);
        expect($risposta->status())->toBe(403, $percorso)->and($risposta->json('codice'))->toBe('app_non_attiva');
    }

    expect(alFinto('PATCH', '/v1/flow/bilancio/entrate', ['visibilita' => 'comune', 'importo' => 1], $gettoni['anna'])->json('codice'))->toBe('app_non_attiva')
        ->and(alFinto('GET', '/v1/flow/conti')->status())->toBe(401);
});

it('un conto nasce 201 con Location ed ETag, ne è titolare chi chiama, e non si crea oltre il tetto (FL.1)', function () {
    [, $gettoni] = studioConFinanze();

    $creato = alFinto('POST', '/v1/flow/conti', ['banca' => 'Banca Uno', 'nome' => 'Principale', 'tipo' => 'corrente', 'visibilita' => 'comune', 'saldo' => 125000, 'saldo_il' => '2026-11-01'], $gettoni['carla']);

    expect($creato->status())->toBe(201, $creato->body())
        ->and(array_keys($creato->json('data')))->toBe(['id', 'banca', 'nome', 'tipo', 'visibilita', 'titolare', 'iban_ultime4', 'valuta', 'saldo', 'saldo_il', 'movimenti_fino_al', 'formato_importazione', 'creato_il'])
        ->and($creato->json('data.titolare.nome'))->toBe('Carla')
        ->and($creato->json('data.valuta'))->toBe('EUR')
        ->and($creato->json('data.movimenti_fino_al'))->toBeNull()
        ->and($creato->header('Location'))->toBe('/v1/flow/conti/'.$creato->json('data.id'))
        ->and($creato->header('ETag'))->toStartWith('"');

    expect(alFinto('POST', '/v1/flow/conti', ['banca' => 'B', 'nome' => 'N', 'tipo' => 'sbagliato', 'visibilita' => 'comune', 'titolare_id' => 'x'], $gettoni['carla'])->json('errors.*.pointer'))->toBe(['#/tipo', '#/titolare_id']);

    for ($i = 1; $i < 50; $i++) {
        expect(alFinto('POST', '/v1/flow/conti', ['banca' => 'B', 'nome' => "C{$i}", 'tipo' => 'altro', 'visibilita' => 'comune'], $gettoni['anna'])->status())->toBe(201);
    }

    $oltre = alFinto('POST', '/v1/flow/conti', ['banca' => 'B', 'nome' => 'Uno di troppo', 'tipo' => 'altro', 'visibilita' => 'comune'], $gettoni['anna']);
    expect($oltre->status())->toBe(409)->and($oltre->json('codice'))->toBe('limite_raggiunto')->and($oltre->json('limite'))->toBe(50);
});

it('un conto personale lo vede solo il titolare, per ogni altro è 404; uno comune lo cambia solo il titolare (FL.2)', function () {
    [, $gettoni] = studioConFinanze();
    $personale = contoDelFinto($gettoni['carla'], 'personale', 'Mio');
    $comune = contoDelFinto($gettoni['carla'], 'comune', 'Insieme');

    expect(alFinto('GET', "/v1/flow/conti/{$personale['id']}", null, $gettoni['carla'])->status())->toBe(200)
        ->and(alFinto('GET', "/v1/flow/conti/{$personale['id']}", null, $gettoni['anna'])->status())->toBe(404)
        ->and(alFinto('PATCH', "/v1/flow/conti/{$personale['id']}", ['nome' => 'X'], $gettoni['anna'])->status())->toBe(404)
        ->and(alFinto('GET', "/v1/flow/conti/{$comune['id']}", null, $gettoni['anna'])->status())->toBe(200)
        ->and(alFinto('PATCH', "/v1/flow/conti/{$comune['id']}", ['nome' => 'X'], $gettoni['anna'])->json('codice'))->toBe('permesso_negato')
        ->and(alFinto('DELETE', "/v1/flow/conti/{$comune['id']}", null, $gettoni['anna'])->json('codice'))->toBe('permesso_negato')
        ->and(array_column(alFinto('GET', '/v1/flow/conti', null, $gettoni['anna'])->json('data'), 'nome'))->toBe(['Insieme'])
        ->and(array_column(alFinto('GET', '/v1/flow/conti', null, $gettoni['carla'])->json('data'), 'nome'))->toBe(['Insieme', 'Mio'])
        ->and(array_column(alFinto('GET', '/v1/flow/conti?visibilita=personale', null, $gettoni['carla'])->json('data'), 'nome'))->toBe(['Mio'])
        ->and(alFinto('GET', '/v1/flow/conti?q=mio', null, $gettoni['carla'])->json('errors.0'))->toMatchArray(['parameter' => 'q', 'regola' => 'campo_estraneo']);
});

it('la modifica del conto è un merge con If-Match: lo stesso corpo non cambia l\'ETag, uno vecchio è 412, un corpo vuoto 422 (FL.3)', function () {
    [, $gettoni] = studioConFinanze();
    $conto = contoDelFinto($gettoni['anna']);
    $etag = alFinto('GET', "/v1/flow/conti/{$conto['id']}", null, $gettoni['anna'])->header('ETag');

    $uguale = alFinto('PATCH', "/v1/flow/conti/{$conto['id']}", ['nome' => $conto['nome'], 'saldo' => null], $gettoni['anna']);
    expect($uguale->status())->toBe(200)->and($uguale->header('ETag'))->toBe($etag);

    $cambiato = alFinto('PATCH', "/v1/flow/conti/{$conto['id']}", ['nome' => 'Nuovo', 'iban_ultime4' => '1234', 'formato_importazione' => ['separatore' => ';']], $gettoni['anna'], null, ['If-Match' => $etag]);
    expect($cambiato->status())->toBe(200, $cambiato->body())
        ->and($cambiato->json('data.nome'))->toBe('Nuovo')
        ->and($cambiato->json('data.formato_importazione'))->toBe(['separatore' => ';'])
        ->and($cambiato->header('ETag'))->not->toBe($etag)
        ->and(alFinto('PATCH', "/v1/flow/conti/{$conto['id']}", ['nome' => 'Ancora'], $gettoni['anna'], null, ['If-Match' => $etag])->json('codice'))->toBe('precondizione_fallita')
        ->and(alFinto('PATCH', "/v1/flow/conti/{$conto['id']}", [], $gettoni['anna'])->json('errors.0.pointer'))->toBe('#')
        ->and(alFinto('PATCH', "/v1/flow/conti/{$conto['id']}", ['nome' => null], $gettoni['anna'])->status())->toBe(422);
});

it('eliminare un conto lo archivia con i suoi movimenti e caricamenti: da lì 404 (FL.4)', function () {
    [, $gettoni] = studioConFinanze();
    $conto = contoDelFinto($gettoni['anna']);
    $movimento = alFinto('POST', '/v1/flow/movimenti', ['conto_id' => $conto['id'], 'data' => '2026-11-02', 'importo' => -450, 'descrizione' => 'Caffè'], $gettoni['anna'])->json('data.id');

    expect(alFinto('DELETE', "/v1/flow/conti/{$conto['id']}", null, $gettoni['anna'])->status())->toBe(204)
        ->and(alFinto('GET', "/v1/flow/conti/{$conto['id']}", null, $gettoni['anna'])->status())->toBe(404)
        ->and(alFinto('GET', "/v1/flow/movimenti/{$movimento}", null, $gettoni['anna'])->status())->toBe(404)
        ->and(alFinto('GET', '/v1/flow/movimenti?visibilita=comune', null, $gettoni['anna'])->json('data'))->toBe([])
        ->and(alFinto('GET', "/v1/flow/movimenti?visibilita=comune&conto={$conto['id']}", null, $gettoni['anna'])->status())->toBe(404);
});

it('l\'elenco dei conti va a cursore, per banca e nome (FL.5)', function () {
    [, $gettoni] = studioConFinanze();

    foreach (['Zeta', 'Alfa', 'Beta'] as $nome) {
        contoDelFinto($gettoni['anna'], 'comune', $nome);
    }

    $prima = alFinto('GET', '/v1/flow/conti?limite=2', null, $gettoni['anna']);
    $seconda = alFinto('GET', '/v1/flow/conti?limite=2&cursore='.$prima->json('successivo'), null, $gettoni['anna']);

    expect(array_column($prima->json('data'), 'nome'))->toBe(['Alfa', 'Beta'])
        ->and(array_column($seconda->json('data'), 'nome'))->toBe(['Zeta'])
        ->and($seconda->json('successivo'))->toBeNull()
        ->and(alFinto('GET', '/v1/flow/conti?cursore=finto', null, $gettoni['anna'])->json('errors.0.parameter'))->toBe('cursore');
});

it('le categorie di sistema sono 31, a due livelli, con id e chiave stabili e nient\'altro (FL.6)', function () {
    [, $gettoni] = studioConFinanze();

    $categorie = alFinto('GET', '/v1/flow/categorie', null, $gettoni['carla']);

    expect($categorie->status())->toBe(200)
        ->and($categorie->json('data'))->toHaveCount(31)
        ->and(array_keys($categorie->json('data.0')))->toBe(['id', 'chiave', 'genitore_id', 'tipo'])
        ->and(array_column($categorie->json('data'), 'chiave'))->toContain('spesa', 'casa', 'casa.luce')
        ->and($categorie->json('data.0.genitore_id'))->toBeNull();
});

it('un movimento a mano prende la categoria dalla parola chiave, la persona la cambia e la regola non la riscrive più (FL.7)', function () {
    [, $gettoni] = studioConFinanze();
    $conto = contoDelFinto($gettoni['anna']);
    $categorie = array_column(alFinto('GET', '/v1/flow/categorie', null, $gettoni['anna'])->json('data'), 'id', 'chiave');

    $creato = alFinto('POST', '/v1/flow/movimenti', ['conto_id' => $conto['id'], 'data' => '2026-11-02', 'importo' => -4520, 'descrizione' => 'ESSELUNGA Milano', 'nota' => 'spesa settimanale'], $gettoni['bruno']);

    expect($creato->status())->toBe(201, $creato->body())
        ->and(array_keys($creato->json('data')))->toBe(['id', 'conto_id', 'visibilita', 'data', 'importo', 'valuta', 'descrizione', 'categoria_id', 'categoria_fonte', 'nota', 'escluso', 'escluso_motivo', 'escluso_origine', 'origine', 'importazione_id'])
        ->and($creato->json('data.categoria_id'))->toBe($categorie['spesa'])
        ->and($creato->json('data.categoria_fonte'))->toBe('sistema')
        ->and($creato->json('data.origine'))->toBe('manuale')
        ->and($creato->header('Location'))->toBe('/v1/flow/movimenti/'.$creato->json('data.id'));

    $id = $creato->json('data.id');
    $scelta = alFinto('PATCH', "/v1/flow/movimenti/{$id}", ['categoria_id' => $categorie['casa']], $gettoni['carla']);
    expect($scelta->json('data.categoria_fonte'))->toBe('utente')
        ->and(alFinto('PATCH', "/v1/flow/movimenti/{$id}", ['categoria_id' => null], $gettoni['carla'])->json('data.categoria_fonte'))->toBe('nessuna')
        ->and(alFinto('POST', '/v1/flow/movimenti', ['conto_id' => $conto['id'], 'data' => '2026-11-02', 'importo' => 0, 'descrizione' => 'x'], $gettoni['bruno'])->json('errors.0.pointer'))->toBe('#/importo');
});

it('escluso_origine: null se non escluso, manuale se lo esclude la persona; rimesso nei totali torna null; il filtro la prende (#3523)', function () {
    [, $gettoni] = studioConFinanze();
    $conto = contoDelFinto($gettoni['anna']);
    $id = alFinto('POST', '/v1/flow/movimenti', ['conto_id' => $conto['id'], 'data' => '2026-11-02', 'importo' => -100, 'descrizione' => 'Bar'], $gettoni['anna'])->json('data.id');
    $ids = fn (string $filtri) => array_column(alFinto('GET', "/v1/flow/movimenti?visibilita=comune{$filtri}", null, $gettoni['anna'])->json('data'), 'id');

    expect(alFinto('GET', "/v1/flow/movimenti/{$id}", null, $gettoni['anna'])->json('data.escluso_origine'))->toBeNull()
        ->and(alFinto('PATCH', "/v1/flow/movimenti/{$id}", ['escluso' => true, 'escluso_motivo' => 'altro'], $gettoni['anna'])->json('data.escluso_origine'))->toBe('manuale')
        ->and($ids('&escluso_origine=manuale'))->toBe([$id])
        ->and($ids('&escluso_origine=automatica'))->toBe([])
        ->and(alFinto('GET', '/v1/flow/movimenti?visibilita=comune&escluso_origine=tutte', null, $gettoni['anna'])->json('errors.0'))->toMatchArray(['parameter' => 'escluso_origine', 'regola' => 'valore_non_ammesso'])
        ->and(alFinto('PATCH', "/v1/flow/movimenti/{$id}", ['escluso' => false], $gettoni['anna'])->json('data.escluso_origine'))->toBeNull()
        ->and($ids('&escluso_origine=manuale'))->toBe([]);
});

it('escluso vuole il suo motivo; uno importato non cambia data, importo e descrizione (FL.8)', function () {
    [, $gettoni] = studioConFinanze();
    $conto = contoDelFinto($gettoni['anna']);
    $id = alFinto('POST', '/v1/flow/movimenti', ['conto_id' => $conto['id'], 'data' => '2026-11-02', 'importo' => -100, 'descrizione' => 'Bar'], $gettoni['anna'])->json('data.id');

    expect(alFinto('PATCH', "/v1/flow/movimenti/{$id}", ['escluso' => true], $gettoni['anna'])->json('errors.0'))->toMatchArray(['pointer' => '#/escluso_motivo', 'regola' => 'obbligatorio'])
        ->and(alFinto('PATCH', "/v1/flow/movimenti/{$id}", ['escluso_motivo' => 'giroconto'], $gettoni['anna'])->json('errors.0'))->toMatchArray(['pointer' => '#/escluso_motivo', 'regola' => 'campo_vietato'])
        ->and(alFinto('PATCH', "/v1/flow/movimenti/{$id}", ['escluso' => true, 'escluso_motivo' => 'giroconto'], $gettoni['anna'])->json('data'))->toMatchArray(['escluso' => true, 'escluso_motivo' => 'giroconto'])
        ->and(alFinto('PATCH', "/v1/flow/movimenti/{$id}", ['escluso' => false], $gettoni['anna'])->json('data.escluso_motivo'))->toBeNull();

    $caricato = alFinto('POST', "/v1/flow/conti/{$conto['id']}/importazioni", ['file_nome' => 'e.csv', 'righe' => [['data' => '2026-11-03', 'importo' => -900, 'descrizione' => 'Pizzeria']]], $gettoni['anna']);
    $importato = alFinto('GET', '/v1/flow/movimenti?visibilita=comune&da=2026-11-03', null, $gettoni['anna'])->json('data.0');

    expect($caricato->status())->toBe(201)
        ->and($importato['origine'])->toBe('importazione')
        ->and($importato['importazione_id'])->toBe($caricato->json('data.id'))
        ->and(alFinto('PATCH', "/v1/flow/movimenti/{$importato['id']}", ['importo' => -1], $gettoni['anna'])->json('errors.0'))->toMatchArray(['pointer' => '#/importo', 'regola' => 'campo_vietato'])
        ->and(alFinto('PATCH', "/v1/flow/movimenti/{$importato['id']}", ['nota' => 'ok'], $gettoni['anna'])->json('data.nota'))->toBe('ok')
        ->and(alFinto('DELETE', "/v1/flow/movimenti/{$importato['id']}", null, $gettoni['anna'])->json('codice'))->toBe('movimento_importato')
        ->and(alFinto('DELETE', "/v1/flow/movimenti/{$id}", null, $gettoni['anna'])->status())->toBe(204)
        ->and(alFinto('GET', "/v1/flow/movimenti/{$id}", null, $gettoni['anna'])->status())->toBe(404);
});

it('l\'elenco dei movimenti vuole la visibilita, rifiuta q, e un conto personale altrui è 404 (FL.9)', function () {
    [, $gettoni] = studioConFinanze();
    $personale = contoDelFinto($gettoni['carla'], 'personale', 'Mio');
    $comune = contoDelFinto($gettoni['anna']);

    foreach ([['data' => '2026-11-01', 'importo' => -100, 'descrizione' => 'Uno'], ['data' => '2026-11-03', 'importo' => 5000, 'descrizione' => 'Due'], ['data' => '2026-11-03', 'importo' => -300, 'descrizione' => 'Tre']] as $riga) {
        alFinto('POST', '/v1/flow/movimenti', ['conto_id' => $comune['id']] + $riga, $gettoni['anna']);
    }

    alFinto('POST', '/v1/flow/movimenti', ['conto_id' => $personale['id'], 'data' => '2026-11-04', 'importo' => -1, 'descrizione' => 'Privato'], $gettoni['carla']);

    $prima = alFinto('GET', '/v1/flow/movimenti?visibilita=comune&limite=2', null, $gettoni['anna']);
    $seconda = alFinto('GET', '/v1/flow/movimenti?visibilita=comune&limite=2&cursore='.$prima->json('successivo'), null, $gettoni['anna']);

    expect(array_column($prima->json('data'), 'data'))->toBe(['2026-11-03', '2026-11-03'])
        ->and(array_column($seconda->json('data'), 'descrizione'))->toBe(['Uno'])
        ->and(array_column(alFinto('GET', '/v1/flow/movimenti?visibilita=personale', null, $gettoni['carla'])->json('data'), 'descrizione'))->toBe(['Privato'])
        ->and(alFinto('GET', '/v1/flow/movimenti?visibilita=personale', null, $gettoni['anna'])->json('data'))->toBe([])
        ->and(alFinto('GET', "/v1/flow/movimenti?visibilita=personale&conto={$personale['id']}", null, $gettoni['anna'])->status())->toBe(404)
        ->and(alFinto('GET', "/v1/flow/movimenti?visibilita=comune&conto={$personale['id']}", null, $gettoni['carla'])->json('data'))->toBe([])
        ->and(alFinto('GET', '/v1/flow/movimenti', null, $gettoni['anna'])->json('errors.0'))->toMatchArray(['parameter' => 'visibilita', 'regola' => 'obbligatorio'])
        ->and(alFinto('GET', '/v1/flow/movimenti?visibilita=comune&q=uno', null, $gettoni['anna'])->json('errors.0'))->toMatchArray(['parameter' => 'q', 'regola' => 'campo_estraneo'])
        ->and(alFinto('GET', '/v1/flow/movimenti?visibilita=comune&escluso=true', null, $gettoni['anna'])->json('data'))->toBe([]);
});

it('la ricerca porta il testo nel corpo: senza maiuscole né accenti, in descrizione e nota; q nell\'URL è rifiutato (FL.10)', function () {
    [, $gettoni] = studioConFinanze();
    $conto = contoDelFinto($gettoni['anna']);
    alFinto('POST', '/v1/flow/movimenti', ['conto_id' => $conto['id'], 'data' => '2026-11-01', 'importo' => -100, 'descrizione' => 'Caffè Roma'], $gettoni['anna']);
    alFinto('POST', '/v1/flow/movimenti', ['conto_id' => $conto['id'], 'data' => '2026-11-02', 'importo' => -200, 'descrizione' => 'Treno', 'nota' => 'per il CAFFE di lavoro'], $gettoni['anna']);
    alFinto('POST', '/v1/flow/movimenti', ['conto_id' => $conto['id'], 'data' => '2026-11-03', 'importo' => -300, 'descrizione' => 'Altro'], $gettoni['anna']);

    $trovati = alFinto('POST', '/v1/flow/movimenti/ricerca', ['visibilita' => 'comune', 'q' => 'caffe', 'limite' => 1], $gettoni['bruno']);
    $seguente = alFinto('POST', '/v1/flow/movimenti/ricerca', ['visibilita' => 'comune', 'q' => 'caffe', 'limite' => 1, 'cursore' => $trovati->json('successivo')], $gettoni['bruno']);

    expect($trovati->status())->toBe(200)
        ->and(array_column($trovati->json('data'), 'descrizione'))->toBe(['Treno'])
        ->and(array_column($seguente->json('data'), 'descrizione'))->toBe(['Caffè Roma'])
        ->and($seguente->json('successivo'))->toBeNull()
        ->and(alFinto('POST', '/v1/flow/movimenti/ricerca?q=caffe', ['visibilita' => 'comune', 'q' => 'caffe'], $gettoni['bruno'])->json('errors.0'))->toMatchArray(['parameter' => 'q', 'regola' => 'campo_estraneo'])
        ->and(alFinto('POST', '/v1/flow/movimenti/ricerca', ['visibilita' => 'comune', 'q' => '--'], $gettoni['bruno'])->json('errors.0'))->toMatchArray(['pointer' => '#/q', 'regola' => 'obbligatorio'])
        ->and(alFinto('POST', '/v1/flow/movimenti/ricerca', ['visibilita' => 'comune', 'q' => 'c'], $gettoni['bruno'])->json('errors.0.pointer'))->toBe('#/q')
        ->and(alFinto('POST', '/v1/flow/movimenti/ricerca', ['visibilita' => 'comune', 'q' => 'caffe', 'cursore' => 'finto'], $gettoni['bruno'])->json('errors.0.pointer'))->toBe('#/cursore')
        ->and(alFinto('POST', '/v1/flow/movimenti/ricerca', ['visibilita' => 'comune', 'q' => 'caffe', 'altro' => 1], $gettoni['bruno'])->json('errors.0'))->toMatchArray(['pointer' => '#/altro', 'regola' => 'campo_estraneo']);
});

it('il caricamento: la prova non scrive, il vero dà 201, i doppioni si riconoscono e il saldo del file si dice (FL.11)', function () {
    [, $gettoni] = studioConFinanze();
    $conto = contoDelFinto($gettoni['anna']);
    $righe = [
        ['data' => '2026-10-01', 'importo' => 10000, 'descrizione' => 'Stipendio', 'saldo' => 10000],
        ['data' => '2026-10-02', 'importo' => -2000, 'descrizione' => 'ESSELUNGA', 'saldo' => 8000],
        ['data' => '2026-10-02', 'importo' => -2000, 'descrizione' => '  esselunga ', 'saldo' => 6000],
    ];

    $prova = alFinto('POST', "/v1/flow/conti/{$conto['id']}/importazioni", ['file_nome' => 'o.csv', 'prova' => true, 'righe' => $righe], $gettoni['carla']);
    expect($prova->status())->toBe(200, $prova->body())
        ->and($prova->json('data'))->toMatchArray(['id' => null, 'righe_lette' => 3, 'inserite' => 3, 'doppioni' => 0, 'periodo_da' => '2026-10-01', 'periodo_a' => '2026-10-02', 'saldo' => 'ok'])
        ->and($prova->json('data.campione.1'))->toMatchArray(['indice' => 1, 'esito' => 'nuova', 'categoria_fonte' => 'sistema'])
        ->and(alFinto('GET', '/v1/flow/movimenti?visibilita=comune', null, $gettoni['anna'])->json('data'))->toBe([])
        ->and(alFinto('GET', "/v1/flow/conti/{$conto['id']}/importazioni", null, $gettoni['anna'])->json('data'))->toBe([]);

    $vero = alFinto('POST', "/v1/flow/conti/{$conto['id']}/importazioni", ['file_nome' => 'o.csv', 'righe' => $righe], $gettoni['carla']);
    $ancora = alFinto('POST', "/v1/flow/conti/{$conto['id']}/importazioni", ['file_nome' => 'o.csv', 'righe' => [$righe[0], ['data' => '2026-10-05', 'importo' => 1, 'descrizione' => 'Nuova']]], $gettoni['carla']);

    expect($vero->status())->toBe(201)
        ->and($vero->json('data.id'))->toBeString()
        ->and($vero->json('data.inserite'))->toBe(3)
        ->and($ancora->json('data'))->toMatchArray(['righe_lette' => 2, 'inserite' => 1, 'doppioni' => 1, 'saldo' => 'assente'])
        ->and($ancora->json('data.campione.0.esito'))->toBe('doppione')
        ->and(alFinto('GET', "/v1/flow/conti/{$conto['id']}", null, $gettoni['anna'])->json('data.movimenti_fino_al'))->toBe('2026-10-05');

    $elenco = alFinto('GET', "/v1/flow/conti/{$conto['id']}/importazioni", null, $gettoni['anna'])->json('data');
    expect(array_keys($elenco[0]))->toBe(['id', 'file_nome', 'righe_lette', 'inserite', 'doppioni', 'periodo_da', 'periodo_a', 'creata_il', 'creata_da', 'annullata_il', 'movimenti_attivi'])
        ->and(array_column($elenco, 'id'))->toBe([$ancora->json('data.id'), $vero->json('data.id')])
        ->and($elenco[0]['creata_da']['nome'])->toBe('Carla');
});

it('il corpo di un caricamento è rifiutato per intero, anche dentro una riga, e il conto altrui è 404 (FL.12)', function () {
    [, $gettoni] = studioConFinanze();
    $personale = contoDelFinto($gettoni['carla'], 'personale', 'Mio');
    $riga = ['data' => '2026-10-01', 'importo' => 100, 'descrizione' => 'Uno'];

    expect(alFinto('POST', "/v1/flow/conti/{$personale['id']}/importazioni", ['file_nome' => 'o.csv', 'righe' => [$riga]], $gettoni['anna'])->status())->toBe(404)
        ->and(alFinto('GET', "/v1/flow/conti/{$personale['id']}/importazioni", null, $gettoni['anna'])->status())->toBe(404)
        ->and(alFinto('POST', "/v1/flow/conti/{$personale['id']}/importazioni", ['file_nome' => 'o.csv', 'righe' => [$riga, $riga + ['categoria' => 'x']]], $gettoni['carla'])->json('errors.0'))
        ->toMatchArray(['pointer' => '#/righe/1/categoria', 'regola' => 'campo_estraneo'])
        ->and(alFinto('POST', "/v1/flow/conti/{$personale['id']}/importazioni", ['file_nome' => 'o.csv', 'righe' => []], $gettoni['carla'])->status())->toBe(422)
        ->and(alFinto('POST', "/v1/flow/conti/{$personale['id']}/importazioni", ['file_nome' => 'o.csv'], $gettoni['carla'])->json('errors.0.pointer'))->toBe('#/righe');
});

it('annullare un caricamento archivia i suoi movimenti: 201 la prima volta, 200 con lo stesso esito dopo (FL.13)', function () {
    [, $gettoni] = studioConFinanze();
    $conto = contoDelFinto($gettoni['anna']);
    $caricamento = alFinto('POST', "/v1/flow/conti/{$conto['id']}/importazioni", ['file_nome' => 'o.csv', 'righe' => [['data' => '2026-10-01', 'importo' => 100, 'descrizione' => 'Uno'], ['data' => '2026-10-02', 'importo' => 200, 'descrizione' => 'Due']]], $gettoni['anna'])->json('data.id');
    $url = "/v1/flow/conti/{$conto['id']}/importazioni/{$caricamento}/annullamento";

    $primo = alFinto('POST', $url, null, $gettoni['carla']);
    $secondo = alFinto('POST', $url, [], $gettoni['carla']);

    expect($primo->status())->toBe(201, $primo->body())
        ->and(array_keys($primo->json('data')))->toBe(['id', 'annullata_il', 'movimenti_archiviati'])
        ->and($primo->json('data.movimenti_archiviati'))->toBe(2)
        ->and($secondo->status())->toBe(200)
        ->and($secondo->json('data'))->toBe($primo->json('data'))
        ->and(alFinto('GET', '/v1/flow/movimenti?visibilita=comune', null, $gettoni['anna'])->json('data'))->toBe([])
        ->and(alFinto('GET', "/v1/flow/conti/{$conto['id']}/importazioni", null, $gettoni['anna'])->json('data.0'))->toMatchArray(['movimenti_attivi' => 0])
        ->and(alFinto('GET', "/v1/flow/conti/{$conto['id']}/importazioni", null, $gettoni['anna'])->json('data.0.annullata_il'))->toBeString()
        ->and(alFinto('POST', "/v1/flow/conti/{$conto['id']}/importazioni/".strtolower((string) Str::ulid()).'/annullamento', null, $gettoni['anna'])->status())->toBe(404)
        ->and(alFinto('POST', $url, ['x' => 1], $gettoni['anna'])->json('errors.0.regola'))->toBe('campo_estraneo');
});

it('una regola vale per i movimenti che nascono dopo; comune per i conti comuni, personale per i personali del suo titolare, la più recente vince (FL.14)', function () {
    [, $gettoni] = studioConFinanze();
    $categorie = array_column(alFinto('GET', '/v1/flow/categorie', null, $gettoni['anna'])->json('data'), 'id', 'chiave');
    $comune = contoDelFinto($gettoni['anna']);
    $personale = contoDelFinto($gettoni['carla'], 'personale', 'Mio');
    $esistente = alFinto('POST', '/v1/flow/movimenti', ['conto_id' => $comune['id'], 'data' => '2026-11-01', 'importo' => -10, 'descrizione' => 'Zorzi Bottega'], $gettoni['anna'])->json('data');

    $crea = alFinto('POST', '/v1/flow/regole', ['testo' => 'ZORZI  Bottega', 'categoria_id' => $categorie['casa'], 'visibilita' => 'comune'], $gettoni['bruno']);
    expect($crea->status())->toBe(201, $crea->body())
        ->and(array_keys($crea->json('data')))->toBe(['id', 'testo', 'categoria_id', 'visibilita', 'creata_da', 'creata_il'])
        ->and($crea->json('data.creata_da'))->toBe(['id' => $crea->json('data.creata_da.id'), 'nome' => 'Bruno'])
        ->and($crea->header('Location'))->toBe('');

    alFinto('POST', '/v1/flow/regole', ['testo' => 'zorzi', 'categoria_id' => $categorie['spesa'], 'visibilita' => 'personale'], $gettoni['carla']);
    $nuovo = alFinto('POST', '/v1/flow/movimenti', ['conto_id' => $comune['id'], 'data' => '2026-11-02', 'importo' => -10, 'descrizione' => 'zorzi bottega centro'], $gettoni['anna'])->json('data');
    $suo = alFinto('POST', '/v1/flow/movimenti', ['conto_id' => $personale['id'], 'data' => '2026-11-02', 'importo' => -10, 'descrizione' => 'zorzi bottega centro'], $gettoni['carla'])->json('data');

    expect($esistente['categoria_id'])->toBeNull()
        ->and(alFinto('GET', "/v1/flow/movimenti/{$esistente['id']}", null, $gettoni['anna'])->json('data.categoria_id'))->toBeNull()
        ->and($nuovo['categoria_id'])->toBe($categorie['casa'])
        ->and($nuovo['categoria_fonte'])->toBe('regola')
        ->and($suo['categoria_id'])->toBe($categorie['spesa'])
        ->and($suo['categoria_fonte'])->toBe('regola');
});

it('le regole: un duplicato è 422 su #/testo, un testo troppo corto dopo la normalizzazione pure, la personale altrui non si vede (FL.15)', function () {
    [, $gettoni] = studioConFinanze();
    $casa = array_column(alFinto('GET', '/v1/flow/categorie', null, $gettoni['anna'])->json('data'), 'id', 'chiave')['casa'];
    $comune = alFinto('POST', '/v1/flow/regole', ['testo' => 'Pizzeria', 'categoria_id' => $casa, 'visibilita' => 'comune'], $gettoni['anna'])->json('data');
    $privata = alFinto('POST', '/v1/flow/regole', ['testo' => 'Segreto', 'categoria_id' => $casa, 'visibilita' => 'personale'], $gettoni['carla'])->json('data');

    expect(alFinto('POST', '/v1/flow/regole', ['testo' => 'PIZZERIA!', 'categoria_id' => $casa, 'visibilita' => 'comune'], $gettoni['bruno'])->json('errors.0'))->toMatchArray(['pointer' => '#/testo', 'regola' => 'duplicato'])
        ->and(alFinto('POST', '/v1/flow/regole', ['testo' => 'Pizzeria', 'categoria_id' => $casa, 'visibilita' => 'personale'], $gettoni['bruno'])->status())->toBe(201)
        ->and(alFinto('POST', '/v1/flow/regole', ['testo' => 'é.', 'categoria_id' => $casa, 'visibilita' => 'comune'], $gettoni['bruno'])->json('errors.0'))->toMatchArray(['pointer' => '#/testo', 'regola' => 'troppo_corto'])
        ->and(array_column(alFinto('GET', '/v1/flow/regole', null, $gettoni['anna'])->json('data'), 'testo'))->toBe(['Pizzeria'])
        ->and(array_column(alFinto('GET', '/v1/flow/regole?visibilita=personale', null, $gettoni['carla'])->json('data'), 'testo'))->toBe(['Segreto'])
        ->and(alFinto('DELETE', "/v1/flow/regole/{$privata['id']}", null, $gettoni['anna'])->status())->toBe(404)
        ->and(alFinto('DELETE', "/v1/flow/regole/{$comune['id']}", null, $gettoni['carla'])->json('codice'))->toBe('permesso_negato')
        ->and(alFinto('DELETE', "/v1/flow/regole/{$comune['id']}", null, $gettoni['bruno'])->status())->toBe(204)
        ->and(alFinto('DELETE', "/v1/flow/regole/{$comune['id']}", null, $gettoni['bruno'])->status())->toBe(404)
        ->and(alFinto('DELETE', "/v1/flow/regole/{$privata['id']}", null, $gettoni['carla'])->status())->toBe(204)
        ->and(alFinto('GET', '/v1/flow/regole?cursore=finto', null, $gettoni['anna'])->json('errors.0.parameter'))->toBe('cursore');
});

it('i freni contano per persona: oltre il tetto è 429 con Retry-After (FL.16)', function () {
    $this->freezeTime();
    [, $gettoni] = studioConFinanze();
    $casa = array_column(alFinto('GET', '/v1/flow/categorie', null, $gettoni['anna'])->json('data'), 'id', 'chiave')['casa'];

    for ($i = 0; $i < 60; $i++) {
        alFinto('POST', '/v1/flow/regole', ['testo' => "testo {$i}", 'categoria_id' => $casa, 'visibilita' => 'comune'], $gettoni['anna']);
    }

    $oltre = alFinto('POST', '/v1/flow/regole', ['testo' => 'uno di troppo', 'categoria_id' => $casa, 'visibilita' => 'comune'], $gettoni['anna']);

    expect($oltre->status())->toBe(429)
        ->and($oltre->json('codice'))->toBe('troppe_richieste')
        ->and($oltre->header('Retry-After'))->toBe('3600')
        ->and(alFinto('POST', '/v1/flow/regole', ['testo' => 'di un altro', 'categoria_id' => $casa, 'visibilita' => 'comune'], $gettoni['bruno'])->status())->toBe(201);
});

it('Idempotency-Key ripete la risposta di conti, movimenti e regole, ma non la prova di un caricamento (FL.17)', function () {
    [, $gettoni] = studioConFinanze();
    $corpo = ['banca' => 'B', 'nome' => 'Unico', 'tipo' => 'altro', 'visibilita' => 'comune'];

    $uno = alFinto('POST', '/v1/flow/conti', $corpo, $gettoni['anna'], null, ['Idempotency-Key' => 'chiave-1']);
    $due = alFinto('POST', '/v1/flow/conti', $corpo, $gettoni['anna'], null, ['Idempotency-Key' => 'chiave-1']);
    $altro = alFinto('POST', '/v1/flow/conti', ['nome' => 'Diverso'] + $corpo, $gettoni['anna'], null, ['Idempotency-Key' => 'chiave-1']);

    expect($uno->status())->toBe(201)
        ->and($due->json('data.id'))->toBe($uno->json('data.id'))
        ->and($due->header('Location'))->toBe($uno->header('Location'))
        ->and($uno->header('ETag'))->toStartWith('"')
        ->and($due->header('ETag'))->toBe('')
        ->and($altro->json('codice'))->toBe('chiave_idempotenza_riusata')
        ->and(alFinto('GET', '/v1/flow/conti', null, $gettoni['anna'])->json('data'))->toHaveCount(1);

    $prova = ['file_nome' => 'o.csv', 'prova' => true, 'righe' => [['data' => '2026-10-01', 'importo' => 1, 'descrizione' => 'Uno']]];
    $primaProva = alFinto('POST', "/v1/flow/conti/{$uno->json('data.id')}/importazioni", $prova, $gettoni['anna'], null, ['Idempotency-Key' => 'p']);
    $veraUno = alFinto('POST', "/v1/flow/conti/{$uno->json('data.id')}/importazioni", ['prova' => false] + $prova, $gettoni['anna'], null, ['Idempotency-Key' => 'p']);

    expect($primaProva->status())->toBe(200)->and($veraUno->status())->toBe(201);
});

it('le spese fisse: la vista è obbligatoria, la personale altrui non si vede né si toglie, prima_data serve se non è mensile, il tetto è 100 (FL.18)', function () {
    [, $gettoni] = studioConFinanze();
    $fissa = fn (array $campi = []) => $campi + ['visibilita' => 'comune', 'nome' => 'Lezioni di nuoto', 'importo' => 6000, 'frequenza_mesi' => 1];

    $comune = alFinto('POST', '/v1/flow/fisse', $fissa(['frequenza_mesi' => 12, 'prima_data' => '2027-03-12']), $gettoni['bruno']);
    $privata = alFinto('POST', '/v1/flow/fisse', $fissa(['nome' => 'Palestra', 'visibilita' => 'personale']), $gettoni['carla']);

    expect($comune->status())->toBe(201, $comune->body())
        ->and(array_keys($comune->json('data')))->toBe(['id', 'visibilita', 'nome', 'importo', 'frequenza_mesi', 'prima_data', 'creata_da', 'creata_il'])
        ->and($comune->json('data.creata_da'))->toBe(['id' => $comune->json('data.creata_da.id'), 'nome' => 'Bruno'])
        ->and($privata->json('data.prima_data'))->toBeNull()
        ->and(alFinto('GET', '/v1/flow/fisse', null, $gettoni['anna'])->json('errors.0'))->toMatchArray(['parameter' => 'visibilita', 'regola' => 'obbligatorio'])
        ->and(alFinto('GET', '/v1/flow/fisse?visibilita=comune&q=x', null, $gettoni['anna'])->json('errors.0.regola'))->toBe('campo_estraneo')
        ->and(array_column(alFinto('GET', '/v1/flow/fisse?visibilita=comune', null, $gettoni['anna'])->json('data'), 'nome'))->toBe(['Lezioni di nuoto'])
        ->and(alFinto('GET', '/v1/flow/fisse?visibilita=personale', null, $gettoni['anna'])->json('data'))->toBe([])
        ->and(array_column(alFinto('GET', '/v1/flow/fisse?visibilita=personale', null, $gettoni['carla'])->json('data'), 'nome'))->toBe(['Palestra'])
        ->and(alFinto('GET', '/v1/flow/fisse?visibilita=personale&cursore='.urlencode((string) alFinto('GET', '/v1/flow/fisse?visibilita=comune&limite=1', null, $gettoni['anna'])->json('successivo')), null, $gettoni['carla'])->status())->toBe(422);

    $senzaData = alFinto('POST', '/v1/flow/fisse', $fissa(['frequenza_mesi' => 3]), $gettoni['anna']);
    $sbagliata = alFinto('POST', '/v1/flow/fisse', $fissa(['frequenza_mesi' => 6]), $gettoni['anna']);
    expect($senzaData->json('errors'))->toHaveCount(1)->and($senzaData->json('errors.0'))->toMatchArray(['pointer' => '#/prima_data', 'regola' => 'obbligatorio'])
        ->and($sbagliata->json('errors'))->toHaveCount(1)->and($sbagliata->json('errors.0'))->toMatchArray(['pointer' => '#/frequenza_mesi', 'regola' => 'valore_non_ammesso'])
        ->and(alFinto('POST', '/v1/flow/fisse', $fissa(['importo' => 0]), $gettoni['anna'])->json('errors.0'))->toMatchArray(['pointer' => '#/importo', 'regola' => 'fuori_intervallo'])
        ->and(alFinto('POST', '/v1/flow/fisse', $fissa(['creata_da' => 'x']), $gettoni['anna'])->json('errors.0'))->toMatchArray(['pointer' => '#/creata_da', 'regola' => 'campo_estraneo']);

    expect(alFinto('DELETE', "/v1/flow/fisse/{$privata->json('data.id')}", null, $gettoni['anna'])->status())->toBe(404)
        ->and(alFinto('DELETE', "/v1/flow/fisse/{$comune->json('data.id')}", null, $gettoni['carla'])->json('codice'))->toBe('permesso_negato')
        ->and(alFinto('DELETE', "/v1/flow/fisse/{$comune->json('data.id')}", null, $gettoni['anna'])->status())->toBe(204)
        ->and(alFinto('DELETE', "/v1/flow/fisse/{$comune->json('data.id')}", null, $gettoni['anna'])->status())->toBe(404)
        ->and(alFinto('DELETE', "/v1/flow/fisse/{$privata->json('data.id')}", null, $gettoni['carla'])->status())->toBe(204);

    // Il tetto è della vista, non della persona: il freno è di sessanta all'ora per persona, quindi cinquanta e cinquanta.
    foreach (range(1, 100) as $n) {
        expect(alFinto('POST', '/v1/flow/fisse', $fissa(['nome' => "spesa {$n}"]), $gettoni[$n <= 50 ? 'anna' : 'bruno'])->status())->toBe(201);
    }

    $oltre = alFinto('POST', '/v1/flow/fisse', $fissa(['nome' => 'una di troppo']), $gettoni['anna']);
    expect($oltre->status())->toBe(409)->and($oltre->json('codice'))->toBe('limite_raggiunto')->and($oltre->json('limite'))->toBe(100);
});

it('il conto del mese: le medie dei tre mesi interi, le fisse mensili, quanto mettere da parte, e la correzione delle entrate è una per vista (FL.19)', function () {
    [, $gettoni] = studioConFinanze();
    $oggi = Carbon\CarbonImmutable::now('Europe/Rome')->startOfDay();
    $conto = alFinto('POST', '/v1/flow/conti', ['banca' => 'Banca Uno', 'nome' => 'Casa', 'tipo' => 'corrente', 'visibilita' => 'comune', 'saldo' => 100000], $gettoni['anna'])->json('data');
    $luce = Zeiras\Auth\Testing\Finto\FlowCategorie::id()[array_search('casa.luce', array_column(Zeiras\Auth\Testing\Finto\FlowCategorie::TUTTE, 'chiave'), true)];

    foreach ([3, 2, 1] as $indietro) {
        $giorno = $oggi->startOfMonth()->subMonthsNoOverflow($indietro)->addDays(4)->format('Y-m-d');
        expect(alFinto('POST', '/v1/flow/movimenti', ['conto_id' => $conto['id'], 'data' => $giorno, 'importo' => 300000, 'descrizione' => 'Stipendio'], $gettoni['anna'])->status())->toBe(201)
            ->and(alFinto('POST', '/v1/flow/movimenti', ['conto_id' => $conto['id'], 'data' => $giorno, 'importo' => -10000, 'descrizione' => 'Luce', 'categoria_id' => $luce], $gettoni['anna'])->status())->toBe(201);
    }

    alFinto('POST', '/v1/flow/fisse', ['visibilita' => 'comune', 'nome' => 'Nuoto', 'importo' => 6000, 'frequenza_mesi' => 1], $gettoni['anna']);
    alFinto('POST', '/v1/flow/fisse', ['visibilita' => 'comune', 'nome' => 'Assicurazione', 'importo' => 48000, 'frequenza_mesi' => 12, 'prima_data' => $oggi->addMonthsNoOverflow(4)->startOfMonth()->addDays(10)->format('Y-m-d')], $gettoni['bruno']);
    alFinto('POST', '/v1/flow/fisse', ['visibilita' => 'personale', 'nome' => 'Palestra', 'importo' => 999900, 'frequenza_mesi' => 1], $gettoni['carla']);

    $comune = alFinto('GET', '/v1/flow/bilancio?visibilita=comune', null, $gettoni['bruno']);
    $dati = $comune->json('data');

    expect($comune->status())->toBe(200, $comune->body())
        ->and(array_keys($dati))->toBe(['visibilita', 'movimenti_fino_al', 'mesi_di_storico', 'nel_conto', 'entrate_al_mese', 'spese_ogni_mese', 'metti_da_parte', 'ti_restano'])
        ->and($dati['mesi_di_storico'])->toBe(3)
        ->and($dati['nel_conto'])->toBe(['importo' => 100000, 'conti_senza_saldo' => 0])
        ->and($dati['entrate_al_mese'])->toBe(['importo' => 300000, 'origine' => 'rilevate', 'mesi' => 3])
        ->and($dati['spese_ogni_mese'])->toBe(['da_conti' => 10000, 'altre_spese_fisse' => 6000, 'totale' => 16000, 'mesi' => 3])
        ->and($dati['metti_da_parte']['importo'])->toBe(12000)->and($dati['metti_da_parte']['totale_scadenze'])->toBe(48000)
        ->and($dati['metti_da_parte']['scadenze'][0])->toMatchArray(['nome' => 'Assicurazione', 'importo' => 48000, 'mesi' => 4])
        ->and($dati['ti_restano'])->toBe(300000 - 16000 - 12000);

    // La personale di Carla non è quella di Anna, e la sua vista non vede i conti comuni.
    $sua = alFinto('GET', '/v1/flow/bilancio?visibilita=personale', null, $gettoni['carla'])->json('data');
    $dellAltra = alFinto('GET', '/v1/flow/bilancio?visibilita=personale', null, $gettoni['anna'])->json('data');
    expect($sua['spese_ogni_mese']['altre_spese_fisse'])->toBe(999900)->and($sua['mesi_di_storico'])->toBe(0)->and($sua['ti_restano'])->toBeNull()
        ->and($dellAltra['spese_ogni_mese']['altre_spese_fisse'])->toBe(0);

    // La correzione: la comune vale per tutti, la personale è di chi la scrive; null torna alle rilevate; zero è una correzione vera.
    $corretta = alFinto('PATCH', '/v1/flow/bilancio/entrate', ['visibilita' => 'comune', 'importo' => 250000], $gettoni['carla']);
    alFinto('PATCH', '/v1/flow/bilancio/entrate', ['visibilita' => 'personale', 'importo' => 0], $gettoni['carla']);

    expect($corretta->status())->toBe(200)->and($corretta->json('data'))->toBe(['importo' => 250000, 'origine' => 'corrette', 'mesi' => 3])
        ->and(alFinto('GET', '/v1/flow/bilancio?visibilita=comune', null, $gettoni['anna'])->json('data.entrate_al_mese.importo'))->toBe(250000)
        ->and(alFinto('GET', '/v1/flow/bilancio?visibilita=personale', null, $gettoni['carla'])->json('data.entrate_al_mese'))->toBe(['importo' => 0, 'origine' => 'corrette', 'mesi' => 0])
        ->and(alFinto('GET', '/v1/flow/bilancio?visibilita=personale', null, $gettoni['anna'])->json('data.entrate_al_mese.origine'))->toBe('rilevate')
        ->and(alFinto('PATCH', '/v1/flow/bilancio/entrate', ['visibilita' => 'comune', 'importo' => null], $gettoni['anna'])->json('data'))->toBe(['importo' => 300000, 'origine' => 'rilevate', 'mesi' => 3]);

    // Gli errori: ogni valore sbagliato sul suo pointer.
    $errore = fn (array $corpo) => alFinto('PATCH', '/v1/flow/bilancio/entrate', $corpo, $gettoni['anna'])->json('errors.0.pointer');
    expect(alFinto('GET', '/v1/flow/bilancio', null, $gettoni['anna'])->json('errors.0'))->toMatchArray(['parameter' => 'visibilita', 'regola' => 'obbligatorio'])
        ->and(alFinto('GET', '/v1/flow/bilancio?visibilita=comune&mese=2026-10', null, $gettoni['anna'])->json('errors.0.regola'))->toBe('campo_estraneo')
        ->and($errore(['importo' => 1]))->toBe('#/visibilita')->and($errore(['visibilita' => 'comune']))->toBe('#/importo')
        ->and($errore(['visibilita' => 'comune', 'importo' => -1]))->toBe('#/importo')->and($errore(['visibilita' => 'comune', 'importo' => '100']))->toBe('#/importo')
        ->and($errore(['visibilita' => 'comune', 'importo' => 1, 'origine' => 'corrette']))->toBe('#/origine');
});

it('il conto del mese con meno di tre mesi di storico non ha medie (FL.19)', function () {
    [, $gettoni] = studioConFinanze();
    $oggi = Carbon\CarbonImmutable::now('Europe/Rome')->startOfDay();
    $conto = alFinto('POST', '/v1/flow/conti', ['banca' => 'Banca Uno', 'nome' => 'Casa', 'tipo' => 'corrente', 'visibilita' => 'comune'], $gettoni['anna'])->json('data');
    alFinto('POST', '/v1/flow/movimenti', ['conto_id' => $conto['id'], 'data' => $oggi->startOfMonth()->subMonthsNoOverflow(2)->addDays(4)->format('Y-m-d'), 'importo' => 100000, 'descrizione' => 'Stipendio'], $gettoni['anna']);

    $dati = alFinto('GET', '/v1/flow/bilancio?visibilita=comune', null, $gettoni['anna'])->json('data');
    expect($dati['mesi_di_storico'])->toBe(2)->and($dati['entrate_al_mese'])->toBe(['importo' => null, 'origine' => 'rilevate', 'mesi' => 2])
        ->and($dati['spese_ogni_mese'])->toBe(['da_conti' => null, 'altre_spese_fisse' => 0, 'totale' => null, 'mesi' => 2])
        ->and($dati['nel_conto'])->toBe(['importo' => 0, 'conti_senza_saldo' => 1])->and($dati['ti_restano'])->toBeNull();
});
