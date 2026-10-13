<?php

use Illuminate\Http\Client\Response;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Freno;
use Zeiras\Auth\Sessione;
use Zeiras\Auth\Testing\BackofficeFinto;

// #1911 (T4, R.4): il freno delle importazioni del CRM nel finto, con RateLimit-Policy e RateLimit come il backoffice
// (Importazioni::frena e intestazioni, #1894). Che i valori siano quelli del backoffice lo prova il suo FintoTest.

const POLITICHE_DELLE_IMPORTAZIONI = '"chiamate_persona";q=300;w=3600, "prova_persona";q=30000;w=3600, "prova_workspace";q=60000;w=3600, '
    .'"scrittura_persona";q=12000;w=3600, "scrittura_workspace";q=24000;w=3600';

/** Il residuo di RateLimit, freno per freno: [r, t]. @return array<string, array{int, int}> */
function residui(Response $risposta): array
{
    preg_match_all('/"([a-z_]+)";r=(\d+);t=(\d+)/', $risposta->header('RateLimit'), $voci, PREG_SET_ORDER);

    return array_combine(array_column($voci, 1), array_map(fn (array $voce) => [(int) $voce[2], (int) $voce[3]], $voci));
}

/** Uno studio col CRM: anna proprietaria, bruno amministratore, carla membro, e i loro gettoni del workspace. [finto, gettoni, studio] */
function studioColCrm(): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio', $anna);
    $finto->membro($studio, $finto->persona('bruno@example.com', PASSWORD, 'Bruno'), 'amministratore');
    $finto->membro($studio, $finto->persona('carla@example.com', PASSWORD, 'Carla'), 'membro');
    $finto->attivaApp($studio, 'crm');
    $gettoni = [];

    foreach (['anna', 'bruno', 'carla'] as $nome) {
        $gettoni[$nome] = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto("{$nome}@example.com")['gettone']['gettone'])->json('data.gettone');
    }

    return [$finto, $gettoni, $studio];
}

it('le importazioni del CRM portano RateLimit-Policy e RateLimit dopo ruolo e app, già scesi delle righe della chiamata (#1911, R.4)', function () {
    $this->freezeTime();
    [, $gettoni] = studioColCrm();

    $prova = alFinto('POST', '/v1/crm/persone/importazioni', ['prova' => true, 'righe' => [['nome' => 'A'], ['nome' => 'B'], ['nome' => 'C']]], $gettoni['anna']);

    expect($prova->status())->toBe(200)
        ->and($prova->header('RateLimit-Policy'))->toBe(POLITICHE_DELLE_IMPORTAZIONI)
        ->and($prova->header('RateLimit'))->toBe('"chiamate_persona";r=299;t=3600, "prova_persona";r=29997;t=3600, "prova_workspace";r=59997;t=3600, '
            .'"scrittura_persona";r=12000;t=0, "scrittura_workspace";r=24000;t=0');

    // Le righe vere sono un altro freno; il freno del workspace è di tutti i suoi membri, quello della persona no.
    $vera = alFinto('POST', '/v1/crm/organizzazioni/importazioni', ['righe' => [['nome' => 'Rossi Srl'], ['nome' => 'Verdi Snc']]], $gettoni['bruno']);

    expect($vera->status())->toBe(201)
        ->and(residui($vera))->toBe(['chiamate_persona' => [299, 3600], 'prova_persona' => [30000, 0], 'prova_workspace' => [59997, 3600], 'scrittura_persona' => [11998, 3600], 'scrittura_workspace' => [23998, 3600]]);

    // Un corpo che non va è 422 col residuo, e non conta; il ruolo e l'app vengono prima, senza le intestazioni.
    $vuota = alFinto('POST', '/v1/crm/persone/importazioni', ['righe' => []], $gettoni['anna']);

    expect($vuota->status())->toBe(422)
        ->and(residui($vuota)['chiamate_persona'])->toBe([299, 3600])
        ->and(residui($vuota)['prova_persona'])->toBe([29997, 3600]);

    $membro = alFinto('POST', '/v1/crm/persone/importazioni', ['righe' => [['nome' => 'X']]], $gettoni['carla']);

    expect($membro->status())->toBe(403)
        ->and($membro->header('RateLimit-Policy'))->toBe('')
        ->and($membro->header('RateLimit'))->toBe('');
});

it('la risposta ricordata con la Idempotency-Key ha il residuo di adesso, e non conta di nuovo (#1911, R.4)', function () {
    $this->freezeTime();
    [, $gettoni] = studioColCrm();
    $chiave = ['Idempotency-Key' => 'import-1'];
    $corpo = ['righe' => [['nome' => 'Rossi Srl']]];

    $prima = alFinto('POST', '/v1/crm/organizzazioni/importazioni', $corpo, $gettoni['anna'], intestazioni: $chiave);
    alFinto('POST', '/v1/crm/organizzazioni/importazioni', ['righe' => [['nome' => 'Verdi Snc']]], $gettoni['anna']);
    $ricordata = alFinto('POST', '/v1/crm/organizzazioni/importazioni', $corpo, $gettoni['anna'], intestazioni: $chiave);

    expect($prima->status())->toBe(201)->and(residui($prima)['scrittura_persona'])->toBe([11999, 3600])
        ->and($ricordata->status())->toBe(201)->and($ricordata->json())->toBe($prima->json())
        ->and($ricordata->header('Location'))->toBe($prima->header('Location'))
        ->and(residui($ricordata)['scrittura_persona'])->toBe([11998, 3600])
        ->and(residui($ricordata)['chiamate_persona'])->toBe([298, 3600]);
});

it('oltre il tetto è 429 col freno che ha fermato, Retry-After e il residuo, e non consuma niente; il client lo legge in freni() (#1911, R.4, R.1)', function () {
    $this->freezeTime();
    [, $gettoni, $studio] = studioColCrm();

    for ($chiamata = 1; $chiamata <= 300; $chiamata++) {
        $risposta = alFinto('POST', '/v1/crm/persone/importazioni', ['prova' => true, 'righe' => [['nome' => 'A']]], $gettoni['anna']);
        expect($risposta->status())->toBe(200);
    }

    expect(residui($risposta)['chiamate_persona'])->toBe([0, 3600]);

    // La trecentunesima, dal client di zr-auth con la sessione di anna nel finto.
    $accesso = entraNelFinto('anna@example.com');
    Sessione::apri($accesso);
    Sessione::entra(alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso['gettone']['gettone'])->json('data'));
    $api = Api::workspace();

    expect(fn () => $api->post('/v1/crm/persone/importazioni', ['prova' => true, 'righe' => [['nome' => 'A'], ['nome' => 'B']]]))
        ->toThrow(fn (ErroreApi $e) => expect($e->stato)->toBe(429)->and($e->codice)->toBe('troppe_richieste')
            ->and($e->estensioni)->toBe(['freno' => 'chiamate_persona'])->and($e->riprovaFra)->toBe(3600))
        ->and($api->freni()->freno('chiamate_persona'))->toEqual(new Freno('chiamate_persona', q: 300, w: 3600, r: 0, t: 3600))
        ->and($api->freni()->freno('prova_persona')->r)->toBe(29700);
});
