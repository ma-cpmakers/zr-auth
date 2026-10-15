<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #2162 (TE.5): il tetto alle esportazioni del CRM in corso nel finto. Che sia quello del backoffice lo prova il suo FintoTest.

/** Uno studio col CRM: anna proprietaria, carla membro, e i loro gettoni del workspace. [finto, gettoni] */
function studioDelTetto(): array
{
    $finto = BackofficeFinto::attiva();
    $studio = $finto->workspace('Studio', $finto->persona('anna@example.com', PASSWORD));
    $finto->membro($studio, $finto->persona('carla@example.com', PASSWORD, 'Carla'), 'membro');
    $finto->attivaApp($studio, 'crm');
    $gettoni = [];

    foreach (['anna', 'carla'] as $nome) {
        $gettoni[$nome] = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto("{$nome}@example.com")['gettone']['gettone'])->json('data.gettone');
    }

    return [$finto, $gettoni];
}

it('con il tetto occupato le due esportazioni sono 429 troppe_richieste con Retry-After, dopo ruolo e filtri, e senza consumare il freno a ora (TE.5)', function (string $percorso) {
    [$finto, $gettoni] = studioDelTetto();
    $finto->occupaEsportazioni();

    $pieno = alFinto('GET', $percorso, null, $gettoni['anna']);

    expect($pieno->status())->toBe(429)
        ->and($pieno->json('codice'))->toBe('troppe_richieste')
        ->and((int) $pieno->header('Retry-After'))->toBeGreaterThan(0)
        ->and(alFinto('GET', $percorso, null, $gettoni['carla'])->status())->toBe(403)
        ->and(alFinto('GET', $percorso.'?colore=blu', null, $gettoni['anna'])->status())->toBe(422);

    // più rifiuti del freno a ora (10): il tetto non lo ha consumato, e liberato il finto l'esportazione esce
    foreach (range(1, 12) as $_) {
        alFinto('GET', $percorso, null, $gettoni['anna'])->status();
    }

    $finto->occupaEsportazioni(false);
    $libera = alFinto('GET', $percorso, null, $gettoni['anna']);

    expect($libera->status())->toBe(200)->and($libera->header('Content-Type'))->toStartWith('text/csv');
})->with(['/v1/crm/persone/esportazione', '/v1/crm/organizzazioni/esportazione']);
