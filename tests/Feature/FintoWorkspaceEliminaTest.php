<?php

// Sprint 36, PR G (#2014): workspace.elimina e workspace.eliminazione.elimina nel finto. Qui ciò che un frontend vede dal suo lato;
// che le risposte e gli errori siano quelli del backoffice, byte per byte, lo prova il FintoTest del backoffice.

/** Un proprietario con due workspace, e un amministratore del primo: [studio, altro, gettone del proprietario, gettone dell'amministratore, gettone dell'altro]. */
function conDueWorkspace(): array
{
    $finto = Zeiras\Auth\Testing\BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio', $anna);
    $altro = $finto->workspace('Altro', $anna);
    $bruno = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $finto->membro($studio, $bruno, 'amministratore');
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];
    $gettone = fn (string $workspace) => alFinto('POST', '/v1/gettoni', ['workspace_id' => $workspace], $accesso)->json('data.gettone');

    return [$studio, $altro, $gettone($studio['id']), alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('bruno@example.com')['gettone']['gettone'])->json('data.gettone'), $gettone($altro['id'])];
}

it('il proprietario programma l\'eliminazione fra 30 giorni, l\'elenco la mostra e il workspace lavora come prima (#2014)', function () {
    [$studio, $altro, $anna, $bruno] = conDueWorkspace();
    $this->travelTo('2026-10-15 12:00:00');

    $risposta = alFinto('DELETE', '/v1/workspace', gettone: $anna);

    expect($risposta->status())->toBe(202)
        ->and($risposta->json('data'))->toBe(['id' => $studio['id'], 'eliminazione_il' => '2026-11-14T12:00:00.000Z']);

    $elenco = collect(alFinto('GET', '/v1/io/workspace', gettone: $anna)->json('data'))->keyBy('id');
    expect($elenco[$studio['id']]['eliminazione_il'])->toBe('2026-11-14T12:00:00.000Z')
        ->and($elenco[$altro['id']]['eliminazione_il'])->toBeNull()
        ->and(alFinto('GET', '/v1/workspace/membri', gettone: $bruno)->status())->toBe(200)
        ->and(alFinto('PATCH', '/v1/workspace', ['nome' => 'Nuovo'], $bruno)->json('data.eliminazione_il'))->toBe('2026-11-14T12:00:00.000Z')
        // Una seconda chiamata risponde con la stessa data.
        ->and(alFinto('DELETE', '/v1/workspace', gettone: $anna)->json('data.eliminazione_il'))->toBe('2026-11-14T12:00:00.000Z');
});

it('un amministratore non elimina né annulla (403), e l\'ultimo workspace della persona è 409 ultimo_workspace (#2014)', function () {
    [$studio, , $anna, $bruno, $altroAnna] = conDueWorkspace();

    expect(alFinto('DELETE', '/v1/workspace', gettone: $bruno)->json('codice'))->toBe('permesso_negato')
        ->and(alFinto('DELETE', '/v1/workspace/eliminazione', gettone: $bruno)->json('codice'))->toBe('permesso_negato');

    alFinto('DELETE', '/v1/workspace', gettone: $anna);
    $ultimo = alFinto('DELETE', '/v1/workspace', gettone: $altroAnna);

    expect($ultimo->status())->toBe(409)
        ->and($ultimo->json('codice'))->toBe('ultimo_workspace');
    // Annullata la prima, il secondo non è più l'ultimo.
    alFinto('DELETE', '/v1/workspace/eliminazione', gettone: $anna);
    expect(alFinto('DELETE', '/v1/workspace', gettone: $altroAnna)->status())->toBe(202)
        ->and($studio['id'])->not->toBeEmpty();
});

it('annullare toglie la data (204), e senza un\'eliminazione programmata è 404 non_trovato (#2014)', function () {
    [$studio, , $anna] = conDueWorkspace();

    expect(alFinto('DELETE', '/v1/workspace/eliminazione', gettone: $anna)->json('codice'))->toBe('non_trovato');

    alFinto('DELETE', '/v1/workspace', gettone: $anna);
    $annullata = alFinto('DELETE', '/v1/workspace/eliminazione', gettone: $anna);

    expect($annullata->status())->toBe(204)
        ->and(collect(alFinto('GET', '/v1/io/workspace', gettone: $anna)->json('data'))->firstWhere('id', $studio['id'])['eliminazione_il'])->toBeNull();
});
