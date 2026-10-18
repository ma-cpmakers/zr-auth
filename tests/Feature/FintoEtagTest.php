<?php

// #1965 (ET.1, ET.2): le risposte del finto che nel backoffice portano `ETag` lo portano (forte, fra virgolette, opaco); un If-None-Match
// che lo contiene dà 304 senza corpo; crm.attivita.modifica rispetta If-Match (412 se la versione non è più quella, 422 sul valore che
// non è un entity-tag). Che la forma sia quella del backoffice lo prova il suo FintoTest.

use Illuminate\Http\Client\Response;

const FORMA_ETAG = '/^"[0-9a-f]{32}"$/';

/** Una persona, un'azienda, una pipeline con una trattativa e un'attività: [percorso della lettura => id]. */
function oggettiConEtag(string $gettone): array
{
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettone)->json('data.id');
    $persona = alFinto('POST', '/v1/crm/persone', ['nome' => 'Giulia'], $gettone)->json('data.id');
    $pipeline = alFinto('POST', '/v1/crm/pipeline', ['nome' => 'Vendite', 'fasi' => [['nome' => 'Contatto', 'probabilita' => 10]]], $gettone)->json('data.id');
    $trattativa = alFinto('POST', '/v1/crm/trattative', ['titolo' => 'Fornitura', 'pipeline_id' => $pipeline], $gettone)->json('data.id');
    $attivita = alFinto('POST', '/v1/crm/attivita', ['tipo' => 'compito', 'titolo' => 'Richiamare', 'persona_id' => $persona], $gettone)->json('data.id');

    return [
        "/v1/crm/persone/{$persona}" => $persona,
        "/v1/crm/organizzazioni/{$azienda}" => $azienda,
        "/v1/crm/pipeline/{$pipeline}" => $pipeline,
        "/v1/crm/trattative/{$trattativa}" => $trattativa,
        "/v1/crm/attivita/{$attivita}" => $attivita,
    ];
}

function etagDi(Response $risposta): string
{
    expect($risposta->status())->toBeLessThan(300, $risposta->body())
        ->and($risposta->header('ETag'))->toMatch(FORMA_ETAG);

    return $risposta->header('ETag');
}

it('i cinque mostra del CRM portano un ETag forte, uguale a ogni lettura e nuovo quando la risorsa cambia (ET.1, ET.2)', function () {
    [, $gettoni] = studioColCrm();
    $anna = $gettoni['anna'];

    foreach (oggettiConEtag($anna) as $percorso => $id) {
        $prima = etagDi(alFinto('GET', $percorso, null, $anna));

        expect(etagDi(alFinto('GET', $percorso, null, $anna)))->toBe($prima);
    }

    $attivita = array_key_last(oggettiConEtag($anna));
    $prima = etagDi(alFinto('GET', $attivita, null, $anna));
    $modificata = alFinto('PATCH', $attivita, ['titolo' => 'Richiamare domani'], $anna);

    expect(etagDi($modificata))->not->toBe($prima)
        ->and(etagDi(alFinto('GET', $attivita, null, $anna)))->toBe($modificata->header('ETag'));
});

it('If-None-Match con l\'ETag dà 304 senza corpo e con l\'ETag; con un altro, 200 (ET.1)', function () {
    [, $gettoni] = studioColCrm();
    $anna = $gettoni['anna'];

    foreach (oggettiConEtag($anna) as $percorso => $id) {
        $etag = etagDi(alFinto('GET', $percorso, null, $anna));
        $uguale = alFinto('GET', $percorso, null, $anna, null, ['If-None-Match' => $etag]);
        $debole = alFinto('GET', $percorso, null, $anna, null, ['If-None-Match' => 'W/'.$etag]);
        $altro = alFinto('GET', $percorso, null, $anna, null, ['If-None-Match' => '"0000"']);
        $stella = alFinto('GET', $percorso, null, $anna, null, ['If-None-Match' => '*']);
        $lista = alFinto('GET', $percorso, null, $anna, null, ['If-None-Match' => '"0000", '.$etag]);

        expect($uguale->status())->toBe(304)
            ->and($uguale->body())->toBe('')
            ->and($uguale->header('ETag'))->toBe($etag)
            ->and($debole->status())->toBe(304)
            ->and($stella->status())->toBe(304)
            ->and($lista->status())->toBe(304)
            ->and($altro->status())->toBe(200)
            ->and($altro->header('ETag'))->toBe($etag);
    }
});

it('crm.attivita.modifica con If-Match: la versione giusta passa, una vecchia è 412 senza scrivere, * vale per ogni attività (ET.1)', function () {
    [, $gettoni] = studioColCrm();
    $anna = $gettoni['anna'];
    $percorso = array_key_last(oggettiConEtag($anna));
    $letta = etagDi(alFinto('GET', $percorso, null, $anna));

    $giusta = alFinto('PATCH', $percorso, ['titolo' => 'Uno'], $anna, null, ['If-Match' => $letta]);
    $nuova = etagDi($giusta);

    $vecchia = alFinto('PATCH', $percorso, ['titolo' => 'Due'], $anna, null, ['If-Match' => $letta]);
    $debole = alFinto('PATCH', $percorso, ['titolo' => 'Due'], $anna, null, ['If-Match' => 'W/'.$nuova]);
    // Una versione vecchia è 412 anche con un corpo non valido: l'header si guarda prima del corpo.
    $primaDelCorpo = alFinto('PATCH', $percorso, ['titolo' => ''], $anna, null, ['If-Match' => $letta]);
    // La 412 non scrive: l'attività è ancora quella di prima.
    $dopo = alFinto('GET', $percorso, null, $anna);
    $stella = alFinto('PATCH', $percorso, ['titolo' => 'Tre'], $anna, null, ['If-Match' => '*']);

    expect($giusta->status())->toBe(200)
        ->and($giusta->json('data.titolo'))->toBe('Uno')
        ->and($nuova)->not->toBe($letta)
        ->and($vecchia->status())->toBe(412)
        ->and($vecchia->json('codice'))->toBe('precondizione_fallita')
        ->and($debole->status())->toBe(412)
        ->and($primaDelCorpo->status())->toBe(412)
        ->and($dopo->json('data.titolo'))->toBe('Uno')
        ->and($dopo->header('ETag'))->toBe($nuova)
        ->and($stella->status())->toBe(200)
        ->and($stella->json('data.titolo'))->toBe('Tre');
});

it('un If-Match che non è né * né una lista di entity-tag è 422 sull\'header (ET.1)', function () {
    [, $gettoni] = studioColCrm();
    $anna = $gettoni['anna'];
    $percorso = array_key_last(oggettiConEtag($anna));

    $risposta = alFinto('PATCH', $percorso, ['titolo' => 'Uno'], $anna, null, ['If-Match' => 'senza-virgolette']);

    expect($risposta->status())->toBe(422)
        ->and($risposta->json('errors.0.header'))->toBe('If-Match')
        ->and($risposta->json('errors.0.regola'))->toBe('formato');
});

it('un\'attività che non c\'è è 404 con qualunque If-Match o If-None-Match, prima di guardare l\'header (ET.1)', function () {
    [, $gettoni] = studioColCrm();
    $anna = $gettoni['anna'];
    $assente = '/v1/crm/attivita/01m4jsakdxa3nk697es3kgpvjw';

    foreach ([
        alFinto('PATCH', $assente, ['titolo' => 'Uno'], $anna, null, ['If-Match' => '*']),
        alFinto('PATCH', $assente, ['titolo' => 'Uno'], $anna, null, ['If-Match' => 'senza-virgolette']),
        alFinto('GET', $assente, null, $anna, null, ['If-None-Match' => '*']),
    ] as $risposta) {
        expect($risposta->status())->toBe(404)->and($risposta->json('codice'))->toBe('non_trovato');
    }
});
