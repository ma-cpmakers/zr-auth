<?php

// #3366: il ruolo `ospite` nel finto, «solo visualizzazione». Qui ciò che un frontend vede dal suo lato; che le risposte e gli errori siano quelli del
// backoffice, byte per byte, lo prova il FintoTest del backoffice.

/** Un workspace con la proprietaria Anna, Bruno ospite e il gettone di ognuno: [finto, studio, anna, bruno, gettone di Anna, gettone di Bruno]. */
function conLOspite(): array
{
    $finto = Zeiras\Auth\Testing\BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $studio = $finto->workspace('Studio di Anna', $anna);
    $bruno = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $finto->membro($studio, $bruno, 'ospite');
    $gettoneDi = fn (string $email) => alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto($email)['gettone']['gettone'])->json('data.gettone');

    return [$finto, $studio, $anna, $bruno, $gettoneDi('anna@example.com'), $gettoneDi('bruno@example.com')];
}

it("l'ospite c'è: si invita, si vede in Membri, si cambia come gli altri ruoli (#3366)", function () {
    [, , , $bruno, $gettoneAnna] = conLOspite();

    $invito = alFinto('POST', '/v1/workspace/inviti', ['email' => 'carla@example.com', 'ruolo' => 'ospite'], $gettoneAnna);
    $ruoli = collect(alFinto('GET', '/v1/workspace/membri', gettone: $gettoneAnna)->json('data'))->pluck('ruolo', 'id')->all();
    $modifica = alFinto('PATCH', "/v1/workspace/membri/{$bruno['id']}", ['ruolo' => 'membro'], $gettoneAnna);

    expect($invito->status())->toBe(201)->and($invito->json('data.ruolo'))->toBe('ospite')
        ->and($ruoli[$bruno['id']])->toBe('ospite')
        ->and($modifica->status())->toBe(200)->and($modifica->json('data.ruolo'))->toBe('membro');
});

it("l'ospite non legge membri e inviti (403 permesso_negato) e il suo profilo porta ruolo ospite (#3366)", function () {
    [, , , , , $gettoneBruno] = conLOspite();

    $membri = alFinto('GET', '/v1/workspace/membri', gettone: $gettoneBruno);
    $inviti = alFinto('GET', '/v1/workspace/inviti', gettone: $gettoneBruno);
    $io = alFinto('GET', '/v1/io', gettone: $gettoneBruno);

    expect($membri->status())->toBe(403)->and($membri->json('codice'))->toBe('permesso_negato')
        ->and($inviti->status())->toBe(403)->and($inviti->json('codice'))->toBe('permesso_negato')
        ->and($io->status())->toBe(200)->and($io->json('data.ruolo'))->toBe('ospite');
});

it("app.elenca: l'ospite riceve solo le app in cui può entrare (pm), gli altri ruoli l'elenco intero, e app.modifica resta 403 (#3591)", function () {
    [, , , , $gettoneAnna, $gettoneBruno] = conLOspite();

    $suo = alFinto('GET', '/v1/app', gettone: $gettoneBruno);
    $intero = alFinto('GET', '/v1/app', gettone: $gettoneAnna);
    $modifica = alFinto('PATCH', '/v1/app/pm', ['stato' => 'disponibile'], $gettoneBruno);

    expect($suo->status())->toBe(200)->and(array_column($suo->json('data'), 'codice'))->toBe(['pm'])->and($suo->json('successivo'))->toBeNull()
        ->and(count($intero->json('data')))->toBeGreaterThan(1)
        ->and($modifica->status())->toBe(403)->and($modifica->json('codice'))->toBe('permesso_negato');
});
