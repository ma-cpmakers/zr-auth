<?php

// Sprint 36, PR G (#2014): workspace.elimina e workspace.eliminazione.elimina nel finto. Qui ciò che un frontend vede dal suo lato;
// che le risposte e gli errori siano quelli del backoffice, byte per byte, lo prova il FintoTest del backoffice.

/** Un proprietario con due workspace, e un amministratore del primo: [studio, altro, gettone del proprietario, gettone dell'amministratore, gettone dell'altro, il finto]. */
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

    return [$studio, $altro, $gettone($studio['id']), alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('bruno@example.com')['gettone']['gettone'])->json('data.gettone'), $gettone($altro['id']), $finto];
}

it('il proprietario programma l\'eliminazione fra 30 giorni, l\'elenco la mostra e il workspace lavora come prima (#2014)', function () {
    $this->travelTo('2026-10-15 12:00:00');
    [$studio, $altro, $anna, $bruno] = conDueWorkspace();

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
        ->and($annullata->body())->toBe('')
        ->and(collect(alFinto('GET', '/v1/io/workspace', gettone: $anna)->json('data'))->firstWhere('id', $studio['id'])['eliminazione_il'])->toBeNull();
});

it('con l\'eliminazione programmata gli inviti e il link sono 409 workspace_in_eliminazione, e annullata tornano a valere (#2259)', function () {
    [$studio, , $anna, $bruno, , $finto] = conDueWorkspace();
    $finto->persona('carla@example.com', PASSWORD, nome: 'Carla');
    $finto->persona('dario@example.com', PASSWORD, nome: 'Dario');
    $accessoCarla = entraNelFinto('carla@example.com')['gettone']['gettone'];
    $accessoDario = entraNelFinto('dario@example.com')['gettone']['gettone'];

    $invito = alFinto('POST', '/v1/workspace/inviti', ['email' => 'carla@example.com', 'ruolo' => 'membro'], $anna)->json('data.id');
    $codice = $finto->ultimoInvito('carla@example.com');
    $codiceLink = alFinto('POST', '/v1/workspace/link', gettone: $anna)->json('data.codice');

    alFinto('DELETE', '/v1/workspace', gettone: $anna);

    $esiti = [
        alFinto('POST', '/v1/workspace/inviti', ['email' => 'nuovo@example.com', 'ruolo' => 'membro'], $bruno),
        alFinto('POST', "/v1/workspace/inviti/{$invito}/reinvio", gettone: $anna),
        alFinto('POST', '/v1/workspace/link', gettone: $bruno),
        alFinto('POST', '/v1/inviti/accettazione', ['codice' => $codice], $accessoCarla),
        alFinto('POST', '/v1/link/accettazione', ['codice' => $codiceLink], $accessoDario),
    ];

    foreach ($esiti as $esito) {
        expect($esito->status())->toBe(409)->and($esito->json('codice'))->toBe('workspace_in_eliminazione');
    }

    // Elencare e revocare restano ammessi; un codice che non vale è la stessa 422 di sempre.
    expect(alFinto('GET', '/v1/workspace/inviti', gettone: $bruno)->status())->toBe(200)
        ->and(alFinto('POST', '/v1/inviti/accettazione', ['codice' => str_repeat('a', 43)], $accessoDario)->json('codice'))->toBe('verifica_non_riuscita');

    alFinto('DELETE', '/v1/workspace/eliminazione', gettone: $anna);

    expect(alFinto('POST', '/v1/inviti/accettazione', ['codice' => $codice], $accessoCarla)->status())->toBe(201)
        ->and(alFinto('POST', '/v1/link/accettazione', ['codice' => $codiceLink], $accessoDario)->status())->toBe(201)
        ->and(alFinto('POST', '/v1/workspace/link', gettone: $bruno)->status())->toBe(201)
        ->and($studio['id'])->not->toBeEmpty();
});

it('un invito a un workspace in eliminazione è 409 anche per una persona nuova, da utenti.crea e da accessi.provider.crea, e la persona non nasce (#2044, zr-pm 17/10)', function () {
    [$studio, , $anna, , , $finto] = conDueWorkspace();
    $finto->provider('google');
    $finto->identitaDelProvider('google', 'codice-di-erika', 'erika@example.com');

    $inviti = [];

    foreach (['edoardo@example.com', 'erika@example.com'] as $email) {
        expect(alFinto('POST', '/v1/workspace/inviti', ['email' => $email, 'ruolo' => 'membro'], $anna)->status())->toBe(201);
        $inviti[$email] = $finto->ultimoInvito($email);
    }

    alFinto('DELETE', '/v1/workspace', gettone: $anna);

    $registrazione = alFinto('POST', '/v1/utenti', [
        'email' => 'edoardo@example.com', 'password' => PASSWORD, 'termini_accettati' => true, 'invito' => $inviti['edoardo@example.com'],
    ]);
    $stato = alFinto('POST', '/v1/accessi/provider/google/autorizzazioni')->json('data.stato');
    $arrivo = alFinto('POST', '/v1/accessi/provider/google', ['codice' => 'codice-di-erika', 'stato' => $stato, 'termini_accettati' => true, 'invito' => $inviti['erika@example.com']]);

    foreach ([$registrazione, $arrivo] as $esito) {
        expect($esito->status())->toBe(409)->and($esito->json('codice'))->toBe('workspace_in_eliminazione');
    }

    // Né l'una né l'altra persona è nata: con l'invito (email già verificata) la password di Edoardo entrerebbe, e l'arrivo di Erika
    // senza i termini è 422 solo per una persona nuova (una che c'è entrerebbe: 201).
    $stato = alFinto('POST', '/v1/accessi/provider/google/autorizzazioni')->json('data.stato');

    $edoardo = alFinto('POST', '/v1/accessi', ['email' => 'edoardo@example.com', 'password' => PASSWORD]);

    expect($edoardo->status())->toBe(422)->and($edoardo->json('codice'))->toBe('credenziali_non_valide')
        ->and(alFinto('POST', '/v1/accessi/provider/google', ['codice' => 'codice-di-erika', 'stato' => $stato])->status())->toBe(422);

    // Annullata l'eliminazione, gli stessi inviti valgono.
    alFinto('DELETE', '/v1/workspace/eliminazione', gettone: $anna);
    $stato = alFinto('POST', '/v1/accessi/provider/google/autorizzazioni')->json('data.stato');

    expect(alFinto('POST', '/v1/utenti', [
        'email' => 'edoardo@example.com', 'password' => PASSWORD, 'termini_accettati' => true, 'invito' => $inviti['edoardo@example.com'],
    ])->status())->toBe(202)
        ->and(alFinto('POST', '/v1/accessi/provider/google', ['codice' => 'codice-di-erika', 'stato' => $stato, 'termini_accettati' => true, 'invito' => $inviti['erika@example.com']])->status())->toBe(201);

    // Edoardo è nato con l'email verificata, e il suo invito lo ha messo in Studio.
    $accesso = entraNelFinto('edoardo@example.com');
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso['gettone']['gettone']);

    expect($accesso['gettone']['utente']['email_verificata_il'])->toBeString()
        ->and($gettone->status())->toBe(201)
        ->and($gettone->json('data.ruolo'))->toBe('membro');
});

it('io.workspace.crea accetta il colore (#2012, C.1): uno della palette, senza o null è neutral, fuori palette 422 su #/colore e non nasce niente', function () {
    $finto = Zeiras\Auth\Testing\BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];

    $sky = alFinto('POST', '/v1/io/workspace', ['nome' => 'Studio sky', 'colore' => 'sky'], $accesso);
    $senza = alFinto('POST', '/v1/io/workspace', ['nome' => 'Senza colore'], $accesso);
    $nullo = alFinto('POST', '/v1/io/workspace', ['nome' => 'Colore null', 'colore' => null], $accesso);

    expect($sky->status())->toBe(201)->and($sky->json('data.colore'))->toBe('sky')
        ->and($senza->json('data.colore'))->toBe('neutral')
        ->and($nullo->json('data.colore'))->toBe('neutral')
        ->and(collect(alFinto('GET', '/v1/io/workspace', gettone: $accesso)->json('data'))->pluck('colore', 'nome')->all())
        ->toEqual(['Studio sky' => 'sky', 'Senza colore' => 'neutral', 'Colore null' => 'neutral']);

    foreach (['rosso', '#ff0000', 'PINE'] as $fuori) {
        $rifiuto = alFinto('POST', '/v1/io/workspace', ['nome' => 'Fuori', 'colore' => $fuori], $accesso);

        expect($rifiuto->status())->toBe(422)
            ->and($rifiuto->json('errors.0.pointer'))->toBe('#/colore')
            ->and($rifiuto->json('errors.0.regola'))->toBe('valore_non_ammesso');
    }

    expect(alFinto('GET', '/v1/io/workspace', gettone: $accesso)->json('data'))->toHaveCount(3);
});
