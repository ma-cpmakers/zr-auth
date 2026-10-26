<?php

// Sprint 36, PR G (#2014): workspace.elimina e workspace.eliminazione.elimina nel finto. Qui ciò che un frontend vede dal suo lato;
// che le risposte e gli errori siano quelli del backoffice, byte per byte, lo prova il FintoTest del backoffice.

/**
 * Un proprietario con due workspace, e un amministratore del primo: [studio, altro, gettone del proprietario, gettone dell'amministratore,
 * gettone dell'altro, il finto, gettone senza workspace del proprietario]. Dal #2600 i gettoni di un workspace in eliminazione non valgono più
 * (401): con quello senza workspace la persona lo ritrova e lo ripristina (#2602).
 */
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

    return [$studio, $altro, $gettone($studio['id']), alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('bruno@example.com')['gettone']['gettone'])->json('data.gettone'), $gettone($altro['id']), $finto, $accesso];
}

it('il proprietario programma l\'eliminazione fra 30 giorni: il workspace sparisce subito dagli elenchi e i suoi gettoni sono 401 (#2014, #2600, #2601)', function () {
    $this->travelTo('2026-10-15 12:00:00');
    [$studio, $altro, $anna, $bruno, , , $accesso] = conDueWorkspace();

    $risposta = alFinto('DELETE', '/v1/workspace', gettone: $anna);

    expect($risposta->status())->toBe(202)
        ->and($risposta->json('data'))->toBe(['id' => $studio['id'], 'eliminazione_il' => '2026-11-14T12:00:00.000Z', 'membri_avvisati' => true]);

    $elenco = alFinto('GET', '/v1/io/workspace', gettone: $accesso)->json('data');
    $eliminati = alFinto('GET', "/v1/aziende/{$studio['azienda_id']}/workspace?eliminati=true", gettone: $accesso)->json('data');
    $annaId = alFinto('GET', '/v1/io', gettone: $accesso)->json('data.utente.id');

    // Il proprietario e l'amministratore non hanno più un gettone che valga; l'altro workspace della stessa persona sì.
    expect(array_column($elenco, 'id'))->toBe([$altro['id']])
        ->and($elenco[0]['eliminazione_il'])->toBeNull()
        ->and(array_column($eliminati, 'id'))->toBe([$studio['id']])
        ->and($eliminati[0]['eliminazione_il'])->toBe('2026-11-14T12:00:00.000Z')
        ->and($eliminati[0]['eliminato_da'])->toBe($annaId)
        ->and($eliminati[0]['uscita_negata'])->toBeNull();

    foreach ([
        alFinto('GET', '/v1/workspace/membri', gettone: $bruno),
        alFinto('PATCH', '/v1/workspace', ['nome' => 'Nuovo'], $bruno),
        alFinto('DELETE', '/v1/workspace', gettone: $anna),
        alFinto('GET', '/v1/io', gettone: $anna),
    ] as $risposta) {
        expect([$risposta->status(), $risposta->json('codice')])->toBe([401, 'gettone_non_valido']);
    }

    // `gettoni.crea` non riapre un workspace in eliminazione: è lo stesso 422 di un workspace non tuo.
    $riapre = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso);

    expect($riapre->status())->toBe(422)->and($riapre->json('errors.0.pointer'))->toBe('#/workspace_id');
});

it('un amministratore non elimina né annulla (403), e l\'ultimo workspace della persona è 409 ultimo_workspace (#2014)', function () {
    [$studio, , $anna, $bruno, $altroAnna, , $accesso] = conDueWorkspace();

    expect(alFinto('DELETE', '/v1/workspace', gettone: $bruno)->json('codice'))->toBe('permesso_negato')
        ->and(alFinto('DELETE', '/v1/workspace/eliminazione', gettone: $bruno)->json('codice'))->toBe('permesso_negato');

    alFinto('DELETE', '/v1/workspace', gettone: $anna);
    $ultimo = alFinto('DELETE', '/v1/workspace', gettone: $altroAnna);

    expect($ultimo->status())->toBe(409)
        ->and($ultimo->json('codice'))->toBe('ultimo_workspace');
    // Ripristinata la prima, il secondo non è più l'ultimo.
    alFinto('DELETE', "/v1/io/workspace/{$studio['id']}/eliminazione", gettone: $accesso);
    expect(alFinto('DELETE', '/v1/workspace', gettone: $altroAnna)->status())->toBe(202)
        ->and($studio['id'])->not->toBeEmpty();
});

it('workspace.eliminazione.elimina, senza un\'eliminazione programmata, è 404 non_trovato; dopo, il gettone del workspace è 401 (#2014, #2600)', function () {
    [, , $anna] = conDueWorkspace();

    expect(alFinto('DELETE', '/v1/workspace/eliminazione', gettone: $anna)->json('codice'))->toBe('non_trovato');

    alFinto('DELETE', '/v1/workspace', gettone: $anna);
    $vecchio = alFinto('DELETE', '/v1/workspace/eliminazione', gettone: $anna);

    expect($vecchio->status())->toBe(401)->and($vecchio->json('codice'))->toBe('gettone_non_valido');
});

it('io.workspace.eliminazione.elimina ripristina col gettone della persona (200 con membri_avvisati): il workspace torna negli elenchi e i gettoni tornano validi (#2602)', function () {
    [$studio, , $anna, , , , $accesso] = conDueWorkspace();
    alFinto('DELETE', '/v1/workspace', gettone: $anna);

    $ripristinato = alFinto('DELETE', "/v1/io/workspace/{$studio['id']}/eliminazione", gettone: $accesso);
    $elenco = collect(alFinto('GET', '/v1/io/workspace', gettone: $accesso)->json('data'))->keyBy('id');

    expect($ripristinato->status())->toBe(200)
        ->and($ripristinato->json())->toBe(['data' => ['membri_avvisati' => true]])
        ->and($elenco[$studio['id']]['eliminazione_il'])->toBeNull()
        ->and($elenco[$studio['id']]['eliminato_da'])->toBeNull()
        ->and(alFinto('GET', '/v1/io', gettone: $anna)->status())->toBe(200)
        ->and(alFinto('GET', "/v1/aziende/{$studio['azienda_id']}/workspace?eliminati=true", gettone: $accesso)->json('data'))->toBe([]);
});

it('io.workspace.eliminazione.elimina: l\'amministratore ripristina, un membro o un workspace non in eliminazione è 404, un corpo con campi 422 prima del 404 (#2602)', function () {
    [$studio, $altro, $anna, , , $finto, $accesso] = conDueWorkspace();
    $finto->membro($studio, $finto->persona('carla@example.com', PASSWORD, nome: 'Carla'), 'membro');
    $accessoBruno = entraNelFinto('bruno@example.com')['gettone']['gettone'];
    $accessoCarla = entraNelFinto('carla@example.com')['gettone']['gettone'];
    $ripristina = fn (string $gettone, string $workspace, ?array $corpo = null) => alFinto('DELETE', "/v1/io/workspace/{$workspace}/eliminazione", $corpo, $gettone);

    // Ancora vivo: 404 anche al proprietario; il corpo sbagliato è 422 prima.
    expect($ripristina($accesso, $studio['id'])->status())->toBe(404)
        ->and($ripristina($accesso, $studio['id'], ['x' => 1])->status())->toBe(422);

    alFinto('DELETE', '/v1/workspace', gettone: $anna);

    expect($ripristina($accessoCarla, $studio['id'])->status())->toBe(404)
        ->and($ripristina($accessoBruno, $altro['id'])->status())->toBe(404)
        ->and($ripristina($accesso, 'inventato')->status())->toBe(404)
        ->and($ripristina($accessoBruno, $studio['id'])->status())->toBe(200);
});

it('con l\'eliminazione programmata le due accettazioni sono 409 workspace_in_eliminazione e i tre metodi del workspace 401; ripristinata tornano a valere (#2259, #2600)', function () {
    [$studio, , $anna, $bruno, , $finto, $accesso] = conDueWorkspace();
    $finto->persona('carla@example.com', PASSWORD, nome: 'Carla');
    $finto->persona('dario@example.com', PASSWORD, nome: 'Dario');
    $accessoCarla = entraNelFinto('carla@example.com')['gettone']['gettone'];
    $accessoDario = entraNelFinto('dario@example.com')['gettone']['gettone'];

    $invito = alFinto('POST', '/v1/workspace/inviti', ['email' => 'carla@example.com', 'ruolo' => 'membro'], $anna)->json('data.id');
    $codice = $finto->ultimoInvito('carla@example.com');
    $codiceLink = alFinto('POST', '/v1/workspace/link', gettone: $anna)->json('data.codice');

    alFinto('DELETE', '/v1/workspace', gettone: $anna);

    $accettazioni = [
        alFinto('POST', '/v1/inviti/accettazione', ['codice' => $codice], $accessoCarla),
        alFinto('POST', '/v1/link/accettazione', ['codice' => $codiceLink], $accessoDario),
    ];

    foreach ($accettazioni as $esito) {
        expect($esito->status())->toBe(409)->and($esito->json('codice'))->toBe('workspace_in_eliminazione');
    }

    // I gettoni del workspace non valgono più (#2600): invitare, reinviare, creare il link, elencare gli inviti sono 401.
    $chiusi = [
        alFinto('POST', '/v1/workspace/inviti', ['email' => 'nuovo@example.com', 'ruolo' => 'membro'], $bruno),
        alFinto('POST', "/v1/workspace/inviti/{$invito}/reinvio", gettone: $anna),
        alFinto('POST', '/v1/workspace/link', gettone: $bruno),
        alFinto('GET', '/v1/workspace/inviti', gettone: $bruno),
    ];

    foreach ($chiusi as $esito) {
        expect($esito->status())->toBe(401)->and($esito->json('codice'))->toBe('gettone_non_valido');
    }

    // Un codice che non vale è la stessa 422 di sempre.
    expect(alFinto('POST', '/v1/inviti/accettazione', ['codice' => str_repeat('a', 43)], $accessoDario)->json('codice'))->toBe('verifica_non_riuscita');

    alFinto('DELETE', "/v1/io/workspace/{$studio['id']}/eliminazione", gettone: $accesso);

    expect(alFinto('POST', '/v1/inviti/accettazione', ['codice' => $codice], $accessoCarla)->status())->toBe(201)
        ->and(alFinto('POST', '/v1/link/accettazione', ['codice' => $codiceLink], $accessoDario)->status())->toBe(201)
        ->and(alFinto('POST', '/v1/workspace/link', gettone: $bruno)->status())->toBe(201)
        ->and($studio['id'])->not->toBeEmpty();
});

it('un invito a un workspace in eliminazione è 409 anche per una persona nuova, da utenti.crea e da accessi.provider.crea, e la persona non nasce (#2044, zr-pm 17/10)', function () {
    [$studio, , $anna, , , $finto, $accessoAnna] = conDueWorkspace();
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

    // Ripristinato il workspace, gli stessi inviti valgono.
    alFinto('DELETE', "/v1/io/workspace/{$studio['id']}/eliminazione", gettone: $accessoAnna);
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

it("eliminati con un valore che non va, anche vuoto, è 422 sul parametro con la stessa regola valore_non_ammesso (#2684)", function (string $valore) {
    [$studio, , , , , , $accesso] = conDueWorkspace();

    $risposta = alFinto('GET', "/v1/aziende/{$studio['azienda_id']}/workspace?eliminati={$valore}", gettone: $accesso);

    // deve fallire se: l'errore non indica il parametro, o il valore vuoto dà un'altra regola
    expect($risposta->status())->toBe(422)
        ->and($risposta->json('codice'))->toBe('dati_non_validi')
        ->and($risposta->json('errors.0.parameter'))->toBe('eliminati')
        ->and($risposta->json('errors.0.regola'))->toBe('valore_non_ammesso');
})->with(['1', 'yes', 'maybe', '']);

it("l'ultimo workspace non si lascia né si elimina: il detail è lo stesso per chi elimina e per chi esce, e rimanda al ripristino di oggi (#2684)", function () {
    $finto = Zeiras\Auth\Testing\BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $solo = $finto->workspace('Solo', $anna);
    $bruno = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $finto->membro($solo, $bruno, 'membro');
    $gettoneAnna = alFinto('POST', '/v1/gettoni', ['workspace_id' => $solo['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');
    $accessoBruno = entraNelFinto('bruno@example.com')['gettone']['gettone'];

    $eliminazione = alFinto('DELETE', '/v1/workspace', gettone: $gettoneAnna);
    $uscita = alFinto('DELETE', "/v1/io/workspace/{$solo['id']}", gettone: $accessoBruno);

    // deve fallire se: la risposta di chi esce dice solo «non si elimina»
    expect($eliminazione->status())->toBe(409)
        ->and($uscita->status())->toBe(409)
        ->and($uscita->json('detail'))->toBe($eliminazione->json('detail'))
        ->and($uscita->json('detail'))->toContain('lasci')->toContain('elimini')->toContain('io.workspace.eliminazione.elimina');
});

it('membri_avvisati è false quando il tetto di un giro di email ogni 24 ore ferma il secondo giro, e torna true dopo un giorno (#2636)', function () {
    $this->travelTo('2026-10-26 12:00:00');
    [$studio, , $anna, , , , $accesso] = conDueWorkspace();
    $giro = function () use ($studio, &$anna, &$accesso) {
        $eliminato = alFinto('DELETE', '/v1/workspace', gettone: $anna)->json('data.membri_avvisati');
        $ripristinato = alFinto('DELETE', "/v1/io/workspace/{$studio['id']}/eliminazione", gettone: $accesso)->json('data.membri_avvisati');

        return [$eliminato, $ripristinato];
    };

    expect($giro())->toBe([true, true]);

    // Il ripristino ridà il gettone: il secondo giro è dentro le 24 ore.
    expect($giro())->toBe([false, false]);

    // Un giorno dopo i gettoni sono scaduti: la persona entra di nuovo.
    $this->travelTo('2026-10-27 12:01:00');
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];
    $anna = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso)->json('data.gettone');

    expect($giro())->toBe([true, true]);
});
