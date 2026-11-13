<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #3439: contatti.crea nel finto, senza gettone. Che le risposte e gli errori siano quelli del backoffice, e che il gate 2 le passi, lo prova il FintoTest del backoffice.

/** @return array<string, mixed> */
function messaggioAlFinto(array $sovrascrivi = []): array
{
    return array_replace(['nome' => 'Anna Verdi', 'email' => 'Anna.Verdi@example.com', 'messaggio' => 'Ciao', 'argomento' => 1, 'sito_web' => '', 'compilato_in_ms' => 8000], $sovrascrivi);
}

it('contatti.crea: 202 con `accettato` e senza gettone, il messaggio «recapitato» sta in messaggiDeiContatti (#3439)', function () {
    $finto = BackofficeFinto::attiva();

    $risposta = alFinto('POST', '/v1/contatti', messaggioAlFinto());

    expect($risposta->status())->toBe(202)->and($risposta->json())->toBe(['data' => ['accettato' => true]])
        ->and($finto->messaggiDeiContatti())->toBe([['nome' => 'Anna Verdi', 'email' => 'anna.verdi@example.com', 'azienda' => null, 'argomento' => 1, 'messaggio' => 'Ciao', 'lingua' => null]]);
});

it('contatti.crea: la trappola e il compilato in fretta sono 202 identico e non recapitano; i campi sbagliati sono 422 sul loro pointer (#3439)', function () {
    $finto = BackofficeFinto::attiva();

    expect(alFinto('POST', '/v1/contatti', messaggioAlFinto(['sito_web' => 'x']))->status())->toBe(202)
        ->and(alFinto('POST', '/v1/contatti', messaggioAlFinto(['compilato_in_ms' => 2999]))->status())->toBe(202)
        ->and($finto->messaggiDeiContatti())->toBe([]);

    $rifiutata = alFinto('POST', '/v1/contatti', messaggioAlFinto(['argomento' => 5, 'compilato_in_ms' => '8000', 'telefono' => '1']));

    expect($rifiutata->status())->toBe(422)->and(array_column($rifiutata->json('errors'), 'pointer'))->toContain('#/argomento', '#/compilato_in_ms', '#/telefono');
});

it('contatti.crea: freno per email (3 in un\'ora), mai per IP (#3439)', function () {
    $finto = BackofficeFinto::attiva();

    foreach (range(1, 3) as $i) {
        expect(alFinto('POST', '/v1/contatti', messaggioAlFinto())->status())->toBe(202);
    }

    $quarta = alFinto('POST', '/v1/contatti', messaggioAlFinto());

    expect($quarta->status())->toBe(429)->and($quarta->json('codice'))->toBe('troppe_richieste')->and($quarta->header('Retry-After'))->not->toBe('')
        ->and(alFinto('POST', '/v1/contatti', messaggioAlFinto(['email' => 'luca@example.com']))->status())->toBe(202)
        ->and($finto->messaggiDeiContatti())->toHaveCount(4);
});
