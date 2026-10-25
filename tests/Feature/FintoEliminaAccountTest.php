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
        ->and($risposta->json('data'))->toBe(['richiesta_il' => '2026-10-24T09:00:00.000Z', 'anonimizzazione_il' => '2026-11-23T09:00:00.000Z', 'workspace_eliminati' => []])
        ->and($risposta->header('Link'))->toBe(linkDi('io.elimina'))
        ->and(alFinto('GET', '/v1/io', gettone: $gettone)->status())->toBe(401);
});

it('una proprietaria è 409 proprieta_da_trasferire con i workspace accanto al codice, e non cambia niente (#1961)', function () {
    [$finto, $zelda, $gettone] = chiedeDiEliminare();
    $studio = $finto->workspace('Studio di Zelda', $zelda);
    $finto->membro($studio, $finto->persona('paolo@example.com', PASSWORD, nome: 'Paolo'), 'membro');

    $risposta = alFinto('DELETE', '/v1/io', gettone: $gettone);

    expect($risposta->status())->toBe(409)
        ->and($risposta->json('codice'))->toBe('proprieta_da_trasferire')
        ->and($risposta->json('workspace_da_trasferire'))->toBe([['id' => $studio['id'], 'nome' => 'Studio di Zelda']])
        ->and(alFinto('GET', '/v1/io', gettone: $gettone)->status())->toBe(200);
});

it('un workspace con l\'eliminazione programmata non è da trasferire: il 409 elenca solo quello vivo (nota 16347 punto 1)', function () {
    [$finto, $zelda, $gettone] = chiedeDiEliminare();
    $morente = $finto->workspace('Studio che se ne va', $zelda);
    $vivo = $finto->workspace('Studio che resta', $zelda);
    $finto->membro($vivo, $finto->persona('paolo@example.com', PASSWORD, nome: 'Paolo'), 'membro');
    $delMorente = alFinto('POST', '/v1/gettoni', ['workspace_id' => $morente['id']], $gettone)->json('data.gettone');
    expect(alFinto('DELETE', '/v1/workspace', gettone: $delMorente)->status())->toBe(202);

    $risposta = alFinto('DELETE', '/v1/io', gettone: $gettone);

    expect($risposta->status())->toBe(409)
        ->and($risposta->json('workspace_da_trasferire'))->toBe([['id' => $vivo['id'], 'nome' => 'Studio che resta']]);
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
    $studio = $finto->workspace('Studio', $zelda);
    $finto->membro($studio, $finto->persona('ugo@example.com', PASSWORD, nome: 'Ugo'), 'membro');

    // Con una proprietà da trasferire (un altro membro: da sola, Zelda se ne va con il workspace) ogni chiamata passa dal freno e finisce in 409: la sesta è 429.
    foreach (range(1, 5) as $_) {
        expect(alFinto('DELETE', '/v1/io', gettone: $gettone)->status())->toBe(409);
    }

    $sesta = alFinto('DELETE', '/v1/io', gettone: $gettone);

    expect($sesta->status())->toBe(429)
        ->and($sesta->json('codice'))->toBe('troppe_richieste')
        ->and((int) $sesta->header('Retry-After'))->toBeGreaterThan(0);
});

it('il workspace in cui è l\'unico account non blocca: si elimina con l\'account, alla stessa data, e chi rientra lo ritrova (Luciano 24/10)', function () {
    $this->travelTo('2026-10-24 09:00:00');
    [$finto, $zelda, $gettone] = chiedeDiEliminare();
    $solo = $finto->workspace('Studio solo', $zelda);
    // Il gettone di Zelda muore col 202: la scadenza programmata si legge dal finto.
    $programmate = fn (): array => (new ReflectionProperty($finto, 'eliminazioni'))->getValue($finto);

    $risposta = alFinto('DELETE', '/v1/io', gettone: $gettone);

    expect($risposta->status())->toBe(202)
        ->and($risposta->json('data.workspace_eliminati'))->toBe([['id' => $solo['id'], 'nome' => 'Studio solo']])
        ->and(array_map(fn ($quando) => $quando->toIso8601String(), $programmate()))->toBe([$solo['id'] => '2026-11-23T09:00:00+00:00']);

    // Il rientro entro i trenta giorni lo ritrova.
    entraNelFinto('zelda@example.com');

    expect($programmate())->toBe([]);
});

it('il rientro non annulla l\'eliminazione che Zelda aveva chiesto a mano (Luciano 24/10)', function () {
    $this->travelTo('2026-10-24 09:00:00');
    [$finto, $zelda, $gettone] = chiedeDiEliminare();
    $voluto = $finto->workspace('Studio che se ne andava già', $zelda);
    $finto->workspace('Studio solo', $zelda);
    $delVoluto = alFinto('POST', '/v1/gettoni', ['workspace_id' => $voluto['id']], $gettone)->json('data.gettone');
    expect(alFinto('DELETE', '/v1/workspace', gettone: $delVoluto)->status())->toBe(202)
        ->and(alFinto('DELETE', '/v1/io', gettone: $gettone)->status())->toBe(202);

    entraNelFinto('zelda@example.com');

    expect(array_keys((new ReflectionProperty($finto, 'eliminazioni'))->getValue($finto)))->toBe([$voluto['id']]);
});
