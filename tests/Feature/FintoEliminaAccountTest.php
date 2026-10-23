<?php

// #1961: io.elimina nel finto, «Elimina account» con ripensamento (forma b), e `eliminazione_annullata` negli accessi. Qui ciò che un
// frontend vede dal suo lato; che le risposte e gli errori siano quelli del backoffice, byte per byte, lo prova il FintoTest del backoffice.

/** Una persona con un accesso e la conferma fresca: [finto, persona, gettone]. */
function chiedeDiEliminare(bool $conferma = true): array
{
    $finto = Zeiras\Auth\Testing\BackofficeFinto::attiva();
    $zelda = $finto->persona('zelda@example.com', PASSWORD, nome: 'Zelda');
    $gettone = entraNelFinto('zelda@example.com')['gettone']['gettone'];

    if ($conferma) {
        expect(alFinto('POST', '/v1/accessi/conferma', ['password' => PASSWORD], $gettone)->status())->toBe(200);
    }

    return [$finto, $zelda, $gettone];
}

it('senza una conferma recente è 403 conferma_richiesta e il gettone vale ancora (#1961)', function () {
    [, , $gettone] = chiedeDiEliminare(conferma: false);

    $risposta = alFinto('DELETE', '/v1/io', gettone: $gettone);

    expect($risposta->status())->toBe(403)
        ->and($risposta->json('codice'))->toBe('conferma_richiesta')
        ->and(alFinto('GET', '/v1/io', gettone: $gettone)->status())->toBe(200);
});

it('con la conferma è 202 con le due date a trenta giorni, e da lì il gettone è 401 (#1961)', function () {
    $this->travelTo('2026-10-24 09:00:00');
    [, , $gettone] = chiedeDiEliminare();

    $risposta = alFinto('DELETE', '/v1/io', gettone: $gettone);

    expect($risposta->status())->toBe(202)
        ->and($risposta->json('data'))->toBe(['richiesta_il' => '2026-10-24T09:00:00.000Z', 'anonimizzazione_il' => '2026-11-23T09:00:00.000Z'])
        ->and($risposta->header('Link'))->toBe(linkDi('io.elimina'))
        ->and(alFinto('GET', '/v1/io', gettone: $gettone)->status())->toBe(401);
});

it('una proprietaria è 409 proprieta_da_trasferire con i workspace accanto al codice, e non cambia niente (#1961)', function () {
    [$finto, $zelda, $gettone] = chiedeDiEliminare();
    $studio = $finto->workspace('Studio di Zelda', $zelda);

    $risposta = alFinto('DELETE', '/v1/io', gettone: $gettone);

    expect($risposta->status())->toBe(409)
        ->and($risposta->json('codice'))->toBe('proprieta_da_trasferire')
        ->and($risposta->json('workspace_da_trasferire'))->toBe([['id' => $studio['id'], 'nome' => 'Studio di Zelda']])
        ->and(alFinto('GET', '/v1/io', gettone: $gettone)->status())->toBe(200);
});

it('chi rientra con la password toglie la richiesta, e solo allora l\'accesso porta eliminazione_annullata (#1961)', function () {
    [, , $gettone] = chiedeDiEliminare();
    alFinto('DELETE', '/v1/io', gettone: $gettone);

    $rientro = alFinto('POST', '/v1/accessi', ['email' => 'zelda@example.com', 'password' => PASSWORD]);

    expect($rientro->status())->toBe(201)
        ->and($rientro->json('data.eliminazione_annullata'))->toBeTrue();

    // La richiesta è tolta: un accesso dopo non porta più il campo.
    $ancora = alFinto('POST', '/v1/accessi', ['email' => 'zelda@example.com', 'password' => PASSWORD]);

    expect($ancora->json('data'))->not->toHaveKey('eliminazione_annullata');
});

it('l\'accesso di chi non aveva chiesto niente non porta eliminazione_annullata (#1961)', function () {
    $finto = Zeiras\Auth\Testing\BackofficeFinto::attiva();
    $finto->persona('paolo@example.com', PASSWORD, nome: 'Paolo');

    expect(entraNelFinto('paolo@example.com'))->not->toHaveKey('eliminazione_annullata');
});

it('oltre cinque richieste in un\'ora è 429 troppe_richieste con Retry-After (#1961)', function () {
    [$finto, $zelda, $gettone] = chiedeDiEliminare();
    $finto->workspace('Studio', $zelda);

    // Con una proprietà da trasferire ogni chiamata passa dal freno e finisce in 409: la sesta è 429.
    foreach (range(1, 5) as $_) {
        expect(alFinto('DELETE', '/v1/io', gettone: $gettone)->status())->toBe(409);
    }

    $sesta = alFinto('DELETE', '/v1/io', gettone: $gettone);

    expect($sesta->status())->toBe(429)
        ->and($sesta->json('codice'))->toBe('troppe_richieste')
        ->and((int) $sesta->header('Retry-After'))->toBeGreaterThan(0);
});
