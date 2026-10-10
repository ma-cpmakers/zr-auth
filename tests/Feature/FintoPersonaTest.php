<?php

use Zeiras\Auth\Sessione;
use Zeiras\Auth\Testing\BackofficeFinto;
use Zeiras\Auth\Testing\Finto\Testi;

// T3.1-T3.4 dello sprint 10 (#1348; per zr-home #971 e #980, DoD G13): le lingue di Zeiras, la modifica della persona e il
// cambio della password nel finto. Qui ciò che un frontend vede dal suo lato; che le risposte e gli errori siano quelli del
// backoffice, byte per byte, e che il gate 2 le passi, lo prova il FintoTest del backoffice (G12, T3.4).

const PASSWORD_DEL_CAMBIO = 'una password nuova e sicura';

/** Una persona verificata con un workspace suo: [finto, workspace, gettone dell'accesso, gettone del workspace]. */
function fintoDellaPersona(): array
{
    $finto = BackofficeFinto::attiva();
    $studio = $finto->workspace('Studio', $finto->persona('anna@example.com', PASSWORD));
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];
    $delWorkspace = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso)->json('data.gettone');

    return [$finto, $studio, $accesso, $delWorkspace];
}

// T3.1

it('lingue.elenca dà le lingue di Zeiras in ordine di codice, a qualunque gettone, anche a quello dell\'accesso (T3.1)', function () {
    [, , $accesso, $delWorkspace] = fintoDellaPersona();

    foreach ([$accesso, $delWorkspace] as $gettone) {
        $lingue = alFinto('GET', '/v1/lingue', gettone: $gettone);

        expect($lingue->status())->toBe(200)
            ->and($lingue->json('data'))->toBe([
                ['codice' => 'en', 'nome' => 'English'],
                ['codice' => 'es', 'nome' => 'Español'],
                ['codice' => 'it', 'nome' => 'Italiano'],
            ])
            ->and($lingue->json('successivo'))->toBeNull()
            // Nessuna lingua che Zeiras non parla: sono quelle di Testi::LINGUE, né una di più né una di meno.
            ->and($lingue->json('data.*.codice'))->toEqualCanonicalizing(Testi::LINGUE);
    }

    expect(alFinto('GET', '/v1/lingue')->status())->toBe(401);
});

it('lingue.elenca è una lista a cursore: il successivo porta l\'ultima lingua della pagina, e la pagina dopo parte dalla lingua dopo (T3.1)', function () {
    [, , $accesso] = fintoDellaPersona();

    $prima = alFinto('GET', '/v1/lingue?limite=2', gettone: $accesso);
    $dopo = alFinto('GET', '/v1/lingue?limite=2&cursore='.urlencode($prima->json('successivo')), gettone: $accesso);

    expect($prima->json('data.*.codice'))->toBe(['en', 'es'])
        ->and($prima->json('successivo'))->toBeString()
        ->and($dopo->json('data'))->toBe([['codice' => 'it', 'nome' => 'Italiano']])
        ->and($dopo->json('successivo'))->toBeNull()
        // Un cursore inventato, e un limite fuori misura, sono 422 come nelle altre liste.
        ->and(alFinto('GET', '/v1/lingue?cursore=inventato', gettone: $accesso)->status())->toBe(422)
        ->and(alFinto('GET', '/v1/lingue?limite=0', gettone: $accesso)->status())->toBe(422);
});

// T3.2

it('io.modifica cambia solo i campi mandati e risponde con la forma di io.mostra già aggiornata (T3.2)', function () {
    [, , $gettone] = fintoDellaPersona();

    $risposta = alFinto('PATCH', '/v1/io', ['utente' => ['nome' => '  Anna Maria  ', 'fuso_orario' => 'America/New_York']], $gettone);

    expect($risposta->status())->toBe(200)
        ->and($risposta->json('data.utente.nome'))->toBe('Anna Maria')
        ->and($risposta->json('data.utente.fuso_orario'))->toBe('America/New_York')
        // La lingua non era nel corpo: resta quella di prima.
        ->and($risposta->json('data.utente.lingua'))->toBe('it')
        ->and($risposta->json('data.workspace'))->toBeNull()
        ->and($risposta->json('data.ruolo'))->toBeNull()
        ->and($risposta->json())->toBe(alFinto('GET', '/v1/io', gettone: $gettone)->json());
});

it('io.modifica è tutto o niente: un valore sbagliato non lascia salvato un altro campo (T3.2)', function () {
    [, , $gettone] = fintoDellaPersona();
    $prima = alFinto('GET', '/v1/io', gettone: $gettone)->json();

    $risposta = alFinto('PATCH', '/v1/io', ['utente' => ['nome' => 'Nuovo nome', 'lingua' => 'de', 'fuso_orario' => 'Marte/Olympus']], $gettone);

    expect($risposta->status())->toBe(422)
        ->and($risposta->json('codice'))->toBe('dati_non_validi')
        ->and(array_column($risposta->json('errors'), 'pointer'))->toBe(['#/utente/lingua', '#/utente/fuso_orario'])
        ->and(alFinto('GET', '/v1/io', gettone: $gettone)->json())->toBe($prima);
});

it('io.modifica rifiuta un campo di altre risposte o che non esiste sul suo pointer, mai in silenzio (T3.2)', function (array $corpo, array $pointer) {
    [, , $gettone] = fintoDellaPersona();
    $prima = alFinto('GET', '/v1/io', gettone: $gettone)->json();

    $risposta = alFinto('PATCH', '/v1/io', $corpo, $gettone);

    expect($risposta->status())->toBe(422)
        ->and(array_column($risposta->json('errors'), 'pointer'))->toBe($pointer)
        ->and(alFinto('GET', '/v1/io', gettone: $gettone)->json())->toBe($prima);
})->with([
    'l\'email' => [['utente' => ['email' => 'altra@example.com']], ['#/utente/email']],
    'l\'id della persona' => [['utente' => ['id' => 'inventato']], ['#/utente/id']],
    'la verifica dell\'email' => [['utente' => ['email_verificata_il' => '2026-10-08T10:00:00.000Z']], ['#/utente/email_verificata_il']],
    'il workspace' => [['workspace' => ['nome' => 'Altro']], ['#/workspace']],
    'il ruolo' => [['ruolo' => 'proprietario'], ['#/ruolo']],
    'un campo che non esiste' => [['utente' => ['colore' => 'blu']], ['#/utente/colore']],
    'un campo che non esiste in cima' => [['nome' => 'Anna'], ['#/nome']],
]);

it('io.modifica con un corpo senza campi da cambiare è un errore sul corpo (T3.2)', function (array $corpo, string $pointer) {
    [, , $gettone] = fintoDellaPersona();

    $risposta = alFinto('PATCH', '/v1/io', $corpo, $gettone);

    expect($risposta->status())->toBe(422)
        ->and($risposta->json('errors'))->toHaveCount(1)
        ->and($risposta->json('errors.0.pointer'))->toBe($pointer);
})->with([
    'senza corpo' => [[], '#'],
    'utente vuoto' => [['utente' => []], '#/utente'],
]);

it('io.modifica col gettone dell\'accesso cambia la persona, con workspace e ruolo null, e ha gli stessi errori del gettone di un workspace (#1363, T2.1, T2.3)', function () {
    [, , $accesso] = fintoDellaPersona();

    $risposta = alFinto('PATCH', '/v1/io', ['utente' => ['nome' => 'Nuovo', 'lingua' => 'en']], $accesso);

    expect($risposta->status())->toBe(200)
        ->and($risposta->json('data.utente.nome'))->toBe('Nuovo')
        ->and($risposta->json('data.utente.lingua'))->toBe('en')
        ->and($risposta->json('data.workspace'))->toBeNull()
        ->and($risposta->json('data.ruolo'))->toBeNull()
        ->and($risposta->json('data.notifiche_non_lette'))->toBeNull()
        ->and(alFinto('GET', '/v1/io', gettone: $accesso)->json('data.utente.nome'))->toBe('Nuovo');

    foreach ([['utente' => []], ['utente' => ['lingua' => 'de']], []] as $corpo) {
        expect(alFinto('PATCH', '/v1/io', $corpo, $accesso)->status())->toBe(422);
    }
});

it('la lingua cambiata vale per le risposte dopo, anche per gli errori (T3.2)', function () {
    [, , $gettone] = fintoDellaPersona();
    $inItaliano = alFinto('PATCH', '/v1/io', ['utente' => []], $gettone)->json('errors.0.detail');

    expect(alFinto('PATCH', '/v1/io', ['utente' => ['lingua' => 'en']], $gettone)->status())->toBe(200);
    $inInglese = alFinto('PATCH', '/v1/io', ['utente' => []], $gettone)->json('errors.0.detail');

    expect($inItaliano)->toContain('almeno uno')
        ->and($inInglese)->not->toBe($inItaliano)
        ->and($inInglese)->toContain('at least one')
        ->and(alFinto('GET', '/v1/io', gettone: $gettone)->json('data.utente.lingua'))->toBe('en');
});

// T3.3

it('io.password.modifica: 204, la vecchia password non entra più e la nuova sì (T3.3)', function () {
    [, , $accesso] = fintoDellaPersona();

    $risposta = alFinto('PATCH', '/v1/io/password', ['password_attuale' => PASSWORD, 'password_nuova' => PASSWORD_DEL_CAMBIO], $accesso);

    expect($risposta->status())->toBe(204)
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD])->status())->not->toBe(201)
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD_DEL_CAMBIO])->status())->toBe(201);
});

it('io.password.modifica lascia vivi i gettoni dell\'accesso che chiama e uccide quelli degli altri accessi (T3.3)', function () {
    [, $studio, $accesso, $delWorkspace] = fintoDellaPersona();
    $altro = entraNelFinto('anna@example.com')['gettone']['gettone'];
    $delAltro = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $altro)->json('data.gettone');

    expect(alFinto('PATCH', '/v1/io/password', ['password_attuale' => PASSWORD, 'password_nuova' => PASSWORD_DEL_CAMBIO], $accesso)->status())->toBe(204)
        ->and(alFinto('GET', '/v1/io', gettone: $delWorkspace)->status())->toBe(200)
        ->and(alFinto('GET', '/v1/io', gettone: $accesso)->status())->toBe(200)
        ->and(alFinto('GET', '/v1/io', gettone: $altro)->status())->toBe(401)
        ->and(alFinto('GET', '/v1/io', gettone: $delAltro)->status())->toBe(401);
});

it('io.password.modifica: la password attuale sbagliata è 422 sul campo e non cambia niente (T3.3)', function () {
    [, , $accesso] = fintoDellaPersona();

    $risposta = alFinto('PATCH', '/v1/io/password', ['password_attuale' => PASSWORD_SBAGLIATA, 'password_nuova' => PASSWORD_DEL_CAMBIO], $accesso);

    expect($risposta->status())->toBe(422)
        ->and($risposta->json('errors.0.pointer'))->toBe('#/password_attuale')
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD])->status())->toBe(201)
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD_DEL_CAMBIO])->status())->not->toBe(201);
});

it('io.password.modifica: la nuova password che non va è 422 sul campo (T3.3)', function (string $nuova) {
    [, , $accesso] = fintoDellaPersona();

    $risposta = alFinto('PATCH', '/v1/io/password', ['password_attuale' => PASSWORD, 'password_nuova' => $nuova], $accesso);

    expect($risposta->status())->toBe(422)
        ->and($risposta->json('errors.0.pointer'))->toBe('#/password_nuova')
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD])->status())->toBe(201);
})->with([
    'di 11 caratteri' => ['undici-undi'],
    'col carattere nullo' => ["una password\0lunga e sicura"],
    'trapelata' => [BackofficeFinto::PASSWORD_TRAPELATA],
]);

it('io.password.modifica: al sesto errore sulla password attuale in un\'ora è 429, anche con quella giusta (T3.3)', function () {
    [, , $accesso] = fintoDellaPersona();

    for ($tentativo = 1; $tentativo <= 5; $tentativo++) {
        expect(alFinto('PATCH', '/v1/io/password', ['password_attuale' => PASSWORD_SBAGLIATA, 'password_nuova' => PASSWORD_DEL_CAMBIO], $accesso)->status())->toBe(422);
    }

    $sesta = alFinto('PATCH', '/v1/io/password', ['password_attuale' => PASSWORD, 'password_nuova' => PASSWORD_DEL_CAMBIO], $accesso);

    expect($sesta->status())->toBe(429)
        ->and($sesta->json('codice'))->toBe('troppe_richieste')
        ->and($sesta->header('Retry-After'))->toMatch('/^\d+$/');
});

it('io.password.modifica: una password attuale giusta azzera il conto degli errori (T3.3)', function () {
    [, , $accesso] = fintoDellaPersona();

    for ($tentativo = 1; $tentativo <= 4; $tentativo++) {
        alFinto('PATCH', '/v1/io/password', ['password_attuale' => PASSWORD_SBAGLIATA, 'password_nuova' => PASSWORD_DEL_CAMBIO], $accesso);
    }

    // La giusta passa e azzera; la nuova diventa quella di adesso, e altri quattro errori non bastano a frenare.
    expect(alFinto('PATCH', '/v1/io/password', ['password_attuale' => PASSWORD, 'password_nuova' => PASSWORD_DEL_CAMBIO], $accesso)->status())->toBe(204);

    for ($tentativo = 1; $tentativo <= 4; $tentativo++) {
        expect(alFinto('PATCH', '/v1/io/password', ['password_attuale' => PASSWORD_SBAGLIATA, 'password_nuova' => PASSWORD], $accesso)->status())->toBe(422);
    }
});

it('io.password.modifica senza gettone è 401, e un corpo con un campo in più è 422 sul campo (T3.3)', function () {
    [, , $accesso] = fintoDellaPersona();

    expect(alFinto('PATCH', '/v1/io/password', ['password_attuale' => PASSWORD, 'password_nuova' => PASSWORD_DEL_CAMBIO])->status())->toBe(401);

    $risposta = alFinto('PATCH', '/v1/io/password', ['password_attuale' => PASSWORD, 'password_nuova' => PASSWORD_DEL_CAMBIO, 'email' => 'altra@example.com'], $accesso);

    expect($risposta->status())->toBe(422)->and($risposta->json('errors.0.pointer'))->toBe('#/email');
});

it('le tre scritture sulla persona col gettone di un workspace sono 403 gettone_con_workspace prima del corpo, e non cambiano niente (#1412, T5.4)', function () {
    [, , $accesso, $delWorkspace] = fintoDellaPersona();

    $scritture = [
        ['PATCH', '/v1/io', ['utente' => ['nome' => 'Altro nome']]],
        ['PATCH', '/v1/io/password', ['password_attuale' => PASSWORD, 'password_nuova' => PASSWORD_DEL_CAMBIO]],
        ['POST', '/v1/io/workspace', ['nome' => 'Secondo studio']],
    ];

    foreach ($scritture as [$metodo, $percorso, $corpo]) {
        foreach ([$corpo, ['x' => 1]] as $inviato) {
            $risposta = alFinto($metodo, $percorso, $inviato, $delWorkspace);

            expect($risposta->status())->toBe(403)
                ->and($risposta->json('codice'))->toBe('gettone_con_workspace');
        }
    }

    expect(alFinto('GET', '/v1/io', gettone: $accesso)->json('data.utente.nome'))->not->toBe('Altro nome')
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD])->status())->toBe(201)
        ->and(alFinto('GET', '/v1/io/workspace', gettone: $accesso)->json('data'))->toHaveCount(1);
});

// #1473 (T2.5): la lingua cambiata con io.modifica si rilegge da io.mostra e arriva alla sessione del modulo.

it('la lingua cambiata da io.modifica arriva alla sessione con io.mostra e Sessione::aggiorna (#1473 T2.5)', function () {
    [, , $gettone] = fintoDellaPersona();
    Sessione::apri(entraNelFinto('anna@example.com'));
    expect(Sessione::utente()['lingua'])->toBe('it');

    expect(alFinto('PATCH', '/v1/io', ['utente' => ['lingua' => 'en', 'nome' => 'Anne']], $gettone)->status())->toBe(200);
    $io = alFinto('GET', '/v1/io', gettone: $gettone)->json('data');

    expect(Sessione::aggiorna($io))->toBeTrue()
        ->and(Sessione::utente()['lingua'])->toBe('en')
        ->and(Sessione::utente()['nome'])->toBe('Anne');
});
