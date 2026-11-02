<?php

use Illuminate\Http\Client\Response;
use Zeiras\Auth\Testing\BackofficeFinto;

// #2609 (zr-board #2459): i collegamenti di un to-do a un record del CRM nel finto — il «con chi» e il «dove», `crm` nelle risposte del to-do,
// i tetti per ruolo, il doppione, il motivo di un record che non si legge (anche `unito`, con `unito_in`). Qui ciò che un frontend vede dal
// suo lato; che le risposte e gli errori siano quelli del backoffice lo prova il FintoTest del backoffice.

/**
 * Un workspace con `pm` e `crm` attive e due membri: [finto, gettoni per nome].
 *
 * @return array{BackofficeFinto, array<string, string>}
 */
function studioTodoECrm(): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD, 'Anna');
    $studio = $finto->workspace('Studio', $anna);
    $finto->membro($studio, $finto->persona('bruno@example.com', PASSWORD, 'Bruno'), 'membro');
    $finto->attivaApp($studio, 'pm', 'crm');
    $gettoni = [];

    foreach (['anna', 'bruno'] as $nome) {
        $gettoni[$nome] = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto("{$nome}@example.com")['gettone']['gettone'])->json('data.gettone');
    }

    return [$finto, $gettoni];
}

/** Un contatto del CRM: il suo id. */
function contattoPerIlTodo(string $gettone, string $nome, string $cognome = ''): string
{
    $risposta = alFinto('POST', '/v1/crm/persone', ['nome' => $nome, ...($cognome === '' ? [] : ['cognome' => $cognome])], $gettone);
    expect($risposta->status())->toBe(201);

    return $risposta->json('data.id');
}

/** Il collegamento di un to-do a un record; con `$stato` ne controlla lo stato. */
function collegaIlTodo(string $gettone, string $todo, string $ruolo, string $tipo, string $id, ?int $stato = null): Response
{
    $risposta = alFinto('POST', "/v1/board/todo/elementi/{$todo}/crm", ['ruolo' => $ruolo, 'tipo' => $tipo, 'id' => $id], $gettone);

    if ($stato !== null) {
        expect($risposta->status())->toBe($stato, $risposta->body());
    }

    return $risposta;
}

function unoDeiTodo(string $gettone, string $titolo = 'Richiamare'): string
{
    $risposta = alFinto('POST', '/v1/board/todo/elementi', ['titolo' => $titolo], $gettone);
    expect($risposta->status())->toBe(201);

    return $risposta->json('data.id');
}

it('collega un contatto: 201, la Location, la forma del backoffice; crm è nel to-do (intero) e nelle righe dell\'elenco (breve)', function () {
    [, $gettoni] = studioTodoECrm();
    $todo = unoDeiTodo($gettoni['anna']);
    expect(alFinto('GET', "/v1/board/todo/elementi/{$todo}", null, $gettoni['anna'])->json('data.crm'))->toBe([]);
    $persona = contattoPerIlTodo($gettoni['anna'], 'Giulia', 'Verdi');

    $prima = collegaIlTodo($gettoni['anna'], $todo, 'con_chi', 'crm.persona', $persona, 201);

    expect($prima->header('Location'))->toBe("/v1/board/todo/elementi/{$todo}/crm/".$prima->json('data.id'))
        ->and(array_keys($prima->json('data')))->toBe(['id', 'ruolo', 'tipo', 'record_id', 'titolo', 'contesto', 'motivo', 'unito_in', 'indirizzo', 'creato_il', 'creato_da'])
        ->and($prima->json('data'))->toMatchArray(['ruolo' => 'con_chi', 'tipo' => 'crm.persona', 'record_id' => $persona, 'titolo' => 'Giulia Verdi', 'motivo' => null, 'unito_in' => null, 'indirizzo' => "/v1/crm/persone/{$persona}"]);

    expect(collegaIlTodo($gettoni['anna'], $todo, 'con_chi', 'crm.persona', $persona, 409)->json('codice'))->toBe('record_gia_collegato');

    $mostra = alFinto('GET', "/v1/board/todo/elementi/{$todo}", null, $gettoni['anna']);
    $riga = alFinto('GET', '/v1/board/todo/elementi?lista_id='.$mostra->json('data.lista_id'), null, $gettoni['anna'])->json('data.0.crm.0');

    expect($mostra->json('data.crm.0.id'))->toBe($prima->json('data.id'))
        ->and(array_keys($riga))->toBe(['ruolo', 'tipo', 'record_id', 'titolo', 'motivo', 'unito_in'])
        ->and($riga['titolo'])->toBe('Giulia Verdi');
});

it('con un collegamento il 304 di mostra non c\'è (titolo e contesto non entrano nell\'ETag); senza, c\'è ancora', function () {
    [, $gettoni] = studioTodoECrm();
    $todo = unoDeiTodo($gettoni['anna']);
    $etag = alFinto('GET', "/v1/board/todo/elementi/{$todo}", null, $gettoni['anna'])->header('ETag');

    expect(alFinto('GET', "/v1/board/todo/elementi/{$todo}", null, $gettoni['anna'], null, ['If-None-Match' => $etag])->status())->toBe(304);

    collegaIlTodo($gettoni['anna'], $todo, 'con_chi', 'crm.persona', contattoPerIlTodo($gettoni['anna'], 'Giulia'), 201);

    expect(alFinto('GET', "/v1/board/todo/elementi/{$todo}", null, $gettoni['anna'], null, ['If-None-Match' => $etag])->status())->toBe(200);
});

it('i ruoli hanno tetti propri: «dove» uno solo, «con_chi» venti (409 limite_raggiunto con limite); lo stesso record può essere in tutti e due', function () {
    [, $gettoni] = studioTodoECrm();
    $todo = unoDeiTodo($gettoni['anna']);
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['anna'])->json('data.id');
    $altra = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Bianchi Srl'], $gettoni['anna'])->json('data.id');

    collegaIlTodo($gettoni['anna'], $todo, 'dove', 'crm.organizzazione', $azienda, 201);
    $dove = collegaIlTodo($gettoni['anna'], $todo, 'dove', 'crm.organizzazione', $altra, 409);
    expect($dove->json('codice'))->toBe('limite_raggiunto')->and($dove->json('limite'))->toBe(1);
    collegaIlTodo($gettoni['anna'], $todo, 'con_chi', 'crm.organizzazione', $azienda, 201);

    $primo = null;

    foreach (range(1, 19) as $n) {
        $collegato = collegaIlTodo($gettoni['anna'], $todo, 'con_chi', 'crm.persona', contattoPerIlTodo($gettoni['anna'], "Contatto {$n}"), 201)->json('data.id');
        $primo ??= $collegato;
    }

    $fermata = collegaIlTodo($gettoni['anna'], $todo, 'con_chi', 'crm.persona', contattoPerIlTodo($gettoni['anna'], 'Contatto 20'), 409);
    expect($fermata->json('codice'))->toBe('limite_raggiunto')->and($fermata->json('limite'))->toBe(20);

    expect(alFinto('DELETE', "/v1/board/todo/elementi/{$todo}/crm/{$primo}", null, $gettoni['anna'])->status())->toBe(204);
    collegaIlTodo($gettoni['anna'], $todo, 'con_chi', 'crm.persona', contattoPerIlTodo($gettoni['anna'], 'Contatto 21'), 201);
});

it('un id che non c\'è nel workspace è 422 su #/id; ruolo, tipo e id sbagliati sono 422 sul loro campo', function () {
    [, $gettoni] = studioTodoECrm();
    $todo = unoDeiTodo($gettoni['anna']);

    $inventato = collegaIlTodo($gettoni['anna'], $todo, 'con_chi', 'crm.persona', '01k6w9a1c3e5g7j9m1p3r5t7v9', 422);

    expect($inventato->json('errors.0.pointer'))->toBe('#/id')->and($inventato->json('errors.0.regola'))->toBe('non_trovato');
    expect(collegaIlTodo($gettoni['anna'], $todo, 'accanto', 'crm.persona', '01k6w9a1c3e5g7j9m1p3r5t7v9', 422)->json('errors.0.pointer'))->toBe('#/ruolo');
    expect(collegaIlTodo($gettoni['anna'], $todo, 'con_chi', 'crm.cliente', '01k6w9a1c3e5g7j9m1p3r5t7v9', 422)->json('errors.0.pointer'))->toBe('#/tipo');
    expect(collegaIlTodo($gettoni['anna'], $todo, 'con_chi', 'crm.persona', 'non-un-ulid', 422)->json('errors.0.pointer'))->toBe('#/id');
});

it('elimina toglie il collegamento (204): non è più in lista, non si elimina di nuovo (404) e lo stesso record si ricollega; il to-do di un altro è 404', function () {
    [, $gettoni] = studioTodoECrm();
    $todo = unoDeiTodo($gettoni['anna']);
    $altro = unoDeiTodo($gettoni['anna'], 'Altro');
    $persona = contattoPerIlTodo($gettoni['anna'], 'Giulia');
    $id = collegaIlTodo($gettoni['anna'], $todo, 'con_chi', 'crm.persona', $persona, 201)->json('data.id');

    expect(alFinto('GET', "/v1/board/todo/elementi/{$todo}/crm", null, $gettoni['bruno'])->status())->toBe(404)
        ->and(collegaIlTodo($gettoni['bruno'], $todo, 'con_chi', 'crm.persona', $persona)->status())->toBe(404)
        ->and(alFinto('DELETE', "/v1/board/todo/elementi/{$altro}/crm/{$id}", null, $gettoni['anna'])->status())->toBe(404)
        ->and(alFinto('DELETE', "/v1/board/todo/elementi/{$todo}/crm/{$id}", null, $gettoni['anna'])->status())->toBe(204);
    expect(alFinto('GET', "/v1/board/todo/elementi/{$todo}/crm", null, $gettoni['anna'])->json('data'))->toBe([])
        ->and(alFinto('DELETE', "/v1/board/todo/elementi/{$todo}/crm/{$id}", null, $gettoni['anna'])->status())->toBe(404);
    collegaIlTodo($gettoni['anna'], $todo, 'con_chi', 'crm.persona', $persona, 201);
});

it('l\'elenco dà i collegamenti nell\'ordine di nascita, a cursore', function () {
    [, $gettoni] = studioTodoECrm();
    $todo = unoDeiTodo($gettoni['anna']);
    $uno = collegaIlTodo($gettoni['anna'], $todo, 'con_chi', 'crm.persona', contattoPerIlTodo($gettoni['anna'], 'Giulia'), 201)->json('data.id');
    $due = collegaIlTodo($gettoni['anna'], $todo, 'con_chi', 'crm.persona', contattoPerIlTodo($gettoni['anna'], 'Marco'), 201)->json('data.id');

    $prima = alFinto('GET', "/v1/board/todo/elementi/{$todo}/crm?limite=1", null, $gettoni['anna']);
    $seconda = alFinto('GET', "/v1/board/todo/elementi/{$todo}/crm?limite=1&cursore=".$prima->json('successivo'), null, $gettoni['anna']);

    expect($prima->json('data.0.id'))->toBe($uno)->and($prima->json('successivo'))->not->toBeNull()
        ->and($seconda->json('data.0.id'))->toBe($due)->and($seconda->json('successivo'))->toBeNull();
});

it('un contatto anonimizzato resta con titolo null e il motivo anonimizzato; con l\'app crm spenta il motivo è non_leggibile e collegare è 403', function () {
    [, $gettoni] = studioTodoECrm();
    $todo = unoDeiTodo($gettoni['anna']);
    $persona = contattoPerIlTodo($gettoni['anna'], 'Giulia');
    collegaIlTodo($gettoni['anna'], $todo, 'con_chi', 'crm.persona', $persona, 201);

    expect(alFinto('POST', "/v1/crm/persone/{$persona}/anonimizzazione", null, $gettoni['anna'])->status())->toBeLessThan(300);
    expect(alFinto('GET', "/v1/board/todo/elementi/{$todo}", null, $gettoni['anna'])->json('data.crm.0'))
        ->toMatchArray(['titolo' => null, 'contesto' => null, 'motivo' => 'anonimizzato']);

    expect(alFinto('PATCH', '/v1/app/crm', ['stato' => 'disponibile'], $gettoni['anna'])->status())->toBe(200);
    expect(alFinto('GET', "/v1/board/todo/elementi/{$todo}/crm", null, $gettoni['anna'])->json('data.0'))->toMatchArray(['titolo' => null, 'motivo' => 'non_leggibile'])
        ->and(collegaIlTodo($gettoni['anna'], $todo, 'con_chi', 'crm.persona', $persona)->json('codice'))->toBe('app_non_attiva');
});

it('un contatto unito in un altro ha motivo unito e unito_in: l\'id di chi resta; l\'unione annullata lo riporta com\'era', function () {
    [, $gettoni] = studioTodoECrm();
    $todo = unoDeiTodo($gettoni['anna']);
    $resta = contattoPerIlTodo($gettoni['anna'], 'Anna', 'Rossi');
    $unito = contattoPerIlTodo($gettoni['anna'], 'Anna', 'Rossi');
    collegaIlTodo($gettoni['anna'], $todo, 'con_chi', 'crm.persona', $unito, 201);

    $versioni = [];

    foreach ([$resta, $unito] as $contatto) {
        $versioni[$contatto] = alFinto('GET', "/v1/crm/persone/{$contatto}", null, $gettoni['anna'])->header('ETag');
    }

    $unione = alFinto('POST', '/v1/crm/persone/unioni', ['resta_id' => $resta, 'unito_id' => $unito, 'versioni' => $versioni], $gettoni['anna']);
    expect($unione->status())->toBe(201, $unione->body());

    $riga = alFinto('GET', "/v1/board/todo/elementi/{$todo}/crm", null, $gettoni['anna'])->json('data.0');
    expect($riga)->toMatchArray(['record_id' => $unito, 'titolo' => null, 'contesto' => null, 'motivo' => 'unito', 'unito_in' => $resta]);

    expect(alFinto('POST', '/v1/crm/persone/unioni/'.$unione->json('data.id').'/annullamento', null, $gettoni['anna'])->status())->toBeLessThan(300);
    expect(alFinto('GET', "/v1/board/todo/elementi/{$todo}/crm", null, $gettoni['anna'])->json('data.0'))->toMatchArray(['titolo' => 'Anna Rossi', 'motivo' => null, 'unito_in' => null]);
});
