<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #2596 (io.workspace.elimina) e #2598 (workspace.contenuti.mostra) nel finto. Qui ciò che un frontend vede dal suo lato; che le risposte e
// gli errori siano quelli del backoffice, byte per byte, lo prova il FintoTest del backoffice.

/**
 * Anna proprietaria di «Studio», con Bruno amministratore (e proprietario di «Di Bruno»), Carla membro (e proprietaria di «Di Carla») e Dario
 * membro che in Zeiras non ha altro. [finto, studio, accessi per email (entra e dà il gettone senza workspace)].
 *
 * @return array{BackofficeFinto, array<string, mixed>, Closure}
 */
function conUscita(): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio', $anna);

    foreach (['bruno' => ['Bruno', 'amministratore'], 'carla' => ['Carla', 'membro'], 'dario' => ['Dario', 'membro']] as $chi => [$nome, $ruolo]) {
        $persona = $finto->persona("{$chi}@example.com", PASSWORD, nome: $nome);
        $finto->membro($studio, $persona, $ruolo);

        if ($chi !== 'dario') {
            $finto->workspace("Di {$nome}", $persona);
        }
    }

    return [$finto, $studio, fn (string $chi) => entraNelFinto("{$chi}@example.com")['gettone']['gettone']];
}

it('io.workspace.elimina: la persona lascia il workspace con qualunque suo gettone (204), il gettone di quel workspace è 401 e gli altri restano (#2596)', function () {
    [, $studio, $accesso] = conUscita();
    $carla = $accesso('carla');
    $carlaQui = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $carla)->json('data.gettone');

    $risposta = alFinto('DELETE', "/v1/io/workspace/{$studio['id']}", gettone: $carla);

    expect($risposta->status())->toBe(204)
        ->and($risposta->body())->toBe('')
        ->and(alFinto('GET', '/v1/io', gettone: $carlaQui)->json('codice'))->toBe('gettone_non_valido')
        ->and(alFinto('GET', '/v1/io', gettone: $carla)->status())->toBe(200)
        ->and(collect(alFinto('GET', '/v1/io/workspace', gettone: $carla)->json('data'))->pluck('nome')->all())->toBe(['Di Carla'])
        // Una seconda volta il workspace non è più suo.
        ->and(alFinto('DELETE', "/v1/io/workspace/{$studio['id']}", gettone: $carla)->status())->toBe(404);
});

it('io.workspace.elimina risponde nell\'ordine del backoffice: il corpo (422), un workspace non suo (404), poi l\'ostacolo (409 col suo codice) (#2596)', function () {
    [, $studio, $accesso] = conUscita();
    $lascia = fn (string $chi, string $workspace, ?array $corpo = null) => alFinto('DELETE', "/v1/io/workspace/{$workspace}", $corpo, $accesso($chi));
    $esito = fn (Illuminate\Http\Client\Response $risposta) => [$risposta->status(), $risposta->json('codice')];

    expect($lascia('carla', $studio['id'], ['x' => 1])->status())->toBe(422)
        // Un 404 uguale per un id inventato e per un workspace di cui non è membro.
        ->and($lascia('carla', 'inventato')->status())->toBe(404)
        ->and($lascia('dario', alFinto('GET', '/v1/io/workspace', gettone: $accesso('bruno'))->json('data.0.id'))->status())->toBe(404)
        ->and($esito($lascia('anna', $studio['id'])))->toBe([409, 'proprieta_da_trasferire'])
        ->and($esito($lascia('dario', $studio['id'])))->toBe([409, 'ultimo_workspace'])
        ->and($esito($lascia('bruno', $studio['id'])))->toBe([409, 'unico_amministratore']);
});

it('io.workspace.elimina: con un secondo amministratore l\'amministratore esce, e uscita_negata nell\'elenco lo diceva (#2596)', function () {
    [$finto, $studio, $accesso] = conUscita();
    $eli = $finto->persona('eli@example.com', PASSWORD, nome: 'Eli');
    $finto->membro($studio, $eli, 'amministratore');
    $finto->workspace('Di Eli', $eli);
    $negata = fn (string $chi) => collect(alFinto('GET', '/v1/io/workspace?limite=100', gettone: $accesso($chi))->json('data'))->firstWhere('id', $studio['id'])['uscita_negata'];

    expect($negata('bruno'))->toBeNull()
        ->and($negata('anna'))->toBe('proprieta_da_trasferire')
        ->and($negata('dario'))->toBe('ultimo_workspace')
        ->and(alFinto('DELETE', "/v1/io/workspace/{$studio['id']}", gettone: $accesso('bruno'))->status())->toBe(204);
});

it('workspace.contenuti.mostra: quanto contiene il workspace, a proprietario e amministratori; con l\'app pm spenta è vuoto (#2598)', function () {
    [$finto, $studio, $accesso] = conUscita();
    $gettone = fn (string $chi) => alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso($chi))->json('data.gettone');
    $anna = $gettone('anna');

    $spenta = alFinto('GET', '/v1/workspace/contenuti', gettone: $anna);

    expect($spenta->status())->toBe(200)->and($spenta->json('data'))->toBe([])
        ->and(alFinto('GET', '/v1/workspace/contenuti', gettone: $gettone('carla'))->json('codice'))->toBe('permesso_negato')
        ->and(alFinto('GET', '/v1/workspace/contenuti')->json('codice'))->toBe('gettone_assente');

    $finto->attivaApp($studio, 'pm');
    $cartella = $finto->cartella($studio, 'Uno');
    $viva = $finto->board($studio, 'Viva', $cartella);
    $chiusa = $finto->board($studio, 'Chiusa', $cartella, 'chiusa');
    $nelCestino = $finto->board($studio, 'Cestino', $cartella, 'cestino');
    $finto->scheda($studio, 'Una', $viva['id']);
    $finto->scheda($studio, 'Due', $chiusa['id']);
    $finto->scheda($studio, 'Tre', $nelCestino['id']);

    $piena = alFinto('GET', '/v1/workspace/contenuti', gettone: $anna);

    // Le board nel cestino e le loro schede non contano: sono già fuori dalla vista.
    expect($piena->json('data'))->toBe(['cartelle' => 1, 'board' => 2, 'schede' => 2])
        ->and(str_contains((string) $piena->header('Cache-Control'), 'no-store'))->toBeTrue()
        ->and(alFinto('GET', '/v1/workspace/contenuti', gettone: $gettone('bruno'))->status())->toBe(200);
});
