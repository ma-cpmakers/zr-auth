<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Zeiras\Auth\Api;
use Zeiras\Auth\Sessione;
use Zeiras\Auth\Testing\BackofficeFinto;
use Zeiras\Auth\Testing\Finto\RichiestaSconosciuta;

// T2 (#1170, Z5): il backoffice finto del nucleo — entrare, uscire, il gettone di un workspace.

const CREDENZIALI_IT = ['Credenziali non valide', "L'email o la password non sono giuste."];

// T2.1

it('accessi.crea apre un accesso col suo gettone di 12 ore, senza workspace, e la persona con la sua lingua (T2.1)', function () {
    $finto = BackofficeFinto::attiva();
    $this->freezeTime();
    $ana = $finto->persona('Ana@Example.com', PASSWORD, nome: 'Ana', lingua: 'es');

    $risposta = alFinto('POST', '/v1/accessi', ['email' => '  ana@example.COM ', 'password' => PASSWORD], lingua: 'it');

    expect($risposta->status())->toBe(201)
        ->and($risposta->header('Content-Type'))->toBe('application/json')
        ->and($risposta->header('Link'))->toBe(linkDi('accessi.crea'));
    $accesso = $risposta->json('data');
    expect(array_keys($risposta->json()))->toBe(['data'])
        ->and(array_keys($accesso))->toBe(['id', 'creato_il', 'gettone'])
        ->and($accesso['id'])->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/')
        ->and($accesso['creato_il'])->toBe(iso(now()))
        ->and(array_keys($accesso['gettone']))->toBe(['gettone', 'scade_il', 'utente', 'workspace', 'ruolo'])
        ->and($accesso['gettone']['gettone'])->toMatch('/^zr_[A-Za-z0-9]{48}$/')
        ->and($accesso['gettone']['scade_il'])->toBe(iso(now()->addHours(12)))
        ->and($accesso['gettone']['workspace'])->toBeNull()
        ->and($accesso['gettone']['ruolo'])->toBeNull()
        // La persona come la dà persona(), con la sua lingua: non quella di Accept-Language.
        ->and($accesso['gettone']['utente'])->toBe($ana)
        ->and($ana)->toMatchArray(['nome' => 'Ana', 'email' => 'ana@example.com', 'lingua' => 'es', 'fuso_orario' => 'Europe/Rome'])
        ->and(array_keys($ana))->toBe(['id', 'nome', 'email', 'email_verificata_il', 'lingua', 'fuso_orario'])
        ->and($ana['email_verificata_il'])->toBe(iso(now()));
});

it('il gettone di accessi.crea apre le chiamate dopo, e col gettone gli errori sono nella lingua della persona (T2.1)', function () {
    $finto = BackofficeFinto::attiva();
    $ana = $finto->persona('ana@example.com', PASSWORD, lingua: 'es');
    $studio = $finto->workspace('Estudio Ana', $ana);
    $gettone = entraNelFinto('ana@example.com')['gettone']['gettone'];

    expect(alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $gettone)->status())->toBe(201);

    $nonTrovato = alFinto('DELETE', '/v1/accessi/01k6r2v8x4c7n3m9p5q1s6t2w8', gettone: $gettone, lingua: 'it');
    expect($nonTrovato->status())->toBe(404)
        ->and($nonTrovato->json())->toBe(problemaAtteso('non_trovato', 404, 'Recurso no encontrado',
            'El recurso indicado en la ruta no existe o no se puede ver con el token.'));
});

it('il flusso di un frontend: Api::senzaGettone() e Sessione::apri(), Api::persona() e Sessione::entra() (T2.1, T2.4)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio Anna', $anna);

    Sessione::apri(Api::senzaGettone()->post('/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD])['data']);
    Sessione::entra(Api::persona()->post('/v1/gettoni', ['workspace_id' => $studio['id']])['data']);

    expect(Sessione::utente())->toBe($anna)
        ->and(Sessione::workspace())->toBe($studio)
        ->and(Sessione::ruolo())->toBe('proprietario')
        ->and(Sessione::aperta())->toBeTrue();
});

// T2.2

it('una password sbagliata, un\'email sconosciuta e una non verificata sono lo stesso 422 credenziali_non_valide (T2.2)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno', verificata: false);

    $risposte = [
        alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD_SBAGLIATA]),
        alFinto('POST', '/v1/accessi', ['email' => 'nessuno@example.com', 'password' => PASSWORD]),
        alFinto('POST', '/v1/accessi', ['email' => 'bruno@example.com', 'password' => PASSWORD]),
    ];

    foreach ($risposte as $risposta) {
        expect($risposta->status())->toBe(422)
            ->and($risposta->header('Content-Type'))->toBe('application/problem+json')
            ->and($risposta->header('Link'))->toBe(linkDi('accessi.crea'))
            ->and($risposta->json())->toBe(problemaAtteso('credenziali_non_valide', 422, ...CREDENZIALI_IT))
            ->and($risposta->body())->toBe($risposte[0]->body())
            ->and($risposta->headers())->toBe($risposte[0]->headers());
    }
});

it('title e detail sono nella lingua di Accept-Language, coi testi del backoffice (T2.2)', function (?string $lingua, string $titolo, string $dettaglio) {
    BackofficeFinto::attiva();

    $risposta = alFinto('POST', '/v1/accessi', ['email' => 'nessuno@example.com', 'password' => PASSWORD], lingua: $lingua);

    expect($risposta->json())->toBe(problemaAtteso('credenziali_non_valide', 422, $titolo, $dettaglio));
})->with([
    'senza Accept-Language: italiano' => [null, ...CREDENZIALI_IT],
    'it' => ['it-IT,it;q=0.9', ...CREDENZIALI_IT],
    'en' => ['en-GB', 'Invalid credentials', 'The email or the password is not correct.'],
    'es' => ['de;q=0.9, es', 'Credenciales no válidas', 'El correo electrónico o la contraseña no son correctos.'],
    'nessuna delle tre: inglese' => ['de, fr', 'Invalid credentials', 'The email or the password is not correct.'],
]);

it('il sesto tentativo in un minuto per la stessa email è 429 anche con la password giusta, coi secondi di Retry-After nel detail (T2.2)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);
    $finto->persona('bruno@example.com', PASSWORD);
    $this->freezeTime();

    foreach (range(1, 5) as $tentativo) {
        expect(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD_SBAGLIATA])->status())->toBe(422);
    }

    $frenata = alFinto('POST', '/v1/accessi', ['email' => ' ANNA@example.com', 'password' => PASSWORD]);
    expect($frenata->status())->toBe(429)
        ->and($frenata->header('Retry-After'))->toBe('60')
        ->and($frenata->header('Link'))->toBe(linkDi('accessi.crea'))
        ->and($frenata->json())->toBe(problemaAtteso('troppe_richieste', 429, 'Troppe richieste',
            'Troppe richieste in poco tempo: riprova fra 60 secondi.'))
        // Il freno è dell'email: le altre entrano.
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'bruno@example.com', 'password' => PASSWORD])->status())->toBe(201);

    $this->travel(59)->seconds();
    $inglese = alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD], lingua: 'en');
    expect($inglese->status())->toBe(429)
        ->and($inglese->header('Retry-After'))->toBe('1')
        ->and($inglese->json('detail'))->toBe('Too many requests in a short time: try again in 1 second.');

    $this->travel(1)->seconds();
    expect(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD])->status())->toBe(201);
});

it('un accesso riuscito azzera il conto dei tentativi (T2.2)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);
    $this->freezeTime();
    $sbagliato = fn () => alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD_SBAGLIATA])->status();

    foreach (range(1, 4) as $tentativo) {
        expect($sbagliato())->toBe(422);
    }
    entraNelFinto('anna@example.com');

    foreach (range(1, 5) as $tentativo) {
        expect($sbagliato())->toBe(422);
    }
    expect($sbagliato())->toBe(429);
});

it('un\'email non valida o una password assente sono 422 dati_non_validi, col pointer e il testo del backoffice (T2.2)', function () {
    BackofficeFinto::attiva();

    $risposta = alFinto('POST', '/v1/accessi', ['email' => 'non è una email', 'password' => ''], lingua: 'en');

    expect($risposta->status())->toBe(422)
        ->and($risposta->json())->toBe(problemaAtteso('dati_non_validi', 422, 'Invalid data',
            'Some values are not valid: you find them in errors, with what is wrong and where they are.', ['errors' => [
                ['detail' => 'The email field must be a valid email address.', 'pointer' => '#/email'],
                ['detail' => 'The password field is required.', 'pointer' => '#/password'],
            ]]));
});

// T2.3

it('accessi.elimina chiude l\'accesso: 204, e da lì ogni suo gettone, anche quello del workspace, è 401 gettone_non_valido (T2.3)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio Anna', $anna);
    $accesso = entraNelFinto('anna@example.com');
    $gettone = $accesso['gettone']['gettone'];
    $delWorkspace = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $gettone)->json('data.gettone');

    $uscita = alFinto('DELETE', "/v1/accessi/{$accesso['id']}", gettone: $delWorkspace);

    expect($uscita->status())->toBe(204)
        ->and($uscita->body())->toBe('')
        ->and($uscita->header('Link'))->toBe(linkDi('accessi.elimina'));

    foreach ([$gettone, $delWorkspace] as $chiuso) {
        $rifiutato = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $chiuso, lingua: 'en');
        expect($rifiutato->status())->toBe(401)
            ->and($rifiutato->header('WWW-Authenticate'))->toBe('Bearer realm="zeiras", error="invalid_token"')
            ->and($rifiutato->json())->toBe(problemaAtteso('gettone_non_valido', 401, 'Invalid token', 'The token of the request is not valid.'))
            ->and(alFinto('DELETE', "/v1/accessi/{$accesso['id']}", gettone: $chiuso)->status())->toBe(401);
    }
});

it('accessi.corrente.elimina chiude l\'accesso del gettone senza id: 204 con il Link, ogni suo gettone è 401, un altro accesso resta (#1383)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio Anna', $anna);
    $accesso = entraNelFinto('anna@example.com');
    $gettone = $accesso['gettone']['gettone'];
    $delWorkspace = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $gettone)->json('data.gettone');
    $altro = entraNelFinto('anna@example.com')['gettone']['gettone'];

    $uscita = alFinto('DELETE', '/v1/accessi/corrente', gettone: $delWorkspace);

    expect($uscita->status())->toBe(204)
        ->and($uscita->body())->toBe('')
        ->and($uscita->header('Link'))->toBe(linkDi('accessi.corrente.elimina'));

    foreach ([$gettone, $delWorkspace] as $chiuso) {
        $rifiutato = alFinto('DELETE', '/v1/accessi/corrente', gettone: $chiuso);
        expect($rifiutato->status())->toBe(401)
            ->and($rifiutato->json('codice'))->toBe('gettone_non_valido');
    }

    expect(alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $altro)->status())->toBe(201)
        ->and(alFinto('DELETE', '/v1/accessi/corrente')->status())->toBe(401);
});

it('si esce da un accesso con il gettone di un altro accesso della stessa persona, che resta aperto (T2.3)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);
    $telefono = entraNelFinto('anna@example.com');
    $computer = entraNelFinto('anna@example.com');

    expect(alFinto('DELETE', "/v1/accessi/{$telefono['id']}", gettone: $computer['gettone']['gettone'])->status())->toBe(204)
        ->and(alFinto('DELETE', "/v1/accessi/{$telefono['id']}", gettone: $telefono['gettone']['gettone'])->status())->toBe(401)
        // Già chiuso: non si trova più.
        ->and(alFinto('DELETE', "/v1/accessi/{$telefono['id']}", gettone: $computer['gettone']['gettone'])->status())->toBe(404)
        ->and(alFinto('DELETE', "/v1/accessi/{$computer['id']}", gettone: $computer['gettone']['gettone'])->status())->toBe(204);
});

it('l\'accesso di un\'altra persona è 404 non_trovato, come uno che non esiste, e resta aperto (T2.3)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $anna = entraNelFinto('anna@example.com');
    $bruno = entraNelFinto('bruno@example.com');

    $altrui = alFinto('DELETE', "/v1/accessi/{$bruno['id']}", gettone: $anna['gettone']['gettone']);
    $inesistente = alFinto('DELETE', '/v1/accessi/01k6r2v8x4c7n3m9p5q1s6t2w8', gettone: $anna['gettone']['gettone']);

    expect($altrui->status())->toBe(404)
        ->and($altrui->header('Content-Type'))->toBe('application/problem+json')
        ->and($altrui->json())->toBe(problemaAtteso('non_trovato', 404, 'Risorsa non trovata',
            'La risorsa indicata nel percorso non esiste o non si può vedere col gettone.'))
        ->and($inesistente->body())->toBe($altrui->body())
        ->and(alFinto('DELETE', "/v1/accessi/{$bruno['id']}", gettone: $bruno['gettone']['gettone'])->status())->toBe(204);
});

// T2.4

it('gettoni.crea dà il gettone di un workspace della persona, col suo ruolo, che scade con l\'accesso (T2.4)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $bruno = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $studio = $finto->workspace('Studio Anna', $anna);
    $finto->membro($studio, $bruno, 'membro');
    $this->freezeTime();
    $accesso = entraNelFinto('bruno@example.com');
    $this->travel(2)->hours();

    $risposta = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso['gettone']['gettone']);

    expect($risposta->status())->toBe(201)
        ->and($risposta->header('Content-Type'))->toBe('application/json')
        ->and($risposta->header('Link'))->toBe(linkDi('gettoni.crea'))
        ->and(array_keys($risposta->json()))->toBe(['data'])
        ->and(array_keys($risposta->json('data')))->toBe(['gettone', 'scade_il', 'utente', 'workspace', 'ruolo'])
        ->and($risposta->json('data.gettone'))->toMatch('/^zr_[A-Za-z0-9]{48}$/')
        ->and($risposta->json('data.gettone'))->not->toBe($accesso['gettone']['gettone'])
        ->and($risposta->json('data.scade_il'))->toBe($accesso['gettone']['scade_il'])
        ->and($risposta->json('data.utente'))->toBe($bruno)
        ->and($risposta->json('data.workspace'))->toBe(['id' => $studio['id'], 'nome' => 'Studio Anna', 'slug' => $studio['slug'], 'azienda_id' => $studio['azienda_id']])
        ->and($risposta->json('data.ruolo'))->toBe('membro')
        ->and($studio['slug'])->toMatch('/^studio-anna-[a-z0-9]{6}$/');

    // Il gettone del workspace vale fino alla fine delle 12 ore dell'accesso, non oltre.
    $delWorkspace = $risposta->json('data.gettone');
    $this->travel(10 * 3600 - 1)->seconds();
    expect(alFinto('POST', '/v1/gettoni', [], $delWorkspace)->status())->toBe(403);
    $this->travel(1)->seconds();
    expect(alFinto('POST', '/v1/gettoni', [], $delWorkspace)->status())->toBe(401);
});

it('gettoni.crea col gettone di un workspace è 403 gettone_con_workspace, prima di guardare il corpo (T2.4)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD, lingua: 'en');
    $studio = $finto->workspace('Studio Anna', $anna);
    $delWorkspace = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])
        ->json('data.gettone');

    $risposta = alFinto('POST', '/v1/gettoni', ['workspace_id' => 42], $delWorkspace);

    expect($risposta->status())->toBe(403)
        ->and($risposta->header('Link'))->toBe(linkDi('gettoni.crea'))
        ->and($risposta->json())->toBe(problemaAtteso('gettone_con_workspace', 403, 'Token with workspace',
            'This method wants the token of the access, without workspace, and the token is of a workspace: repeat the request with the token that accessi.crea gave you.'));
});

it('un workspace di cui la persona non è membro, o che non esiste, è lo stesso 422 su #/workspace_id (T2.4)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $carla = $finto->persona('carla@example.com', PASSWORD, nome: 'Carla');
    $finto->workspace('Studio Anna', $anna);
    $altrui = $finto->workspace('Studio Carla', $carla);
    $gettone = entraNelFinto('anna@example.com')['gettone']['gettone'];

    $nonSuo = alFinto('POST', '/v1/gettoni', ['workspace_id' => $altrui['id']], $gettone);
    $inesistente = alFinto('POST', '/v1/gettoni', ['workspace_id' => '01k6r3a7c2e6g0j4m8p2s6v0x4'], $gettone);

    expect($nonSuo->status())->toBe(422)
        ->and($nonSuo->header('Link'))->toBe(linkDi('gettoni.crea'))
        ->and($nonSuo->json())->toBe(problemaAtteso('dati_non_validi', 422, 'Dati non validi',
            'Alcuni valori non vanno bene: li trovi in errors, con cosa non va e dove stanno.', ['errors' => [[
                'detail' => "Non sei membro di un workspace con questo id: l'id lo dà io.workspace.crea, alla nascita del workspace.",
                'pointer' => '#/workspace_id',
            ]]]))
        ->and($inesistente->body())->toBe($nonSuo->body())
        ->and(alFinto('POST', '/v1/gettoni', [], $gettone)->json('errors'))
        ->toBe([['detail' => 'Il campo workspace id è obbligatorio.', 'pointer' => '#/workspace_id']]);
});

it('gettoni.crea senza gettone è 401 gettone_assente, con un gettone inventato 401 gettone_non_valido (T2.4)', function (?string $gettone, string $codice, string $titolo, string $dettaglio, string $sfida) {
    BackofficeFinto::attiva();

    $risposta = alFinto('POST', '/v1/gettoni', ['workspace_id' => '01k6r3a7c2e6g0j4m8p2s6v0x4'], $gettone, lingua: 'es');

    expect($risposta->status())->toBe(401)
        ->and($risposta->header('Content-Type'))->toBe('application/problem+json')
        ->and($risposta->header('WWW-Authenticate'))->toBe($sfida)
        ->and($risposta->header('Link'))->toBe(linkDi('gettoni.crea'))
        ->and($risposta->json())->toBe(problemaAtteso($codice, 401, $titolo, $dettaglio));
})->with([
    'senza gettone' => [null, 'gettone_assente', 'Falta el token',
        'La solicitud no lleva un token: envíalo en la cabecera Authorization, como Bearer.', 'Bearer realm="zeiras"'],
    'gettone inventato' => [GETTONE_ACCESSO, 'gettone_non_valido', 'Token no válido', 'El token de la solicitud no es válido.',
        'Bearer realm="zeiras", error="invalid_token"'],
    'non è un gettone di Zeiras' => ['abc', 'gettone_non_valido', 'Token no válido', 'El token de la solicitud no es válido.',
        'Bearer realm="zeiras", error="invalid_token"'],
]);

// Il finto e gli altri.

it('una richiesta di /v1 che il finto non conosce lancia; una che non è del backoffice resta agli altri', function () {
    BackofficeFinto::attiva();

    expect(fn () => alFinto('GET', '/v1/sconosciuto'))->toThrow(RichiestaSconosciuta::class, 'GET /v1/sconosciuto')
        ->and(fn () => alFinto('GET', '/v1/accessi'))->toThrow(RichiestaSconosciuta::class, 'GET /v1/accessi')
        ->and(fn () => Http::get('https://altrove.example/v1/accessi'))->toThrow(StrayRequestException::class);
});

it('persona() rifiuta un\'email che il finto ha già e una lingua che Zeiras non parla; membro() un ruolo che non c\'è', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio Anna', $anna);

    expect(fn () => $finto->persona(' Anna@Example.com', 'altra'))->toThrow(LogicException::class, 'anna@example.com')
        ->and(fn () => $finto->persona('bruno@example.com', PASSWORD, lingua: 'de'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finto->membro($studio, $anna, 'ospite'))->toThrow(InvalidArgumentException::class);
});

// I freni dei metodi col gettone, e la scadenza al millesimo, come il backoffice.

it('gettoni.crea dà al più 60 gettoni in un\'ora a una persona, poi 429 fino alla fine della finestra (T2.4)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio Anna', $anna);
    $this->freezeTime();
    $gettone = entraNelFinto('anna@example.com')['gettone']['gettone'];

    foreach (range(1, 60) as $richiesta) {
        expect(alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $gettone)->status())->toBe(201);
    }
    // Col gettone di un altro accesso della stessa persona: il freno è suo.
    $frenata = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone']);

    expect($frenata->status())->toBe(429)
        ->and($frenata->header('Retry-After'))->toBe('3600')
        ->and($frenata->header('Link'))->toBe(linkDi('gettoni.crea'))
        ->and($frenata->json('detail'))->toBe('Troppe richieste in poco tempo: riprova fra 3600 secondi.');

    $this->travel(3600)->seconds();
    expect(alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $gettone)->status())->toBe(201);
});

it('un gettone fa al più 600 chiamate in un minuto: la seicentunesima è 429, nella lingua della persona (T2.3)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD, lingua: 'en');
    $this->freezeTime();
    $gettone = entraNelFinto('anna@example.com')['gettone']['gettone'];
    $altro = entraNelFinto('anna@example.com')['gettone']['gettone'];
    $esci = fn (string $con) => alFinto('DELETE', '/v1/accessi/01k6r2v8x4c7n3m9p5q1s6t2w8', gettone: $con);

    foreach (range(1, 600) as $chiamata) {
        expect($esci($gettone)->status())->toBe(404);
    }
    $this->travel(20)->seconds();
    $frenata = $esci($gettone);

    expect($frenata->status())->toBe(429)
        ->and($frenata->header('Retry-After'))->toBe('40')
        ->and($frenata->json('detail'))->toBe('Too many requests in a short time: try again in 40 seconds.')
        // Il freno è del gettone: un altro gettone della stessa persona no.
        ->and($esci($altro)->status())->toBe(404);

    $this->travel(40)->seconds();
    expect($esci($gettone)->status())->toBe(404);
});

it('un gettone non vale più all\'istante del suo scade_il, al millesimo (T2.1)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);
    $gettone = entraNelFinto('anna@example.com')['gettone'];
    $scade = CarbonImmutable::parse($gettone['scade_il']);

    $this->travelTo($scade->subMillisecond());
    expect(alFinto('DELETE', '/v1/accessi/01k6r2v8x4c7n3m9p5q1s6t2w8', gettone: $gettone['gettone'])->status())->toBe(404);

    $this->travelTo($scade);
    expect(alFinto('DELETE', '/v1/accessi/01k6r2v8x4c7n3m9p5q1s6t2w8', gettone: $gettone['gettone'])->status())->toBe(401);
});
