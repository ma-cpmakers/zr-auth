<?php

use Illuminate\Support\Carbon;
use Zeiras\Auth\Testing\BackofficeFinto;

// #1429 T4 (G13): l'accesso con un provider nel finto: accessi.provider.elenca, accessi.provider.autorizzazioni.crea e
// accessi.provider.crea. Qui gli invarianti che un frontend vede dal suo lato; che le risposte siano quelle del backoffice,
// byte per byte, lo prova il FintoTest del backoffice (G12).

/** Il finto con Google acceso, e una partenza fatta: [finto, stato]. */
function fintoConPartenza(string $provider = 'google'): array
{
    $finto = BackofficeFinto::attiva()->provider($provider);
    $partenza = alFinto('POST', "/v1/accessi/provider/{$provider}/autorizzazioni");
    expect($partenza->status())->toBe(201);

    return [$finto, $partenza->json('data.stato')];
}

/** L'arrivo dal provider: codice e stato del ritorno. */
function arrivo(string $provider, string $codice, string $stato, array $altri = [], ?string $lingua = null)
{
    return alFinto('POST', "/v1/accessi/provider/{$provider}", ['codice' => $codice, 'stato' => $stato] + $altri, lingua: $lingua);
}

it('provider(), identitaDelProvider() rifiutano un provider che il backoffice non conosce (#1429)', function () {
    $finto = BackofficeFinto::attiva();

    expect(fn () => $finto->provider('github'))->toThrow(InvalidArgumentException::class, 'github')
        ->and(fn () => $finto->identitaDelProvider('github', 'codice', 'anna@example.com'))->toThrow(InvalidArgumentException::class, 'github');
});

it("accessi.provider.elenca dà solo i provider accesi, in ordine di slug, a pagine, senza gettone, col Link (#1429)", function () {
    $finto = BackofficeFinto::attiva();

    $vuoto = alFinto('GET', '/v1/accessi/provider');
    expect($vuoto->status())->toBe(200)
        ->and($vuoto->json())->toBe(['data' => [], 'successivo' => null])
        ->and($vuoto->header('Link'))->toBe(linkDi('accessi.provider.elenca'));

    $finto->provider('linkedin-openid', 'google', 'facebook');
    $prima = alFinto('GET', '/v1/accessi/provider?limite=2');
    $seconda = alFinto('GET', '/v1/accessi/provider?limite=2&cursore='.urlencode((string) $prima->json('successivo')));

    expect(alFinto('GET', '/v1/accessi/provider')->json())->toBe(['data' => [['provider' => 'facebook'], ['provider' => 'google'], ['provider' => 'linkedin-openid']], 'successivo' => null])
        ->and($prima->json('data'))->toBe([['provider' => 'facebook'], ['provider' => 'google']])
        ->and($prima->json('successivo'))->toBeString()
        ->and($seconda->json())->toBe(['data' => [['provider' => 'linkedin-openid']], 'successivo' => null])
        ->and(alFinto('GET', '/v1/accessi/provider?limite=0')->json('codice'))->toBe('dati_non_validi')
        ->and(alFinto('GET', '/v1/accessi/provider?cursore=inventato')->status())->toBe(422);
});

it('accessi.provider.elenca ha un freno solo, di 120 chiamate al minuto in tutto (#1429)', function () {
    BackofficeFinto::attiva()->provider('google');

    foreach (range(1, 120) as $_) {
        expect(alFinto('GET', '/v1/accessi/provider')->status())->toBe(200);
    }

    $frenata = alFinto('GET', '/v1/accessi/provider');
    expect($frenata->status())->toBe(429)
        ->and($frenata->json('codice'))->toBe('troppe_richieste')
        ->and((int) $frenata->header('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);

    $this->travel(61)->seconds();
    expect(alFinto('GET', '/v1/accessi/provider')->status())->toBe(200);
});

it("accessi.provider.autorizzazioni.crea dà l'indirizzo del provider con lo stato e la sfida PKCE S256, e scade fra 10 minuti (#1429)", function () {
    $this->travelTo(Carbon::parse('2026-10-09 10:00:00.250'));
    BackofficeFinto::attiva()->provider('google', 'facebook');

    $risposta = alFinto('POST', '/v1/accessi/provider/google/autorizzazioni');
    $url = parse_url((string) $risposta->json('data.url'));
    parse_str($url['query'], $query);

    expect($risposta->status())->toBe(201)
        ->and($risposta->header('Link'))->toBe(linkDi('accessi.provider.autorizzazioni.crea'))
        ->and(array_keys($risposta->json('data')))->toBe(['url', 'stato', 'scade_il'])
        ->and($url['scheme'].'://'.$url['host'].$url['path'])->toBe('https://accounts.google.com/o/oauth2/v2/auth')
        ->and(array_keys($query))->toBe(['response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method'])
        ->and($query['response_type'])->toBe('code')
        ->and($query['redirect_uri'])->toBe('https://app.zeiras.com/auth/google/callback')
        ->and($query['scope'])->toBe('openid email profile')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['state'])->toBe($risposta->json('data.stato'))->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($query['code_challenge'])->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($risposta->json('data.scade_il'))->toBe('2026-10-09T10:10:00.250Z')
        // Ogni partenza ha il suo stato.
        ->and(alFinto('POST', '/v1/accessi/provider/google/autorizzazioni')->json('data.stato'))->not->toBe($risposta->json('data.stato'))
        ->and(alFinto('POST', '/v1/accessi/provider/facebook/autorizzazioni')->json('data.url'))->toContain('scope=email%20public_profile');
});

it('accessi.provider.autorizzazioni.crea: un provider spento o sconosciuto è 404 non_trovato, uguale; un campo nel corpo è 422 sul suo pointer (#1429)', function () {
    BackofficeFinto::attiva()->provider('google');

    $spento = alFinto('POST', '/v1/accessi/provider/facebook/autorizzazioni');
    $sconosciuto = alFinto('POST', '/v1/accessi/provider/github/autorizzazioni');
    $campo = alFinto('POST', '/v1/accessi/provider/google/autorizzazioni', ['redirect_uri' => 'https://altrove.example']);

    expect($spento->status())->toBe(404)
        ->and($spento->json('codice'))->toBe('non_trovato')
        ->and($sconosciuto->body())->toBe($spento->body())
        ->and($campo->status())->toBe(422)
        ->and($campo->json('errors.0.pointer'))->toBe('#/redirect_uri');
});

it('accessi.provider.autorizzazioni.crea: 60 chiamate al minuto per provider, 120 in tutto (#1429)', function () {
    BackofficeFinto::attiva()->provider('google', 'facebook');

    foreach (range(1, 60) as $_) {
        expect(alFinto('POST', '/v1/accessi/provider/google/autorizzazioni')->status())->toBe(201);
    }

    $frenata = alFinto('POST', '/v1/accessi/provider/google/autorizzazioni');
    expect($frenata->status())->toBe(429)
        ->and($frenata->json('codice'))->toBe('troppe_richieste')
        // Il freno è del provider: l'altro passa.
        ->and(alFinto('POST', '/v1/accessi/provider/facebook/autorizzazioni')->status())->toBe(201);
});

it('accessi.provider.crea: la persona nuova ha il nome del provider, l\'email verificata e il gettone di un accesso senza workspace (#1429)', function () {
    [$finto, $stato] = fintoConPartenza();
    $finto->consenti('anna@example.com')->identitaDelProvider('google', 'codice-di-anna', 'Anna@Example.com', nome: 'Anna Rossi');

    $risposta = arrivo('google', 'codice-di-anna', $stato, ['termini_accettati' => true]);

    expect($risposta->status())->toBe(201)
        ->and($risposta->header('Link'))->toBe(linkDi('accessi.provider.crea'))
        ->and(array_keys($risposta->json('data')))->toBe(['id', 'creato_il', 'gettone'])
        ->and($risposta->json('data.gettone.workspace'))->toBeNull();

    $io = alFinto('GET', '/v1/io', gettone: $risposta->json('data.gettone.gettone'));
    expect($io->status())->toBe(200)
        ->and($io->json('data.utente.nome'))->toBe('Anna Rossi')
        ->and($io->json('data.utente.email'))->toBe('anna@example.com')
        ->and($io->json('data.utente.email_verificata_il'))->toBeString();
});

it('accessi.provider.crea: il nome manca, vale la parte locale dell\'email (#1429)', function () {
    [$finto, $stato] = fintoConPartenza();
    $finto->consenti('@example.com')->identitaDelProvider('google', 'codice', 'anna.rossi@example.com');

    $risposta = arrivo('google', 'codice', $stato, ['termini_accettati' => true]);

    expect(alFinto('GET', '/v1/io', gettone: $risposta->json('data.gettone.gettone'))->json('data.utente.nome'))->toBe('anna.rossi');
});

it('accessi.provider.crea: Google e LinkedIn fanno nascere la persona anche a registrazione chiusa; i termini mancanti sono 422 su #/termini_accettati (#1554)', function (string $provider) {
    [$finto, $stato] = fintoConPartenza($provider);
    $finto->identitaDelProvider($provider, 'codice', 'anna@altro.it');

    $senza = arrivo($provider, 'codice', $stato);
    expect($senza->status())->toBe(422)
        ->and($senza->json('codice'))->toBe('dati_non_validi')
        ->and($senza->json('errors.0.pointer'))->toBe('#/termini_accettati');

    $stato = alFinto('POST', "/v1/accessi/provider/{$provider}/autorizzazioni")->json('data.stato');
    expect(arrivo($provider, 'codice', $stato, ['termini_accettati' => false])->json('errors.0.pointer'))->toBe('#/termini_accettati');

    $stato = alFinto('POST', "/v1/accessi/provider/{$provider}/autorizzazioni")->json('data.stato');
    $aperta = arrivo($provider, 'codice', $stato, ['termini_accettati' => true]);
    expect($aperta->status())->toBe(201)->and($aperta->json('data.gettone.utente.email'))->toBe('anna@altro.it');
})->with(['google', 'linkedin-openid']);

it('accessi.provider.crea: con Facebook la registrazione chiusa resta 403 registrazione_non_aperta, e la lista la apre (#1554)', function () {
    [$finto, $stato] = fintoConPartenza('facebook');
    $finto->identitaDelProvider('facebook', 'codice', 'anna@example.com');

    $chiusa = arrivo('facebook', 'codice', $stato, ['termini_accettati' => true]);
    expect($chiusa->status())->toBe(403)->and($chiusa->json('codice'))->toBe('registrazione_non_aperta');

    $finto->consenti('anna@example.com');
    $stato = alFinto('POST', '/v1/accessi/provider/facebook/autorizzazioni')->json('data.stato');
    expect(arrivo('facebook', 'codice', $stato, ['termini_accettati' => true])->status())->toBe(201);
});

it("accessi.provider.crea: una persona che c'è già si collega senza termini, e dalla seconda volta entra per l'identità, anche se ha cambiato l'email (#1429)", function () {
    [$finto, $stato] = fintoConPartenza();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $finto->identitaDelProvider('google', 'primo', 'anna@example.com', id: 'sub-anna');

    $prima = arrivo('google', 'primo', $stato);
    expect($prima->status())->toBe(201)
        ->and(alFinto('GET', '/v1/io', gettone: $prima->json('data.gettone.gettone'))->json('data.utente.id'))->toBe($anna['id']);

    // La password resta, perché l'email era già verificata.
    expect(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD])->status())->toBe(201);

    // Il provider ora dice un'altra email per lo stesso id: è sempre Anna.
    $finto->identitaDelProvider('google', 'secondo', 'altra@example.com', id: 'sub-anna');
    $stato = alFinto('POST', '/v1/accessi/provider/google/autorizzazioni')->json('data.stato');
    $seconda = arrivo('google', 'secondo', $stato);

    expect($seconda->status())->toBe(201)
        ->and(alFinto('GET', '/v1/io', gettone: $seconda->json('data.gettone.gettone'))->json('data.utente.id'))->toBe($anna['id']);
});

it("accessi.provider.crea: un account con l'email da verificare diventa verificato e la sua password non vale più (#1429)", function () {
    [$finto, $stato] = fintoConPartenza();
    $finto->persona('anna@example.com', PASSWORD, verificata: false);
    $finto->identitaDelProvider('google', 'codice', 'anna@example.com');

    expect(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD])->json('codice'))->toBe('credenziali_non_valide');

    $risposta = arrivo('google', 'codice', $stato);
    $io = alFinto('GET', '/v1/io', gettone: $risposta->json('data.gettone.gettone'));

    expect($risposta->status())->toBe(201)
        ->and($io->json('data.utente.email_verificata_il'))->toBeString()
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD])->json('codice'))->toBe('credenziali_non_valide');
});

it("accessi.provider.crea: l'email non valida e un codice che il provider rifiuta sono la stessa 422 verifica_non_riuscita (#1429)", function () {
    $finto = BackofficeFinto::attiva()->provider('google')->consenti('@example.com');
    $finto->identitaDelProvider('google', 'non-valida', 'non-una-email');
    $corpi = [];

    foreach (['non-valida', 'sconosciuto'] as $codice) {
        $stato = alFinto('POST', '/v1/accessi/provider/google/autorizzazioni')->json('data.stato');
        $risposta = arrivo('google', $codice, $stato, ['termini_accettati' => true]);
        expect($risposta->status())->toBe(422)->and($risposta->json('codice'))->toBe('verifica_non_riuscita');
        $corpi[] = $risposta->body();
    }

    expect(array_unique($corpi))->toHaveCount(1);
});

it("accessi.provider.crea: l'email che il provider non garantisce è 422 email_del_provider_non_verificata, uguale con e senza un account; non nasce niente e la password non cambia (#1555)", function () {
    $finto = BackofficeFinto::attiva()->provider('google')->consenti('@example.com');
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno', verificata: false);
    $finto->identitaDelProvider('google', 'con-account', 'bruno@example.com', verificata: false)
        ->identitaDelProvider('google', 'senza-account', 'anna@example.com', verificata: false);
    $corpi = [];

    foreach (['con-account', 'senza-account'] as $codice) {
        $stato = alFinto('POST', '/v1/accessi/provider/google/autorizzazioni')->json('data.stato');
        $risposta = arrivo('google', $codice, $stato, ['termini_accettati' => true]);
        expect($risposta->status())->toBe(422)->and($risposta->json('codice'))->toBe('email_del_provider_non_verificata');
        $corpi[] = preg_replace('/"instance":"[^"]*"/', '', $risposta->body());
    }

    expect(array_unique($corpi))->toHaveCount(1)
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD])->status())->toBe(422);

    // La password di Bruno non è cambiata: con quella stessa si verifica l'email col codice, e poi si entra.
    alFinto('POST', '/v1/io/email/codice', ['email' => 'bruno@example.com', 'password' => PASSWORD]);
    $codice = (string) $finto->ultimoCodice('bruno@example.com');
    expect(alFinto('POST', '/v1/io/email/verifica', ['email' => 'bruno@example.com', 'password' => PASSWORD, 'codice' => $codice])->status())->toBe(200)
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'bruno@example.com', 'password' => PASSWORD])->status())->toBe(201);

    // Lo stato è consumato: riportarlo è un rifiuto come gli altri.
    $stato = alFinto('POST', '/v1/accessi/provider/google/autorizzazioni')->json('data.stato');
    arrivo('google', 'senza-account', $stato);
    expect(arrivo('google', 'senza-account', $stato)->json('codice'))->toBe('verifica_non_riuscita');
});

it('accessi.provider.crea: lo stato vale una volta sola, per quel provider, e 10 minuti; ogni altro stato è 422 verifica_non_riuscita (#1429)', function () {
    $this->travelTo(Carbon::parse('2026-10-09 10:00:00'));
    $finto = BackofficeFinto::attiva()->provider('google', 'facebook')->consenti('@example.com');
    $finto->identitaDelProvider('google', 'codice', 'anna@example.com')->identitaDelProvider('facebook', 'codice', 'anna@example.com');
    $stato = fn (string $provider) => alFinto('POST', "/v1/accessi/provider/{$provider}/autorizzazioni")->json('data.stato');
    $rifiutato = fn ($risposta) => expect($risposta->status())->toBe(422)->and($risposta->json('codice'))->toBe('verifica_non_riuscita');

    $usato = $stato('google');
    expect(arrivo('google', 'codice', $usato, ['termini_accettati' => true])->status())->toBe(201);
    $rifiutato(arrivo('google', 'codice', $usato));

    // Lo stato di un provider non vale per un altro, e si consuma lo stesso.
    $diGoogle = $stato('google');
    $rifiutato(arrivo('facebook', 'codice', $diGoogle));
    $rifiutato(arrivo('google', 'codice', $diGoogle));

    $rifiutato(arrivo('google', 'codice', str_repeat('a', 43)));

    $scaduto = $stato('google');
    $this->travel(601)->seconds();
    $rifiutato(arrivo('google', 'codice', $scaduto));

    $vivo = $stato('google');
    $this->travel(600)->seconds();
    $rifiutato(arrivo('google', 'codice', $vivo));
});

it('accessi.provider.crea: uno stato che il provider rifiuta si consuma lo stesso (#1429)', function () {
    [$finto, $stato] = fintoConPartenza();
    $finto->consenti('@example.com')->identitaDelProvider('google', 'buono', 'anna@example.com');

    expect(arrivo('google', 'sbagliato', $stato, ['termini_accettati' => true])->status())->toBe(422)
        ->and(arrivo('google', 'buono', $stato, ['termini_accettati' => true])->status())->toBe(422);
});

it('accessi.provider.crea: un provider che non risponde è 503 servizio_non_disponibile (#1429)', function () {
    [$finto, $stato] = fintoConPartenza();
    $finto->consenti('@example.com')->identitaDelProvider('google', 'codice', 'anna@example.com')->guastaProvider('google');

    $risposta = arrivo('google', 'codice', $stato, ['termini_accettati' => true]);

    expect($risposta->status())->toBe(503)->and($risposta->json('codice'))->toBe('servizio_non_disponibile');
});

it('accessi.provider.crea: un provider spento o sconosciuto è 404, e i dati non validi 422 sul campo, prima dello stato (#1429)', function () {
    BackofficeFinto::attiva()->provider('google');
    $stato = str_repeat('a', 43);

    expect(arrivo('facebook', 'codice', $stato)->json('codice'))->toBe('non_trovato')
        ->and(arrivo('github', 'codice', $stato)->status())->toBe(404);

    foreach ([
        'senza il codice' => [['stato' => $stato], '#/codice'],
        'stato corto' => [['codice' => 'c', 'stato' => 'corto'], '#/stato'],
        'codice troppo lungo' => [['codice' => str_repeat('c', 2049), 'stato' => $stato], '#/codice'],
        'termini non booleani' => [['codice' => 'c', 'stato' => $stato, 'termini_accettati' => 'forse'], '#/termini_accettati'],
        'un campo in più' => [['codice' => 'c', 'stato' => $stato, 'ritorno' => 'x'], '#/ritorno'],
    ] as [$corpo, $pointer]) {
        $risposta = alFinto('POST', '/v1/accessi/provider/google', $corpo);
        expect($risposta->status())->toBe(422)
            ->and($risposta->json('codice'))->toBe('dati_non_validi')
            ->and($risposta->json('errors.0.pointer'))->toBe($pointer);
    }
});

it('accessi.provider.crea: il sesto arrivo in un minuto con lo stesso stato è 429 troppe_richieste (#1429)', function () {
    BackofficeFinto::attiva()->provider('google');
    $stato = str_repeat('b', 43);

    foreach (range(1, 5) as $_) {
        expect(arrivo('google', 'codice', $stato)->status())->toBe(422);
    }

    $frenata = arrivo('google', 'codice', $stato);
    expect($frenata->status())->toBe(429)->and($frenata->json('codice'))->toBe('troppe_richieste');

    // Un altro stato passa.
    expect(arrivo('google', 'codice', str_repeat('c', 43))->status())->toBe(422);
});
