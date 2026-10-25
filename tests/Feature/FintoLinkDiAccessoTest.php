<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #2479: il finto di accessi.link.crea. Che le risposte e gli errori siano quelli del backoffice lo prova il FintoTest del backoffice (G67).

it('accessi.link.crea: un link vivo dà 201 con l\'accesso, il gettone e la prenotazione_id; l\'accesso è di via link (#2479, T1.1)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);
    $codice = $finto->link('anna@example.com', '01k6r2s4a8c2e6g0j4m8p2r6t0');

    $entrata = alFinto('POST', '/v1/accessi/link', ['codice' => $codice]);

    expect($entrata->status())->toBe(201)
        ->and($entrata->json('data.prenotazione_id'))->toBe('01k6r2s4a8c2e6g0j4m8p2r6t0')
        ->and(array_keys($entrata->json('data')))->toBe(['id', 'creato_il', 'gettone', 'prenotazione_id'])
        ->and($entrata->json('data.gettone.utente.email'))->toBe('anna@example.com')
        ->and($entrata->json('data.gettone.workspace'))->toBeNull();
});

it('accessi.link.crea: usato, scaduto e sconosciuto sono lo stesso 404 non_trovato, e non consumano (#2479, T1.2, T1.3)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);
    $usato = $finto->link('anna@example.com', '01k6r2s4a8c2e6g0j4m8p2r6t0');
    $scaduto = $finto->link('anna@example.com', '01k6r2s4a8c2e6g0j4m8p2r6t0', now()->toImmutable()->subSecond());

    alFinto('POST', '/v1/accessi/link', ['codice' => $usato])->assertCreated();
    $risposte = array_map(fn (string $codice) => alFinto('POST', '/v1/accessi/link', ['codice' => $codice]), [$usato, $scaduto, str_repeat('a', 43)]);

    expect(array_map(fn ($r) => esito($r), $risposte))->toBe([[404, 'non_trovato'], [404, 'non_trovato'], [404, 'non_trovato']])
        ->and($risposte[1]->body())->toBe($risposte[0]->body())
        ->and($risposte[2]->body())->toBe($risposte[0]->body());
});

it('accessi.link.crea: il codice è nel corpo e di forma base64url di 16-128 caratteri; altrimenti 422 su #/codice (#2479, T1.4)', function (array $corpo) {
    BackofficeFinto::attiva();

    $risposta = alFinto('POST', '/v1/accessi/link', $corpo);

    expect(esito($risposta))->toBe([422, 'dati_non_validi'])
        ->and(array_column($risposta->json('errors'), 'pointer'))->toBe(['#/codice']);
})->with([
    'assente' => [[]],
    'corto' => [['codice' => str_repeat('a', 15)]],
    'lungo' => [['codice' => str_repeat('a', 129)]],
    'fuori alfabeto' => [['codice' => str_repeat('a', 15).'+']],
]);

it('accessi.link.crea: il gettone del link apre solo l\'uscita; ogni altro metodo è 403 permesso_negato (#2479, T1.5)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);
    $gettone = alFinto('POST', '/v1/accessi/link', ['codice' => $finto->link('anna@example.com', '01k6r2s4a8c2e6g0j4m8p2r6t0')])->json('data.gettone.gettone');

    foreach ([['GET', '/v1/io'], ['GET', '/v1/accessi'], ['POST', '/v1/io/workspace']] as [$metodo, $percorso]) {
        expect(esito(alFinto($metodo, $percorso, $metodo === 'POST' ? [] : null, $gettone)))->toBe([403, 'permesso_negato']);
    }

    expect(alFinto('DELETE', '/v1/accessi/corrente', null, $gettone)->status())->toBe(204);
});

it('accessi.link.crea: un gettone di accessi.crea non ha ambito e apre i metodi della persona (#2479, T1.5)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);
    $gettone = alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD])->json('data.gettone.gettone');

    expect(alFinto('GET', '/v1/io', null, $gettone)->status())->toBe(200);
});
