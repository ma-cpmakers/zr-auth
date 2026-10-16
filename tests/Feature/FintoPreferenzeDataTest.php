<?php

// #2228: formato_data e inizio_settimana della persona nel finto. Che le risposte e gli errori siano quelli del backoffice lo prova il suo
// FintoTest; qui ciò che un frontend vede: i valori ammessi, `null` che torna alla lingua, il pointer dell'errore e niente scritto.

it('io.modifica cambia formato_data e inizio_settimana, io.mostra li rilegge, null li azzera e un campo non mandato non cambia (#2228)', function () {
    [, $gettoni] = studioColCrm();
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];

    expect(alFinto('GET', '/v1/io', gettone: $accesso)->json('data.utente'))->toHaveKeys(['formato_data', 'inizio_settimana'])
        ->and(alFinto('GET', '/v1/io', gettone: $accesso)->json('data.utente.formato_data'))->toBeNull();

    $risposta = alFinto('PATCH', '/v1/io', ['utente' => ['formato_data' => 'aaaa-mm-gg', 'inizio_settimana' => 'domenica']], $accesso);

    expect($risposta->status())->toBe(200)
        ->and($risposta->json('data.utente.formato_data'))->toBe('aaaa-mm-gg')
        ->and($risposta->json('data.utente.inizio_settimana'))->toBe('domenica')
        ->and(alFinto('GET', '/v1/io', gettone: $accesso)->json('data'))->toBe($risposta->json('data'));

    alFinto('PATCH', '/v1/io', ['utente' => ['inizio_settimana' => null]], $accesso);

    expect(alFinto('GET', '/v1/io', gettone: $accesso)->json('data.utente.formato_data'))->toBe('aaaa-mm-gg')
        ->and(alFinto('GET', '/v1/io', gettone: $accesso)->json('data.utente.inizio_settimana'))->toBeNull();
});

it('un valore fuori elenco è 422 valore_non_ammesso sul pointer del campo e non scrive niente, nemmeno gli altri campi; col gettone di un workspace è 403 (#2228)', function () {
    [, $gettoni] = studioColCrm();
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];

    foreach ([['formato_data', 'dd/mm/yyyy'], ['formato_data', 'GG/MM/AAAA'], ['inizio_settimana', 'martedi'], ['inizio_settimana', 1]] as [$campo, $valore]) {
        $risposta = alFinto('PATCH', '/v1/io', ['utente' => ['nome' => 'Altro nome', $campo => $valore]], $accesso);

        expect($risposta->status())->toBe(422)
            ->and($risposta->json('errors.0.pointer'))->toBe("#/utente/{$campo}")
            ->and($risposta->json('errors.0.regola'))->toBe('valore_non_ammesso');
    }

    expect(alFinto('GET', '/v1/io', gettone: $accesso)->json('data.utente.nome'))->not->toBe('Altro nome')
        ->and(alFinto('PATCH', '/v1/io', ['utente' => ['formato_data' => 'gg/mm/aaaa']], $gettoni['anna'])->json('codice'))->toBe('gettone_con_workspace');
});
