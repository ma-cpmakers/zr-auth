<?php

// #1927 (G67): la mansione, il ruolo e la fonte seguono la regola dei nomi anche nel finto. Che il 422 sia quello del backoffice lo prova
// il suo FintoTest (stesse chiamate, stesse risposte); qui ciò che un frontend vede: sul pointer del campo, e senza scrivere.

it('la mansione, il ruolo e la fonte con un carattere di controllo, di direzione o a larghezza zero sono 422 carattere_non_ammesso sul loro campo, e non si scrive (G67)', function () {
    [, $gettoni] = studioColCrm();
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];

    $mansione = alFinto('PATCH', '/v1/io', ['utente' => ['mansione' => "Diret\u{202E}tore"]], $accesso);
    expect($mansione->status())->toBe(422)
        ->and($mansione->json('errors.0.pointer'))->toBe('#/utente/mansione')
        ->and($mansione->json('errors.0.regola'))->toBe('carattere_non_ammesso');

    foreach (['ruolo' => "Diret\ntore", 'fonte' => "fiera\u{200B}Milano"] as $campo => $valore) {
        $risposta = alFinto('POST', '/v1/crm/persone', ['nome' => 'Anna', $campo => $valore], $gettoni['anna']);
        expect($risposta->status())->toBe(422)
            ->and($risposta->json('errors.0.pointer'))->toBe("#/{$campo}")
            ->and($risposta->json('errors.0.regola'))->toBe('carattere_non_ammesso');
    }

    expect(alFinto('GET', '/v1/io', gettone: $accesso)->json('data.utente.mansione'))->toBeNull()
        ->and(alFinto('PATCH', '/v1/io', ['utente' => ['mansione' => 'Responsabile acquisti']], $accesso)->status())->toBe(200)
        ->and(alFinto('POST', '/v1/crm/persone', ['nome' => 'Anna', 'ruolo' => "مدیر\u{200C}عامل", 'fonte' => 'Fiera di Milano'], $gettoni['anna'])->status())->toBe(201);
});
