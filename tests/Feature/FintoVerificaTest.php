<?php

use Illuminate\Http\Client\Response;
use Zeiras\Auth\Testing\BackofficeFinto;

// T2.6 e T2.7 (#1170, chiesti da zr-home il 05/10): la verifica dell'email nel finto, coi freni del backoffice.

/** Un codice di 6 cifre diverso da quello dato: un codice sbagliato di sicuro. */
function codiceSbagliato(string $codice): string
{
    return $codice === '000000' ? '111111' : '000000';
}

function chiediCodice(string $email, ?string $password = PASSWORD): Response
{
    return alFinto('POST', '/v1/io/email/codice', ['email' => $email, 'password' => $password]);
}

function verifica(string $email, ?string $codice, string $password = PASSWORD, ?string $lingua = null): Response
{
    return alFinto('POST', '/v1/io/email/verifica', ['email' => $email, 'password' => $password, 'codice' => $codice], lingua: $lingua);
}

const VERIFICA_NON_RIUSCITA_IT = ['Verifica non riuscita',
    "Il codice non verifica la richiesta: per l'email controlla il codice, l'email e la password, o chiedi un codice nuovo; per la password controlla il codice e l'email, o chiedi un codice nuovo; per l'ingresso in un'app riparti dall'accesso; per un invito controlla il codice e di usare l'email a cui è arrivato, o chiedine uno nuovo a chi ti ha invitato."];

// T2.6

it('persona(verificata: false) nasce con l\'email da verificare e riceve il primo codice; ultimoCodice() fa da casella di posta (T2.6)', function () {
    $finto = BackofficeFinto::attiva();

    $bruno = $finto->persona(' Bruno@Example.com', PASSWORD, nome: 'Bruno', verificata: false);

    expect($bruno['email_verificata_il'])->toBeNull()
        ->and($bruno['email'])->toBe('bruno@example.com')
        ->and($finto->ultimoCodice('BRUNO@example.com '))->toMatch('/^[0-9]{6}$/')
        ->and($finto->ultimoCodice('nessuno@example.com'))->toBeNull()
        // Prima della verifica la password non apre un accesso.
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'bruno@example.com', 'password' => PASSWORD])->json('codice'))
        ->toBe('credenziali_non_valide');
});

it('io.email.codice.crea risponde 202 con l\'email normalizzata, la stessa risposta in ogni caso (T2.6)', function () {
    $finto = BackofficeFinto::attiva();
    $this->freezeTime();
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno', verificata: false);
    $finto->persona('anna@example.com', PASSWORD);
    $primo = $finto->ultimoCodice('bruno@example.com');
    $this->travel(60)->seconds();

    $giusta = chiediCodice(' Bruno@Example.COM ');
    $secondo = $finto->ultimoCodice('bruno@example.com');
    $this->travel(60)->seconds();
    $sbagliata = chiediCodice('bruno@example.com', 'una password sbagliata');
    $senzaAccount = chiediCodice('nessuno@example.com');
    $giaVerificata = chiediCodice('anna@example.com');

    expect($giusta->status())->toBe(202)
        ->and($giusta->header('Content-Type'))->toBe('application/json')
        ->and($giusta->header('Link'))->toBe(linkDi('io.email.codice.crea'))
        ->and($giusta->json())->toBe(['data' => ['email' => 'bruno@example.com']])
        ->and($sbagliata->status())->toBe(202)
        ->and($sbagliata->body())->toBe($giusta->body())
        ->and($sbagliata->headers())->toBe($giusta->headers())
        ->and($senzaAccount->status())->toBe(202)
        ->and($senzaAccount->json())->toBe(['data' => ['email' => 'nessuno@example.com']])
        ->and($senzaAccount->headers())->toBe($giusta->headers())
        ->and($giaVerificata->status())->toBe(202)
        ->and($giaVerificata->headers())->toBe($giusta->headers())
        // Il codice è partito solo alla password giusta dell'account da verificare.
        ->and($secondo)->not->toBe($primo)
        ->and($finto->ultimoCodice('bruno@example.com'))->toBe($secondo)
        ->and($finto->ultimoCodice('nessuno@example.com'))->toBeNull()
        ->and($finto->ultimoCodice('anna@example.com'))->toBeNull();
});

it('il codice nuovo sostituisce quello di prima, che da lì non vale più (T2.6)', function () {
    $finto = BackofficeFinto::attiva();
    $this->freezeTime();
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno', verificata: false);
    $primo = $finto->ultimoCodice('bruno@example.com');
    $this->travel(60)->seconds();
    chiediCodice('bruno@example.com');
    $secondo = $finto->ultimoCodice('bruno@example.com');

    expect($secondo)->not->toBe($primo)
        ->and(verifica('bruno@example.com', $primo)->json())->toBe(problemaAtteso('verifica_non_riuscita', 422, ...VERIFICA_NON_RIUSCITA_IT))
        ->and(verifica('bruno@example.com', $secondo)->status())->toBe(200);
});

it('fra un invio e l\'altro 60 secondi, al più 5 invii in un\'ora e 10 in un giorno, contando quello della nascita (T2.6)', function () {
    $finto = BackofficeFinto::attiva();
    $this->freezeTime();
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno', verificata: false);
    $parte = function () use ($finto): bool {
        $prima = $finto->ultimoCodice('bruno@example.com');
        expect(chiediCodice('bruno@example.com')->status())->toBe(202);

        return $finto->ultimoCodice('bruno@example.com') !== $prima;
    };

    $this->travel(59)->seconds();
    expect($parte())->toBeFalse();                    // il primo invio, alla nascita, ha 59 secondi
    $this->travel(1)->seconds();
    expect($parte())->toBeTrue();                     // 2 nell'ora
    foreach (range(3, 5) as $invio) {
        $this->travel(60)->seconds();
        expect($parte())->toBeTrue();                 // 3, 4, 5 nell'ora
    }
    $this->travel(60)->seconds();
    expect($parte())->toBeFalse();                    // il sesto nella stessa ora no

    $this->travel(3600 - 300)->seconds();             // l'ora della nascita è finita
    foreach (range(6, 10) as $invio) {
        expect($parte())->toBeTrue();                 // 6-10 nel giorno
        $this->travel(60)->seconds();
    }
    $this->travel(3600)->seconds();                   // un'ora nuova, nello stesso giorno
    expect($parte())->toBeFalse();                    // l'undicesimo nel giorno no

    $this->travel(86400 - 3600 - 3600 - 300)->seconds();  // il giorno della nascita è finito
    expect($parte())->toBeTrue();
});

it('un\'email non valida, una password assente o col carattere nullo sono 422 dati_non_validi, coi testi del backoffice (T2.6)', function () {
    BackofficeFinto::attiva();

    $spagnolo = alFinto('POST', '/v1/io/email/codice', ['email' => 'anna@', 'password' => null], lingua: 'es');
    $italiano = alFinto('POST', '/v1/io/email/codice', ['email' => 'anna@example.com', 'password' => PASSWORD_COL_NULLO]);

    // In spagnolo il backoffice non ha i messaggi della validazione: escono in inglese, il ripiego.
    expect($spagnolo->status())->toBe(422)
        ->and($spagnolo->header('Content-Type'))->toBe('application/problem+json')
        ->and($spagnolo->header('Link'))->toBe(linkDi('io.email.codice.crea'))
        ->and($spagnolo->json())->toBe(problemaAtteso('dati_non_validi', 422, 'Datos no válidos',
            'Algunos valores no son válidos: los encuentras en errors, con lo que falla y dónde están.', ['errors' => [
                ['detail' => 'The email field must be a valid email address.', 'pointer' => '#/email'],
                ['detail' => 'The password field is required.', 'pointer' => '#/password'],
            ]]))
        ->and($italiano->json('errors'))
        ->toBe([['detail' => 'Il campo password non può contenere il carattere nullo (U+0000).', 'pointer' => '#/password']]);
});

it('la sesta richiesta di un codice in un minuto per la stessa email è 429, con Retry-After (T2.6)', function () {
    BackofficeFinto::attiva();
    $this->freezeTime();

    foreach (range(1, 5) as $richiesta) {
        expect(chiediCodice('nessuno@example.com')->status())->toBe(202);
    }
    $frenata = chiediCodice('Nessuno@example.com', 'altra');

    expect($frenata->status())->toBe(429)
        ->and($frenata->header('Retry-After'))->toBe('60')
        ->and($frenata->header('Link'))->toBe(linkDi('io.email.codice.crea'))
        ->and($frenata->json())->toBe(problemaAtteso('troppe_richieste', 429, 'Troppe richieste',
            'Troppe richieste in poco tempo: riprova fra 60 secondi.'))
        ->and(chiediCodice('altro@example.com')->status())->toBe(202);
});

// T2.7

it('io.email.verifica.crea col codice giusto verifica l\'email: 200 con la persona, e da lì accessi.crea entra (T2.7)', function () {
    $finto = BackofficeFinto::attiva();
    $this->freezeTime();
    $bruno = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno', lingua: 'en', verificata: false);
    $this->travel(3)->minutes();

    expect(alFinto('POST', '/v1/accessi', ['email' => 'bruno@example.com', 'password' => PASSWORD])->status())->toBe(422);

    $risposta = verifica(' BRUNO@example.com', $finto->ultimoCodice('bruno@example.com'));

    expect($risposta->status())->toBe(200)
        ->and($risposta->header('Content-Type'))->toBe('application/json')
        ->and($risposta->header('Link'))->toBe(linkDi('io.email.verifica.crea'))
        ->and($risposta->json())->toBe(['data' => [...$bruno, 'email_verificata_il' => iso(now())]])
        ->and(entraNelFinto('bruno@example.com')['gettone']['utente'])->toBe($risposta->json('data'))
        // Già verificata: lo stesso 422 di ogni verifica che non riesce, mai un 200.
        ->and(verifica('bruno@example.com', $finto->ultimoCodice('bruno@example.com'))->json('codice'))->toBe('verifica_non_riuscita');
});

it('ogni verifica che non riesce è lo stesso 422 verifica_non_riuscita (T2.7)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno', verificata: false);
    $finto->persona('anna@example.com', PASSWORD);
    $codice = $finto->ultimoCodice('bruno@example.com');

    $risposte = [
        'codice sbagliato' => verifica('bruno@example.com', codiceSbagliato($codice)),
        'password sbagliata' => verifica('bruno@example.com', $codice, 'una password sbagliata'),
        'email senza account' => verifica('nessuno@example.com', $codice),
        'email già verificata' => verifica('anna@example.com', $codice),
    ];

    foreach ($risposte as $risposta) {
        expect($risposta->status())->toBe(422)
            ->and($risposta->header('Content-Type'))->toBe('application/problem+json')
            ->and($risposta->header('Link'))->toBe(linkDi('io.email.verifica.crea'))
            ->and($risposta->json())->toBe(problemaAtteso('verifica_non_riuscita', 422, ...VERIFICA_NON_RIUSCITA_IT))
            ->and($risposta->headers())->toBe($risposte['codice sbagliato']->headers());
    }
    expect(verifica('bruno@example.com', $codice, lingua: 'en')->status())->toBe(200);
});

it('un codice vale 10 minuti dall\'invio (T2.7)', function () {
    $finto = BackofficeFinto::attiva();
    $this->freezeTime();
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno', verificata: false);
    $finto->persona('carla@example.com', PASSWORD, nome: 'Carla', verificata: false);

    $this->travel(10 * 60 - 1)->seconds();
    expect(verifica('bruno@example.com', $finto->ultimoCodice('bruno@example.com'))->status())->toBe(200);
    $this->travel(1)->seconds();
    expect(verifica('carla@example.com', $finto->ultimoCodice('carla@example.com'))->json('codice'))->toBe('verifica_non_riuscita');
});

it('dopo il quinto errore su un codice nemmeno quello giusto verifica; al quinto tentativo sì (T2.7)', function () {
    $finto = BackofficeFinto::attiva();
    $this->freezeTime();
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno', verificata: false);
    $finto->persona('carla@example.com', PASSWORD, nome: 'Carla', verificata: false);
    $diBruno = $finto->ultimoCodice('bruno@example.com');
    $diCarla = $finto->ultimoCodice('carla@example.com');

    foreach (range(1, 5) as $errore) {
        expect(verifica('bruno@example.com', codiceSbagliato($diBruno))->status())->toBe(422);
    }
    foreach (range(1, 4) as $errore) {
        expect(verifica('carla@example.com', codiceSbagliato($diCarla))->status())->toBe(422);
    }
    expect(verifica('carla@example.com', $diCarla)->status())->toBe(200);

    $this->travel(60)->seconds();
    expect(verifica('bruno@example.com', $diBruno)->json('codice'))->toBe('verifica_non_riuscita');
});

it('un tentativo sul codice si conta solo con la password giusta (T2.7)', function () {
    $finto = BackofficeFinto::attiva();
    $this->freezeTime();
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno', verificata: false);
    $codice = $finto->ultimoCodice('bruno@example.com');

    foreach (range(1, 5) as $errore) {
        expect(verifica('bruno@example.com', codiceSbagliato($codice), 'una password sbagliata')->status())->toBe(422);
    }
    $this->travel(60)->seconds();

    expect(verifica('bruno@example.com', $codice)->status())->toBe(200);
});

it('dopo 10 codici sbagliati in un giorno nemmeno il codice giusto verifica, fino al giorno dopo (T2.7)', function () {
    $finto = BackofficeFinto::attiva();
    $this->freezeTime();
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno', verificata: false);
    $sbaglia = function () use ($finto): void {
        foreach (range(1, 5) as $errore) {
            expect(verifica('bruno@example.com', codiceSbagliato($finto->ultimoCodice('bruno@example.com')))->status())->toBe(422);
        }
    };

    $sbaglia();                                        // 5 sul primo codice
    $this->travel(60)->seconds();
    chiediCodice('bruno@example.com');
    $sbaglia();                                        // 10 nel giorno, sul secondo
    $this->travel(60)->seconds();
    chiediCodice('bruno@example.com');
    expect(verifica('bruno@example.com', $finto->ultimoCodice('bruno@example.com'))->json('codice'))->toBe('verifica_non_riuscita');

    $this->travel(86400 - 120)->seconds();
    chiediCodice('bruno@example.com');
    expect(verifica('bruno@example.com', $finto->ultimoCodice('bruno@example.com'))->status())->toBe(200);
});

it('un codice che non è di 6 cifre è 422 dati_non_validi su #/codice (T2.7)', function (mixed $codice, string $dettaglio) {
    $finto = BackofficeFinto::attiva();
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno', verificata: false);

    $risposta = alFinto('POST', '/v1/io/email/verifica', ['email' => 'bruno@example.com', 'password' => PASSWORD, 'codice' => $codice]);

    expect($risposta->status())->toBe(422)
        ->and($risposta->json('codice'))->toBe('dati_non_validi')
        ->and($risposta->json('errors'))->toBe([['detail' => $dettaglio, 'pointer' => '#/codice']]);
})->with([
    'cinque cifre' => ['12345', 'Il campo codice deve avere 6 cifre.'],
    'lettere' => ['12a456', 'Il campo codice deve avere 6 cifre.'],
    'un numero' => [123456, 'Il campo codice deve essere una stringa.'],
    'assente' => [null, 'Il campo codice è obbligatorio.'],
]);

it('la sesta verifica in un minuto per la stessa email è 429, con Retry-After, anche col codice giusto (T2.7)', function () {
    $finto = BackofficeFinto::attiva();
    $this->freezeTime();
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno', verificata: false);
    $codice = $finto->ultimoCodice('bruno@example.com');

    foreach (range(1, 5) as $richiesta) {
        expect(verifica('bruno@example.com', $codice, 'una password sbagliata')->status())->toBe(422);
    }
    $this->travel(20)->seconds();
    $frenata = verifica('bruno@example.com', $codice, lingua: 'es');

    expect($frenata->status())->toBe(429)
        ->and($frenata->header('Retry-After'))->toBe('40')
        ->and($frenata->header('Link'))->toBe(linkDi('io.email.verifica.crea'))
        ->and($frenata->json())->toBe(problemaAtteso('troppe_richieste', 429, 'Demasiadas solicitudes',
            'Demasiadas solicitudes en poco tiempo: vuelve a intentarlo en 40 segundos.'));

    $this->travel(40)->seconds();
    expect(verifica('bruno@example.com', $codice)->status())->toBe(200);
});
