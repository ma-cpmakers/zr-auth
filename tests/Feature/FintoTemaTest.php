<?php

// #3365: utente.tema nel finto. Che le risposte e gli errori siano quelli del backoffice lo prova il suo FintoTest; qui ciò che un frontend vede:
// `sistema` per chi non ha mai scelto (mai null), i valori ammessi, il pointer dell'errore e il tema di prima che resta.

it('io.mostra dà sistema a chi non ha scelto, io.modifica cambia il tema, un PATCH senza tema non lo tocca (#3365)', function () {
    [, $gettoni] = studioColCrm();
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];

    expect(alFinto('GET', '/v1/io', gettone: $accesso)->json('data.utente.tema'))->toBe('sistema');

    $risposta = alFinto('PATCH', '/v1/io', ['utente' => ['tema' => 'scuro']], $accesso);

    expect($risposta->status())->toBe(200)
        ->and($risposta->json('data.utente.tema'))->toBe('scuro')
        ->and(alFinto('GET', '/v1/io', gettone: $accesso)->json())->toBe($risposta->json());

    alFinto('PATCH', '/v1/io', ['utente' => ['nome' => 'Anna Maria']], $accesso);

    expect(alFinto('GET', '/v1/io', gettone: $accesso)->json('data.utente.tema'))->toBe('scuro');
});

it('null, vuoto o fuori elenco è 422 dati_non_validi su #/utente/tema e il tema di prima resta (#3365)', function () {
    [, $gettoni] = studioColCrm();
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];
    alFinto('PATCH', '/v1/io', ['utente' => ['tema' => 'scuro']], $accesso);

    foreach ([null, '', 'Scuro', 'blu', 1] as $valore) {
        $risposta = alFinto('PATCH', '/v1/io', ['utente' => ['nome' => 'Altro nome', 'tema' => $valore]], $accesso);

        expect($risposta->status())->toBe(422)
            ->and($risposta->json('codice'))->toBe('dati_non_validi')
            ->and($risposta->json('errors.0.pointer'))->toBe('#/utente/tema');
    }

    $mostra = alFinto('GET', '/v1/io', gettone: $accesso)->json('data.utente');

    expect($mostra['tema'])->toBe('scuro')->and($mostra['nome'])->not->toBe('Altro nome');
});
