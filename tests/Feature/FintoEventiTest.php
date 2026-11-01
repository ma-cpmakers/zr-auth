<?php

use Carbon\CarbonImmutable;
use Zeiras\Auth\Testing\BackofficeFinto;

// #2853 (zr-home, Registro attività): eventi.elenca e eventi.ultimo.mostra nel finto, come EventiController e UltimoEventoController
// del backoffice. Che le risposte e gli errori siano quelli del backoffice lo prova il FintoTest del backoffice.

/**
 * Un workspace di Anna con il suo gettone: [finto, workspace, anna, gettone del workspace, gettone dell'accesso].
 *
 * @return array{BackofficeFinto, array<string, mixed>, array<string, mixed>, string, string}
 */
function conUnWorkspaceDiEventi(string $nome = 'Studio'): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD, 'Anna');
    $studio = $finto->workspace($nome, $anna);
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso)->json('data.gettone');

    return [$finto, $studio, $anna, $gettone, $accesso];
}

test('un workspace nasce con il suo evento, e ultimo lo dà nella forma dello schema Evento (FE.1)', function () {
    $this->freezeTime();
    [, $studio, $anna, $gettone] = conUnWorkspaceDiEventi();

    $ultimo = alFinto('GET', '/v1/eventi/ultimo', null, $gettone);

    expect($ultimo->status())->toBe(200)
        ->and(array_keys($ultimo->json('data')))->toBe(['specversion', 'id', 'source', 'type', 'subject', 'time', 'sequence', 'autore', 'causa', 'datacontenttype', 'data'])
        ->and($ultimo->json('data'))->toMatchArray([
            'specversion' => '1.0',
            'source' => 'https://api.zeiras.com/workspace/'.$studio['id'],
            'type' => 'com.zeiras.workspace.creato',
            'subject' => '/v1/workspace',
            'time' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'sequence' => '000000000001',
            'autore' => $anna['id'],
            'causa' => 'io.workspace.crea',
            'datacontenttype' => 'application/json',
        ])
        ->and($ultimo->json('data.data.id'))->toBe($studio['id'])
        ->and($ultimo->json('data.id'))->toMatch('/^[0-9a-z]{26}$/');
});

test('anche un workspace nato da io.workspace.crea ha la sua nascita come primo evento (FE.1)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD, 'Anna');
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];

    $nuovo = alFinto('POST', '/v1/io/workspace', ['nome' => 'Nuovo'], $accesso);
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $nuovo->json('data.id')], $accesso)->json('data.gettone');
    $ultimo = alFinto('GET', '/v1/eventi/ultimo', null, $gettone);

    expect($nuovo->status())->toBe(201)
        ->and($ultimo->json('data.type'))->toBe('com.zeiras.workspace.creato')
        ->and($ultimo->json('data.sequence'))->toBe('000000000001')
        ->and($ultimo->json('data.data.id'))->toBe($nuovo->json('data.id'));
});

test('gli eventi messi dal test crescono di uno per workspace e la lista li dà in ordine, solo quelli del workspace del gettone (FE.2)', function () {
    [$finto, $studio, $anna, $gettone] = conUnWorkspaceDiEventi();
    $altro = $finto->workspace('Altro', $anna);
    $finto->registraEvento($altro, 'com.zeiras.workspace.membro.creato', '/v1/workspace/membri/'.$anna['id']);
    $due = $finto->registraEvento($studio, 'com.zeiras.workspace.membro.creato', '/v1/workspace/membri/'.$anna['id'], ['id' => $anna['id']], autore: $anna['id'], causa: 'workspace.membri.crea');
    $tre = $finto->registraEvento($studio, 'com.zeiras.workspace.modificato', '/v1/workspace');

    $lista = alFinto('GET', '/v1/eventi', null, $gettone);

    expect($lista->status())->toBe(200)
        ->and(array_column($lista->json('data'), 'sequence'))->toBe(['000000000001', '000000000002', '000000000003'])
        ->and(array_column($lista->json('data'), 'type'))->toBe(['com.zeiras.workspace.creato', 'com.zeiras.workspace.membro.creato', 'com.zeiras.workspace.modificato'])
        ->and($lista->json('data.1'))->toBe($due)
        ->and($lista->json('data.2.data'))->toBe([])
        ->and($lista->json('successivo'))->toBeNull()
        ->and(alFinto('GET', '/v1/eventi/ultimo', null, $gettone)->json('data'))->toBe($tre);
});

test('dopo parte dall\'evento dopo, con o senza gli zeri; limite e cursore impaginano (FE.3)', function () {
    [$finto, $studio, , $gettone] = conUnWorkspaceDiEventi();

    foreach (range(2, 5) as $numero) {
        $finto->registraEvento($studio, 'com.zeiras.workspace.modificato', '/v1/workspace', ['n' => $numero]);
    }

    $sequenze = fn ($risposta) => array_column($risposta->json('data'), 'sequence');

    expect($sequenze(alFinto('GET', '/v1/eventi?dopo=2', null, $gettone)))->toBe(['000000000003', '000000000004', '000000000005'])
        ->and($sequenze(alFinto('GET', '/v1/eventi?dopo=000000000002', null, $gettone)))->toBe(['000000000003', '000000000004', '000000000005'])
        ->and($sequenze(alFinto('GET', '/v1/eventi?dopo=5', null, $gettone)))->toBe([])
        ->and(alFinto('GET', '/v1/eventi?dopo=5', null, $gettone)->json('successivo'))->toBeNull();

    $prima = alFinto('GET', '/v1/eventi?limite=2', null, $gettone);
    $seconda = alFinto('GET', '/v1/eventi?limite=2&cursore='.urlencode($prima->json('successivo')), null, $gettone);
    $terza = alFinto('GET', '/v1/eventi?limite=2&cursore='.urlencode($seconda->json('successivo')), null, $gettone);

    expect($sequenze($prima))->toBe(['000000000001', '000000000002'])
        ->and($sequenze($seconda))->toBe(['000000000003', '000000000004'])
        ->and($sequenze($terza))->toBe(['000000000005'])
        ->and($terza->json('successivo'))->toBeNull();

    // Un `dopo` con la prima pagina e poi il cursore: il cursore porta il punto della pagina dopo.
    $dopoDue = alFinto('GET', '/v1/eventi?dopo=2&limite=1', null, $gettone);
    $dopoTre = alFinto('GET', '/v1/eventi?limite=1&cursore='.urlencode($dopoDue->json('successivo')), null, $gettone);

    expect($sequenze($dopoDue))->toBe(['000000000003'])->and($sequenze($dopoTre))->toBe(['000000000004']);
});

test('dopo che non è un sequence, o insieme al cursore, è 422 sul parametro dopo (FE.3, deve fallire se passa)', function () {
    [$finto, $studio, , $gettone] = conUnWorkspaceDiEventi();
    $finto->registraEvento($studio, 'com.zeiras.workspace.modificato', '/v1/workspace');
    $cursore = urlencode(alFinto('GET', '/v1/eventi?limite=1', null, $gettone)->json('successivo'));

    foreach (['abc', '-1', '1.5', '1234567890123', '0x1'] as $valore) {
        $risposta = alFinto('GET', '/v1/eventi?dopo='.$valore, null, $gettone);

        expect($risposta->status())->toBe(422, "dopo={$valore}")
            ->and($risposta->json('codice'))->toBe('dati_non_validi')
            ->and($risposta->json('errors.0.parameter'))->toBe('dopo')
            ->and($risposta->json('errors.0.regola'))->toBe('formato');
    }

    $insieme = alFinto('GET', "/v1/eventi?dopo=1&cursore={$cursore}", null, $gettone);

    expect($insieme->status())->toBe(422)
        ->and($insieme->json('errors.0.parameter'))->toBe('dopo')
        ->and($insieme->json('errors.0.regola'))->toBe('campo_vietato');
});

test('si leggono gli eventi dei 30 giorni dal loro istante; oltre l\'ultimo, o con l\'evento dopo scaduto, è 410 (FE.4)', function () {
    $this->freezeTime();
    [$finto, $studio] = conUnWorkspaceDiEventi();
    $inizio = CarbonImmutable::now();

    // I numeri seguono il tempo come nel backoffice: il 1 è nato adesso, il 2 un giorno dopo, il 3 a 30 giorni.
    $finto->registraEvento($studio, 'com.zeiras.workspace.modificato', '/v1/workspace', istante: $inizio->addDay());
    $finto->registraEvento($studio, 'com.zeiras.workspace.modificato', '/v1/workspace', istante: $inizio->addDays(30));

    // Dopo 31 giorni e mezzo il 1 e il 2 hanno più di 30 giorni, il 3 no.
    $this->travel(31)->days();
    $this->travel(12)->hours();
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso)->json('data.gettone');
    $sequenze = fn ($risposta) => array_column($risposta->json('data') ?? throw new RuntimeException($risposta->status().' '.$risposta->body()), 'sequence');

    // Senza dopo la lista parte dal più vecchio evento dei 30 giorni; l'ultimo è il 3.
    expect($sequenze(alFinto('GET', '/v1/eventi', null, $gettone)))->toBe(['000000000003'])
        ->and(alFinto('GET', '/v1/eventi/ultimo', null, $gettone)->json('data.sequence'))->toBe('000000000003');

    // dopo=1: l'evento subito dopo (il 2) ha più di 30 giorni → 410; dopo=2: il 3 vale.
    $scaduto = alFinto('GET', '/v1/eventi?dopo=1', null, $gettone);

    expect($scaduto->status())->toBe(410)->and($scaduto->json('codice'))->toBe('cursore_scaduto')
        ->and($sequenze(alFinto('GET', '/v1/eventi?dopo=2', null, $gettone)))->toBe(['000000000003'])
        // dopo = l'ultimo: lista vuota; oltre l'ultimo: 410.
        ->and(alFinto('GET', '/v1/eventi?dopo=3', null, $gettone)->status())->toBe(200)
        ->and($sequenze(alFinto('GET', '/v1/eventi?dopo=3', null, $gettone)))->toBe([])
        ->and(alFinto('GET', '/v1/eventi?dopo=4', null, $gettone)->status())->toBe(410)
        // Un parametro vuoto è assente: la lista di sempre, non un 410.
        ->and($sequenze(alFinto('GET', '/v1/eventi?dopo=', null, $gettone)))->toBe(['000000000003']);
});

test('ultimo dà l\'ultimo evento anche se ha più di 30 giorni, e quell\'evento non è nella lista (FE.4)', function () {
    $this->freezeTime();
    [, $studio] = conUnWorkspaceDiEventi();
    $this->travel(31)->days();
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso)->json('data.gettone');

    $ultimo = alFinto('GET', '/v1/eventi/ultimo', null, $gettone);

    expect($ultimo->status())->toBe(200)->and($ultimo->json('data.sequence'))->toBe('000000000001')
        ->and(alFinto('GET', '/v1/eventi', null, $gettone)->json('data'))->toBe([])
        ->and(alFinto('GET', '/v1/eventi?dopo=0', null, $gettone)->status())->toBe(410);
});

test('senza gettone 401, col gettone dell\'accesso 403 gettone_senza_workspace, e tutti i ruoli leggono (FE.5, deve fallire se l\'accesso legge)', function () {
    [$finto, $studio, , , $accesso] = conUnWorkspaceDiEventi();
    $bruno = $finto->persona('bruno@example.com', PASSWORD, 'Bruno');
    $finto->membro($studio, $bruno, 'membro');
    $gettoneDiBruno = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('bruno@example.com')['gettone']['gettone'])->json('data.gettone');

    foreach (['/v1/eventi', '/v1/eventi/ultimo'] as $percorso) {
        expect(alFinto('GET', $percorso)->status())->toBe(401)
            ->and(alFinto('GET', $percorso, null, $accesso)->status())->toBe(403)
            ->and(alFinto('GET', $percorso, null, $accesso)->json('codice'))->toBe('gettone_senza_workspace')
            ->and(alFinto('GET', $percorso, null, $gettoneDiBruno)->status())->toBe(200);
    }
});

test('registraEvento rifiuta un workspace o un autore che non sono del finto (FE.2)', function () {
    [$finto, $studio] = conUnWorkspaceDiEventi();

    expect(fn () => $finto->registraEvento(['id' => 'non-del-finto'], 'com.zeiras.x', '/v1/x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finto->registraEvento($studio, 'com.zeiras.x', '/v1/x', autore: 'non-del-finto'))->toThrow(InvalidArgumentException::class);
});
