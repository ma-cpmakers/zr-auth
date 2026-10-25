<?php

// #2542: workspace.proprietario.modifica nel finto, «Trasferisci la proprietà». Qui ciò che un frontend vede dal suo lato; che le risposte e gli
// errori siano quelli del backoffice, byte per byte, lo prova il FintoTest del backoffice.

/** Un workspace con la proprietaria Anna, Bruno membro e il gettone di Anna nel workspace: [finto, studio, anna, bruno, gettone di Anna, gettone di Bruno]. */
function conIlProprietario(): array
{
    $finto = Zeiras\Auth\Testing\BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $studio = $finto->workspace('Studio di Anna', $anna);
    $bruno = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $finto->membro($studio, $bruno, 'membro');
    $gettoneDi = fn (string $email) => alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto($email)['gettone']['gettone'])->json('data.gettone');

    return [$finto, $studio, $anna, $bruno, $gettoneDi('anna@example.com'), $gettoneDi('bruno@example.com')];
}

it('il proprietario passa la proprietà: 200 col nuovo proprietario, chi chiamava è amministratore e non può più farlo (#2542)', function () {
    [, , $anna, $bruno, $gettoneAnna, $gettoneBruno] = conIlProprietario();

    $risposta = alFinto('PATCH', '/v1/workspace/proprietario', ['utente' => $bruno['id']], $gettoneAnna);

    expect($risposta->status())->toBe(200)
        ->and($risposta->header('Link'))->toBe(linkDi('workspace.proprietario.modifica'))
        ->and($risposta->json('data.id'))->toBe($bruno['id'])
        ->and($risposta->json('data.ruolo'))->toBe('proprietario');

    $ruoli = collect(alFinto('GET', '/v1/workspace/membri', gettone: $gettoneBruno)->json('data'))->pluck('ruolo', 'id')->all();

    expect($ruoli)->toBe([$anna['id'] => 'amministratore', $bruno['id'] => 'proprietario'])
        ->and(alFinto('PATCH', '/v1/workspace/proprietario', ['utente' => $anna['id']], $gettoneAnna)->json('codice'))->toBe('permesso_negato')
        ->and(alFinto('PATCH', '/v1/workspace/proprietario', ['utente' => $anna['id']], $gettoneBruno)->status())->toBe(200);
});

it('un amministratore e un membro sono 403 permesso_negato; chi non è membro e chi è già il proprietario sono 422 su #/utente (#2542)', function () {
    [$finto, , $anna, $bruno, $gettoneAnna, $gettoneBruno] = conIlProprietario();
    $estranea = $finto->persona('carla@example.com', PASSWORD, nome: 'Carla');

    $membro = alFinto('PATCH', '/v1/workspace/proprietario', ['utente' => $bruno['id']], $gettoneBruno);
    $estranea = alFinto('PATCH', '/v1/workspace/proprietario', ['utente' => $estranea['id']], $gettoneAnna);
    $inventato = alFinto('PATCH', '/v1/workspace/proprietario', ['utente' => '01k6r4c9f2h6k0n4r8v2x6z0b4'], $gettoneAnna);
    $gia = alFinto('PATCH', '/v1/workspace/proprietario', ['utente' => $anna['id']], $gettoneAnna);

    expect($membro->status())->toBe(403)->and($membro->json('codice'))->toBe('permesso_negato')
        ->and($estranea->status())->toBe(422)->and($estranea->json('errors.0.pointer'))->toBe('#/utente')->and($estranea->json('errors.0.regola'))->toBe('non_trovato')
        ->and($inventato->json('errors.0.detail'))->toBe($estranea->json('errors.0.detail'))
        ->and($gia->status())->toBe(422)->and($gia->json('errors.0.pointer'))->toBe('#/utente')->and($gia->json('errors.0.regola'))->toBe('valore_non_ammesso');
});

it('un workspace in eliminazione è 409 workspace_in_eliminazione, e il corpo senza utente è 422 su #/utente (#2542)', function () {
    [$finto, $studio, $anna, $bruno, $gettoneAnna] = conIlProprietario();

    expect(alFinto('PATCH', '/v1/workspace/proprietario', [], $gettoneAnna)->json('errors.0.pointer'))->toBe('#/utente');

    // Anna ha un altro workspace: eliminare l'ultimo sarebbe 409 ultimo_workspace.
    $finto->workspace('Altro studio', $anna);

    expect(alFinto('DELETE', '/v1/workspace', gettone: $gettoneAnna)->status())->toBe(202);
    $risposta = alFinto('PATCH', '/v1/workspace/proprietario', ['utente' => $bruno['id']], $gettoneAnna);

    expect($risposta->status())->toBe(409)->and($risposta->json('codice'))->toBe('workspace_in_eliminazione');
});
