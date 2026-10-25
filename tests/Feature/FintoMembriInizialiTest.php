<?php

// #2012 (C.2): `membri` di io.workspace.crea nel finto. Qui ciò che un frontend vede dal suo lato; che le risposte e gli errori siano
// quelli del backoffice, byte per byte, lo prova il FintoTest del backoffice.

/** Una proprietaria con uno studio, una collega membro dello stesso studio e una persona di un'altra azienda: [finto, studio, collega, estranea, gettone dell'accesso, proprietaria]. */
function conLaCollega(): array
{
    $finto = Zeiras\Auth\Testing\BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio', $anna);
    $collega = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $finto->membro($studio, $collega, 'membro');
    $estranea = $finto->persona('carla@example.com', PASSWORD, nome: 'Carla');
    $finto->workspace('Altrove', $estranea);

    return [$finto, $studio, $collega, $estranea, entraNelFinto('anna@example.com')['gettone']['gettone'], $anna];
}

it('una collega della stessa azienda riceve un invito con il ruolo dato, mai un ingresso diretto (#2012 C.2)', function () {
    [$finto, $studio, $collega, , $gettone] = conLaCollega();

    $risposta = alFinto('POST', '/v1/io/workspace', ['nome' => 'Secondo', 'azienda_id' => $studio['azienda_id'], 'membri' => [['persona' => $collega['id'], 'ruolo' => 'amministratore']]], $gettone);
    $nuovo = $risposta->json('data.id');

    expect($risposta->status())->toBe(201)
        ->and($finto->ultimoInvito('bruno@example.com'))->not->toBeNull();

    $inviti = alFinto('GET', '/v1/workspace/inviti', gettone: alFinto('POST', '/v1/gettoni', ['workspace_id' => $nuovo], $gettone)->json('data.gettone'));

    expect(collect($inviti->json('data'))->pluck('ruolo', 'email')->all())->toBe(['bruno@example.com' => 'amministratore'])
        ->and(collect(alFinto('GET', '/v1/workspace/membri', gettone: alFinto('POST', '/v1/gettoni', ['workspace_id' => $nuovo], $gettone)->json('data.gettone'))->json('data'))->count())->toBe(1);
});

it('una persona di un\'altra azienda, una che non c\'è e chi crea sono lo stesso 422 su #/membri/N/persona (#2012 C.2)', function () {
    [, $studio, , $estranea, $gettone, $anna] = conLaCollega();

    $risposta = alFinto('POST', '/v1/io/workspace', ['nome' => 'Secondo', 'azienda_id' => $studio['azienda_id'], 'membri' => [
        ['persona' => $estranea['id'], 'ruolo' => 'membro'],
        ['persona' => '01J00000000000000000000000', 'ruolo' => 'membro'],
        ['persona' => $anna['id'], 'ruolo' => 'membro'],
    ]], $gettone);

    expect($risposta->status())->toBe(422)
        ->and(collect($risposta->json('errors'))->pluck('pointer')->all())->toBe(['#/membri/0/persona', '#/membri/1/persona', '#/membri/2/persona'])
        ->and(collect($risposta->json('errors'))->pluck('regola')->unique()->all())->toBe(['valore_non_ammesso'])
        ->and(collect(alFinto('GET', '/v1/io/workspace', gettone: $gettone)->json('data'))->count())->toBe(1);
});

it('una persona che sta solo in un workspace dell\'azienda che chi chiama non vede è lo stesso 422; con un workspace in comune è ammessa (nota 16350)', function () {
    [$finto, $studio, , , $gettone, $anna] = conLaCollega();
    $paolo = $finto->persona('paolo@example.com', PASSWORD, nome: 'Paolo');
    $altro = $finto->workspace('Altro studio', $paolo, $studio['azienda_id']);
    $eva = $finto->persona('eva@example.com', PASSWORD, nome: 'Eva');
    $finto->membro($altro, $eva, 'membro');
    $corpo = fn (array $persona) => ['nome' => 'Secondo', 'azienda_id' => $studio['azienda_id'], 'membri' => [['persona' => $persona['id'], 'ruolo' => 'membro']]];

    $risposta = alFinto('POST', '/v1/io/workspace', $corpo($eva), $gettone);

    expect($risposta->status())->toBe(422)
        ->and($risposta->json('errors.0.pointer'))->toBe('#/membri/0/persona')
        ->and($risposta->json('errors.0.regola'))->toBe('valore_non_ammesso');

    $finto->membro($altro, $anna, 'membro');

    expect(alFinto('POST', '/v1/io/workspace', $corpo($eva), $gettone)->status())->toBe(201);
});

it('senza azienda_id membri è 422 campo_vietato su #/membri (#2012 C.2)', function () {
    [, , $collega, , $gettone] = conLaCollega();

    $risposta = alFinto('POST', '/v1/io/workspace', ['nome' => 'Secondo', 'membri' => [['persona' => $collega['id'], 'ruolo' => 'membro']]], $gettone);

    expect($risposta->status())->toBe(422)
        ->and($risposta->json('errors.0.pointer'))->toBe('#/membri')
        ->and($risposta->json('errors.0.regola'))->toBe('campo_vietato');
});

it('un campo di un membro che non è persona o ruolo è 422 campo_estraneo sul suo pointer (#2012 C.2)', function () {
    [, $studio, $collega, , $gettone] = conLaCollega();

    $risposta = alFinto('POST', '/v1/io/workspace', ['nome' => 'Secondo', 'azienda_id' => $studio['azienda_id'], 'membri' => [['persona' => $collega['id'], 'ruolo' => 'membro', 'email' => 'x@example.com']]], $gettone);

    expect($risposta->status())->toBe(422)
        ->and($risposta->json('errors.0.pointer'))->toBe('#/membri/0/email')
        ->and($risposta->json('errors.0.regola'))->toBe('campo_estraneo');
});

it('il ruolo proprietario, una persona ripetuta e più di venti membri sono 422 (#2012 C.2)', function () {
    [, $studio, $collega, , $gettone] = conLaCollega();
    $crea = fn (array $membri) => alFinto('POST', '/v1/io/workspace', ['nome' => 'Secondo', 'azienda_id' => $studio['azienda_id'], 'membri' => $membri], $gettone);

    expect($crea([['persona' => $collega['id'], 'ruolo' => 'proprietario']])->json('errors.0.pointer'))->toBe('#/membri/0/ruolo')
        ->and($crea([['persona' => $collega['id'], 'ruolo' => 'membro'], ['persona' => $collega['id'], 'ruolo' => 'membro']])->json('errors.0.regola'))->toBe('duplicato')
        ->and(collect($crea(array_fill(0, 21, ['persona' => $collega['id'], 'ruolo' => 'membro']))->json('errors'))->pluck('pointer'))->toContain('#/membri');
});
