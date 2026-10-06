<?php

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Zeiras\Auth\Testing\BackofficeFinto;

// T3.1 e T3.2 dello sprint 4 (#1169, D23; per zr-home #1209): la registrazione nel finto, come utenti.crea del
// backoffice. Chiusa di norma, con la lista data dal test (consenti()) o aperta (apri(), con Turnstile acceso);
// Turnstile spento di norma, acceso dal test con la risposta dei tasti di prova di Cloudflare, o guasto. L'ordine è
// quello del backoffice: l'email, il suo freno, Turnstile, la lista, il resto.

/** Il corpo di una registrazione giusta, coi campi che il test cambia. */
function registrazione(array $campi = []): array
{
    return $campi + ['email' => 'anna@example.com', 'password' => PASSWORD, 'termini_accettati' => true];
}

function registra(array $corpo, ?string $lingua = null): Response
{
    return alFinto('POST', '/v1/utenti', $corpo, lingua: $lingua);
}

const TURNSTILE_NON_VALIDO_IT = ['Controllo Turnstile non superato',
    'Il controllo Turnstile non è superato: fallo rifare alla persona e riprova con la risposta nuova.'];

// T3.1

it("un'email della lista si registra: 202 con l'email normalizzata, e nasce una persona da verificare, coi valori predefiniti e il primo codice (T3.1)", function () {
    $finto = BackofficeFinto::attiva()->consenti('@example.com');

    $risposta = registra(registrazione(['email' => ' Anna@Example.COM ']));
    $codice = $finto->ultimoCodice('anna@example.com');

    expect($risposta->status())->toBe(202)
        ->and($risposta->header('Content-Type'))->toBe('application/json')
        ->and($risposta->header('Link'))->toBe(linkDi('utenti.crea'))
        ->and($risposta->json())->toBe(['data' => ['email' => 'anna@example.com']])
        ->and($codice)->toMatch('/^[0-9]{6}$/')
        // Prima della verifica la password non apre un accesso.
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD])->json('codice'))
        ->toBe('credenziali_non_valide');

    $verifica = alFinto('POST', '/v1/io/email/verifica', ['email' => 'anna@example.com', 'password' => PASSWORD, 'codice' => $codice]);

    expect($verifica->status())->toBe(200)
        ->and(Arr::except($verifica->json('data'), ['id', 'email_verificata_il']))
        ->toBe(['nome' => 'anna', 'email' => 'anna@example.com', 'lingua' => 'it', 'fuso_orario' => 'Europe/Rome']);
});

it('il nome, la lingua e il fuso orario dati restano alla persona (T3.1)', function () {
    $finto = BackofficeFinto::attiva()->consenti('bruno@altro.it');

    expect(registra(registrazione([
        'email' => 'bruno@altro.it',
        'nome' => ' Bruno Bianchi ',
        'lingua' => 'es',
        'fuso_orario' => 'America/New_York',
    ]))->status())->toBe(202);

    $verifica = alFinto('POST', '/v1/io/email/verifica', [
        'email' => 'bruno@altro.it',
        'password' => PASSWORD,
        'codice' => $finto->ultimoCodice('bruno@altro.it'),
    ]);

    expect(Arr::except($verifica->json('data'), ['id', 'email_verificata_il']))
        ->toBe(['nome' => 'Bruno Bianchi', 'email' => 'bruno@altro.it', 'lingua' => 'es', 'fuso_orario' => 'America/New_York']);
});

it("un'email che ha già un account risponde 202 come una nuova: l'account non cambia e non parte nessun codice (T3.1)", function () {
    $finto = BackofficeFinto::attiva()->consenti('@example.com');
    $this->freezeTime();
    $finto->persona('anna@example.com', PASSWORD);
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno', verificata: false);
    $primo = $finto->ultimoCodice('bruno@example.com');
    // Oltre i 60 secondi fra un codice e l'altro: un codice nuovo a Bruno potrebbe partire, e non deve.
    $this->travel(120)->seconds();

    $nuova = registra(registrazione(['email' => 'carla@example.com']));
    $anna = registra(registrazione(['email' => 'Anna@example.com', 'password' => ALTRA_PASSWORD, 'nome' => 'Altra']));
    $bruno = registra(registrazione(['email' => 'bruno@example.com', 'password' => ALTRA_PASSWORD]));

    expect($nuova->status())->toBe(202)
        ->and($anna->status())->toBe(202)
        ->and($anna->headers())->toBe($nuova->headers())
        ->and($anna->json())->toBe(['data' => ['email' => 'anna@example.com']])
        ->and($bruno->status())->toBe(202)
        ->and($bruno->headers())->toBe($nuova->headers())
        ->and($bruno->json())->toBe(['data' => ['email' => 'bruno@example.com']])
        ->and($finto->ultimoCodice('anna@example.com'))->toBeNull()
        ->and($finto->ultimoCodice('bruno@example.com'))->toBe($primo);

    // Anna entra con la sua password e il suo nome, non con quelli della registrazione.
    $accesso = alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD]);

    expect($accesso->status())->toBe(201)
        ->and($accesso->json('data.gettone.utente.nome'))->toBe('Anna')
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => ALTRA_PASSWORD])->json('codice'))
        ->toBe('credenziali_non_valide')
        // Bruno si verifica col suo primo codice e la sua password.
        ->and(alFinto('POST', '/v1/io/email/verifica', ['email' => 'bruno@example.com', 'password' => PASSWORD, 'codice' => $primo])->status())
        ->toBe(200);
});

it("a registrazione chiusa un'email fuori lista è 403 registrazione_non_aperta, che abbia o no un account; la lista vale per indirizzo intero o «@dominio», per uguaglianza (T3.1)", function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);

    // Di norma la lista è vuota: nessuno si registra.
    $chiusa = registra(registrazione(['email' => 'carla@example.com']));

    expect($chiusa->status())->toBe(403)
        ->and($chiusa->header('Content-Type'))->toBe('application/problem+json')
        ->and($chiusa->header('Link'))->toBe(linkDi('utenti.crea'))
        ->and($chiusa->json())->toBe(problemaAtteso('registrazione_non_aperta', 403, 'Registrazione non aperta',
            'La registrazione di Zeiras non è ancora aperta a tutti, e questa email non è fra quelle che si possono registrare.'))
        ->and(registra(registrazione())->status())->toBe(403)
        ->and($finto->ultimoCodice('carla@example.com'))->toBeNull();

    $finto->consenti(' Bruno@Altro.it ', '@Example.com');

    expect(registra(registrazione(['email' => 'BRUNO@altro.it']))->status())->toBe(202)
        ->and(registra(registrazione(['email' => 'carla@example.com']))->status())->toBe(202)
        ->and(registra(registrazione())->status())->toBe(202)
        ->and(registra(registrazione(['email' => 'dario@altro.it']))->json('codice'))->toBe('registrazione_non_aperta')
        ->and(registra(registrazione(['email' => 'x@evil.example.com']))->json('codice'))->toBe('registrazione_non_aperta')
        ->and(registra(registrazione(['email' => 'x@example.com.evil.it']))->json('codice'))->toBe('registrazione_non_aperta')
        ->and($finto->ultimoCodice('carla@example.com'))->toMatch('/^[0-9]{6}$/')
        ->and($finto->ultimoCodice('dario@altro.it'))->toBeNull();
});

it("un corpo non valido è 422 dati_non_validi coi testi del backoffice: prima l'email da sola, poi il resto (T3.1)", function () {
    $finto = BackofficeFinto::attiva()->consenti('@example.com');

    $email = registra(['email' => 'anna@', 'password' => PASSWORD_CORTA]);
    $resto = registra([
        'email' => 'anna@example.com',
        'password' => PASSWORD_CORTA,
        'nome' => str_repeat('a', 256),
        'lingua' => 'de',
        'fuso_orario' => 'Europe/Atlantide',
        'termini_accettati' => false,
    ]);
    $mancanti = registra(['email' => 'anna@example.com'], lingua: 'es');
    $nullo = registra(registrazione(['password' => PASSWORD_COL_NULLO]));
    $trapelata = registra(registrazione(['password' => BackofficeFinto::PASSWORD_TRAPELATA]));

    expect($email->status())->toBe(422)
        ->and($email->header('Content-Type'))->toBe('application/problem+json')
        ->and($email->header('Link'))->toBe(linkDi('utenti.crea'))
        ->and($email->json())->toBe(problemaAtteso('dati_non_validi', 422, 'Dati non validi',
            'Alcuni valori non vanno bene: li trovi in errors, con cosa non va e dove stanno.', ['errors' => [
                ['detail' => 'Il campo email deve essere un indirizzo email valido.', 'pointer' => '#/email'],
            ]]))
        ->and($resto->json('errors'))->toBe([
            ['detail' => 'Il campo password deve avere almeno 12 caratteri.', 'pointer' => '#/password'],
            ['detail' => 'Il campo nome non deve avere più di 255 caratteri.', 'pointer' => '#/nome'],
            ['detail' => 'Il valore scelto per lingua non è valido.', 'pointer' => '#/lingua'],
            ['detail' => 'Il campo fuso orario deve essere un fuso orario valido.', 'pointer' => '#/fuso_orario'],
            ['detail' => 'Il campo termini accettati deve essere accettato.', 'pointer' => '#/termini_accettati'],
        ])
        // In spagnolo il backoffice non ha i messaggi della validazione: escono in inglese, il ripiego.
        ->and($mancanti->json('errors'))->toBe([
            ['detail' => 'The password field is required.', 'pointer' => '#/password'],
            ['detail' => 'The termini accettati field is required.', 'pointer' => '#/termini_accettati'],
        ])
        ->and($nullo->json('errors'))
        ->toBe([['detail' => 'Il campo password non può contenere il carattere nullo (U+0000).', 'pointer' => '#/password']])
        // Al posto di Have I Been Pwned: la password trapelata del finto.
        ->and($trapelata->json('errors'))
        ->toBe([['detail' => 'Il valore di password è comparso in una fuga di dati: scegline un altro.', 'pointer' => '#/password']])
        ->and($finto->ultimoCodice('anna@example.com'))->toBeNull();
});

it("oltre 5 richieste in un minuto per un'email è 429 coi secondi nel detail e Retry-After, prima della lista (T3.1)", function () {
    BackofficeFinto::attiva();
    $this->freezeTime();

    foreach (range(1, 5) as $richiesta) {
        expect(registra(registrazione(['email' => 'nessuno@example.com']))->status())->toBe(403);
    }
    $frenata = registra(registrazione(['email' => ' Nessuno@example.com', 'password' => PASSWORD_CORTA]));

    expect($frenata->status())->toBe(429)
        ->and($frenata->header('Retry-After'))->toBe('60')
        ->and($frenata->header('Link'))->toBe(linkDi('utenti.crea'))
        ->and($frenata->json())->toBe(problemaAtteso('troppe_richieste', 429, 'Troppe richieste',
            'Troppe richieste in poco tempo: riprova fra 60 secondi.'))
        ->and(registra(registrazione(['email' => 'altro@example.com']))->status())->toBe(403);
});

// T3.2

it('Turnstile è spento di norma: una registrazione senza turnstile, o con un valore qualsiasi, passa (T3.2)', function () {
    BackofficeFinto::attiva()->consenti('@example.com');

    expect(registra(registrazione())->status())->toBe(202)
        ->and(registra(registrazione(['email' => 'bruno@example.com', 'turnstile' => 'un valore qualsiasi']))->status())->toBe(202);
});

it('spento, Turnstile non controlla la risposta ma la valida come il contratto: una lista, un numero o più di 2048 caratteri sono 422 dati_non_validi su #/turnstile, dopo gli altri campi (T3.2)', function () {
    $finto = BackofficeFinto::attiva()->consenti('@example.com');

    $lista = registra(registrazione(['turnstile' => ['XXXX.DUMMY.TOKEN.XXXX']]));
    $numero = registra(registrazione(['turnstile' => 42]), lingua: 'en');
    $lunga = registra(registrazione(['turnstile' => str_repeat('X', 2049), 'termini_accettati' => false]));

    expect($lista->status())->toBe(422)
        ->and($lista->json())->toBe(problemaAtteso('dati_non_validi', 422, 'Dati non validi',
            'Alcuni valori non vanno bene: li trovi in errors, con cosa non va e dove stanno.', ['errors' => [
                ['detail' => 'Il campo turnstile deve essere una stringa.', 'pointer' => '#/turnstile'],
            ]]))
        ->and($numero->json('errors'))->toBe([['detail' => 'The turnstile field must be a string.', 'pointer' => '#/turnstile']])
        ->and($lunga->json('errors'))->toBe([
            ['detail' => 'Il campo termini accettati deve essere accettato.', 'pointer' => '#/termini_accettati'],
            ['detail' => 'Il campo turnstile non deve avere più di 2048 caratteri.', 'pointer' => '#/turnstile'],
        ])
        ->and($finto->ultimoCodice('anna@example.com'))->toBeNull()
        // Vuota, di soli spazi o nulla è come assente; 2048 caratteri passano.
        ->and(registra(registrazione(['email' => 'bruno@example.com', 'turnstile' => '   ']))->status())->toBe(202)
        ->and(registra(registrazione(['email' => 'carla@example.com', 'turnstile' => null]))->status())->toBe(202)
        ->and(registra(registrazione(['email' => 'dario@example.com', 'turnstile' => str_repeat('X', 2048)]))->status())->toBe(202);
});

it("acceso, Turnstile vuole la risposta dei tasti di prova di Cloudflare: senza, o con un'altra, è 422 turnstile_non_valido, prima della lista (T3.2)", function (array $campi) {
    $finto = BackofficeFinto::attiva()->consenti('@example.com')->accendiTurnstile();

    $respinta = registra(registrazione($campi));

    expect($respinta->status())->toBe(422)
        ->and($respinta->header('Content-Type'))->toBe('application/problem+json')
        ->and($respinta->header('Link'))->toBe(linkDi('utenti.crea'))
        ->and($respinta->json())->toBe(problemaAtteso('turnstile_non_valido', 422, ...TURNSTILE_NON_VALIDO_IT))
        ->and(registra(registrazione($campi + ['email' => 'fuori@altro.it']))->json('codice'))->toBe('turnstile_non_valido')
        ->and($finto->ultimoCodice('anna@example.com'))->toBeNull();
})->with([
    'senza turnstile' => [[]],
    'vuota' => [['turnstile' => '']],
    'di soli spazi' => [['turnstile' => '   ']],
    'nulla' => [['turnstile' => null]],
    'una lista' => [['turnstile' => ['XXXX.DUMMY.TOKEN.XXXX']]],
    'un numero' => [['turnstile' => 42]],
    'oltre 2048 caratteri' => [['turnstile' => str_repeat('X', 2049)]],
    "un'altra" => [['turnstile' => 'XXXX.DUMMY.TOKEN.XXXY']],
]);

it('acceso, con la risposta XXXX.DUMMY.TOKEN.XXXX la registrazione passa; fuori lista, dopo Turnstile, resta 403 (T3.2)', function () {
    $finto = BackofficeFinto::attiva()->consenti('@example.com')->accendiTurnstile();

    expect(BackofficeFinto::TURNSTILE_VALIDO)->toBe('XXXX.DUMMY.TOKEN.XXXX')
        ->and(registra(registrazione(['turnstile' => 'XXXX.DUMMY.TOKEN.XXXX']))->status())->toBe(202)
        ->and($finto->ultimoCodice('anna@example.com'))->toMatch('/^[0-9]{6}$/')
        ->and(registra(registrazione(['email' => 'fuori@altro.it', 'turnstile' => 'XXXX.DUMMY.TOKEN.XXXX']))->json('codice'))
        ->toBe('registrazione_non_aperta');
});

it('guasto, Cloudflare non risponde: una risposta ben formata è 503 turnstile_non_disponibile, una mancante resta 422 (T3.2)', function () {
    $finto = BackofficeFinto::attiva()->consenti('@example.com')->guastaTurnstile();

    $guasto = registra(registrazione(['turnstile' => 'XXXX.DUMMY.TOKEN.XXXX']));

    expect($guasto->status())->toBe(503)
        ->and($guasto->header('Content-Type'))->toBe('application/problem+json')
        ->and($guasto->header('Link'))->toBe(linkDi('utenti.crea'))
        ->and($guasto->hasHeader('Retry-After'))->toBeFalse()
        ->and($guasto->json())->toBe(problemaAtteso('turnstile_non_disponibile', 503, 'Controllo Turnstile non disponibile',
            'Il controllo Turnstile non si può fare adesso: rifallo e riprova fra poco.'))
        ->and(registra(registrazione(['email' => 'bruno@example.com']))->json('codice'))->toBe('turnstile_non_valido')
        ->and($finto->ultimoCodice('anna@example.com'))->toBeNull();
});

it('apri() apre a ogni email solo con Turnstile acceso, come il backoffice che si apre solo col segreto: spento, resta la lista (T3.2)', function () {
    $finto = BackofficeFinto::attiva()->consenti('@example.com')->apri();

    expect(registra(registrazione(['email' => 'chiunque@altro.it']))->json('codice'))->toBe('registrazione_non_aperta')
        ->and(registra(registrazione())->status())->toBe(202)
        ->and($finto->ultimoCodice('chiunque@altro.it'))->toBeNull();

    $finto->accendiTurnstile();

    expect(registra(registrazione(['email' => 'altra@altro.it']))->json('codice'))->toBe('turnstile_non_valido')
        ->and(registra(registrazione(['email' => 'altra@altro.it', 'turnstile' => 'XXXX.DUMMY.TOKEN.XXXX']))->status())->toBe(202)
        ->and($finto->ultimoCodice('altra@altro.it'))->toMatch('/^[0-9]{6}$/');
});
