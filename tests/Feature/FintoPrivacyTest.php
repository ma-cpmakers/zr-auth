<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #3053: privacy.cancellazioni.* nel finto, senza gettone. Che le risposte e gli errori siano quelli del backoffice, e che il gate 2 le passi, lo prova il FintoTest del backoffice.

/** @return array<string, mixed> */
function richiestaAlFinto(string $email, array $sovrascrivi = []): array
{
    return array_replace(['email' => $email, 'sito_web' => '', 'compilato_in_ms' => 9000], $sovrascrivi);
}

it('privacy.cancellazioni: la stessa 202 per un\'email che c\'è e una che non c\'è; il codice nasce solo per l\'account (#3053)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);

    $con = alFinto('POST', '/v1/privacy/cancellazioni', richiestaAlFinto('anna@example.com'));
    $senza = alFinto('POST', '/v1/privacy/cancellazioni', richiestaAlFinto('nessuno@example.com'));

    expect($con->status())->toBe(202)->and($con->json())->toBe(['data' => ['accettato' => true]])->and($senza->body())->toBe($con->body())
        ->and($finto->codiceDellaCancellazione('anna@example.com'))->not->toBeNull()
        ->and($finto->codiceDellaCancellazione('nessuno@example.com'))->toBeNull();
});

it('privacy.cancellazioni: mostra, conferma una volta sola, poi lo stato è confermato e il codice non conferma più (#3053)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);
    alFinto('POST', '/v1/privacy/cancellazioni', richiestaAlFinto('anna@example.com'));
    $codice = $finto->codiceDellaCancellazione('anna@example.com');

    $attesa = alFinto('GET', "/v1/privacy/cancellazioni/{$codice}");
    expect($attesa->status())->toBe(200)->and($attesa->json('data.stato'))->toBe('in_attesa_di_conferma')->and($attesa->json('data.definitiva_entro'))->toBeNull();

    $confermata = alFinto('POST', "/v1/privacy/cancellazioni/{$codice}/conferma");
    expect($confermata->status())->toBe(200)->and($confermata->json('data.stato'))->toBe('confermata')->and($confermata->json('data.definitiva_entro'))->not->toBeNull()
        ->and(alFinto('POST', "/v1/privacy/cancellazioni/{$codice}/conferma")->status())->toBe(404)
        ->and(alFinto('GET', "/v1/privacy/cancellazioni/{$codice}")->json('data.stato'))->toBe('confermata')
        ->and(alFinto('GET', '/v1/privacy/cancellazioni/'.str_repeat('a', 43))->json('codice'))->toBe('non_trovato');
});

it('privacy.cancellazioni: una proprietaria con altri account non ha il codice, e la 202 è la stessa (#3053)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $bruno = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $finto->membro($finto->workspace('Studio', $anna), $bruno, 'membro');

    $risposta = alFinto('POST', '/v1/privacy/cancellazioni', richiestaAlFinto('anna@example.com'));

    expect($risposta->status())->toBe(202)->and($finto->codiceDellaCancellazione('anna@example.com'))->toBeNull();
});

it('privacy.cancellazioni: trappola e compilato in fretta sono 202 senza codice; campi sbagliati ed estranei sono 422 sul loro pointer; freno per email (#3053)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);

    expect(alFinto('POST', '/v1/privacy/cancellazioni', richiestaAlFinto('anna@example.com', ['sito_web' => 'x']))->status())->toBe(202)
        ->and(alFinto('POST', '/v1/privacy/cancellazioni', richiestaAlFinto('anna@example.com', ['compilato_in_ms' => 10]))->status())->toBe(202)
        ->and($finto->codiceDellaCancellazione('anna@example.com'))->toBeNull();

    $rifiutata = alFinto('POST', '/v1/privacy/cancellazioni', richiestaAlFinto('non-una-email', ['motivo' => 'x']));
    expect($rifiutata->status())->toBe(422)->and(array_column($rifiutata->json('errors'), 'pointer'))->toContain('#/email', '#/motivo');

    foreach (range(1, 3) as $i) {
        alFinto('POST', '/v1/privacy/cancellazioni', richiestaAlFinto('anna@example.com'));
    }
    $quarta = alFinto('POST', '/v1/privacy/cancellazioni', richiestaAlFinto('anna@example.com'));

    expect($quarta->status())->toBe(429)->and($quarta->json('codice'))->toBe('troppe_richieste');
});
