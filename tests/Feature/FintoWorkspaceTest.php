<?php

use Illuminate\Http\Client\Response;
use Zeiras\Auth\Testing\BackofficeFinto;

// T4.1-T4.6 dello sprint 10 (#1348; per zr-home #1252, DoD G13): il nome del workspace, i membri e gli inviti nel finto. Qui
// ciò che un frontend vede dal suo lato, e gli invarianti; che le risposte e gli errori siano quelli del backoffice, byte per
// byte, e che il gate 2 le passi, lo prova il FintoTest del backoffice (G12).

/**
 * Un workspace con tre persone: Anna proprietaria, Bruno amministratore, Carla membro. [finto, workspace, persone, gettone],
 * dove `gettone(email)` entra e dà il gettone di quel workspace.
 */
function squadra(): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio', $anna);
    $bruno = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $carla = $finto->persona('carla@example.com', PASSWORD, nome: 'Carla');
    $finto->membro($studio, $bruno, 'amministratore');
    $finto->membro($studio, $carla, 'membro');
    $gettone = fn (string $email) => alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto($email)['gettone']['gettone'])->json('data.gettone');

    return [$finto, $studio, ['anna' => $anna, 'bruno' => $bruno, 'carla' => $carla], $gettone];
}

/** L'header Idempotency-Key con un valore: scritto con un valore variabile, la guardia dei segreti non lo prende per una chiave. */
function conChiave(string $valore): array
{
    return ['Idempotency-Key' => $valore];
}

/** Gli errori di un problema: lo stato e il codice. */
function esito(Response $risposta): array
{
    return [$risposta->status(), $risposta->json('codice')];
}

// T4.1

it('workspace.modifica cambia il nome del workspace del gettone, senza gli spazi ai bordi e con lo slug invariato (T4.1)', function () {
    [, $studio, , $gettone] = squadra();
    $anna = $gettone('anna@example.com');

    $risposta = alFinto('PATCH', '/v1/workspace', ['nome' => '  Studio Rossi  '], $anna);

    expect($risposta->status())->toBe(200)
        ->and($risposta->json('data'))->toBe([...$studio, 'nome' => 'Studio Rossi', 'ruolo' => 'proprietario'])
        ->and(alFinto('GET', '/v1/io', gettone: $anna)->json('data.workspace.nome'))->toBe('Studio Rossi')
        ->and(alFinto('GET', '/v1/io/workspace', gettone: $anna)->json('data.0'))->toBe([...$studio, 'nome' => 'Studio Rossi', 'ruolo' => 'proprietario'])
        // Lo stesso nome di prima è un 200 che non cambia niente; l'amministratore lo cambia come il proprietario.
        ->and(alFinto('PATCH', '/v1/workspace', ['nome' => 'Studio Rossi'], $anna)->status())->toBe(200)
        ->and(alFinto('PATCH', '/v1/workspace', ['nome' => 'Studio Bianchi'], $gettone('bruno@example.com'))->json('data.ruolo'))->toBe('amministratore');
});

it('workspace.modifica non è di un membro (403 prima del corpo), e il nome vuoto, di soli spazi o oltre 255 è 422 sul nome (T4.1)', function () {
    [, , , $gettone] = squadra();
    $anna = $gettone('anna@example.com');
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];

    expect(esito(alFinto('PATCH', '/v1/workspace', ['campo' => 'in più'], $gettone('carla@example.com'))))->toBe([403, 'permesso_negato'])
        ->and(esito(alFinto('PATCH', '/v1/workspace', ['nome' => 'Studio'], $accesso)))->toBe([403, 'gettone_senza_workspace']);

    foreach ([[], ['nome' => ''], ['nome' => '   '], ['nome' => str_repeat('a', 256)]] as $corpo) {
        $risposta = alFinto('PATCH', '/v1/workspace', $corpo, $anna);

        expect(esito($risposta))->toBe([422, 'dati_non_validi'])->and($risposta->json('errors.0.pointer'))->toBe('#/nome');
    }

    expect(alFinto('PATCH', '/v1/workspace', ['nome' => str_repeat('a', 255)], $anna)->status())->toBe(200);
});

// T4.2

it('workspace.membri.modifica cambia il ruolo, e membri.elenca e io.mostra del membro lo dicono (T4.2)', function () {
    [, , $persone, $gettone] = squadra();
    $anna = $gettone('anna@example.com');
    $carla = $gettone('carla@example.com');

    $risposta = alFinto('PATCH', "/v1/workspace/membri/{$persone['carla']['id']}", ['ruolo' => 'amministratore'], $anna);

    expect($risposta->status())->toBe(200)
        ->and($risposta->json('data'))->toBe(['id' => $persone['carla']['id'], 'nome' => 'Carla', 'email' => 'carla@example.com', 'ruolo' => 'amministratore'])
        ->and(collect(alFinto('GET', '/v1/workspace/membri', gettone: $anna)->json('data'))->firstWhere('id', $persone['carla']['id'])['ruolo'])->toBe('amministratore')
        ->and(alFinto('GET', '/v1/io', gettone: $carla)->json('data.ruolo'))->toBe('amministratore')
        // Lo stesso ruolo di prima non scrive niente, e risponde uguale.
        ->and(alFinto('PATCH', "/v1/workspace/membri/{$persone['carla']['id']}", ['ruolo' => 'amministratore'], $anna)->json('data.ruolo'))->toBe('amministratore')
        ->and(alFinto('PATCH', "/v1/workspace/membri/{$persone['carla']['id']}", ['ruolo' => 'membro'], $anna)->json('data.ruolo'))->toBe('membro');
});

it('workspace.membri.modifica risponde nell\'ordine del backoffice: il ruolo di chi chiama, il membro (404), il corpo (422), il proprietario (409), l\'amministratore (403) (T4.2)', function () {
    [$finto, $studio, $persone, $gettone] = squadra();
    $dario = $finto->persona('dario@example.com', PASSWORD, nome: 'Dario');
    $finto->membro($studio, $dario, 'amministratore');
    $anna = $gettone('anna@example.com');
    $bruno = $gettone('bruno@example.com');
    $cambia = fn (string $gettone, string $id, array $corpo) => esito(alFinto('PATCH', "/v1/workspace/membri/{$id}", $corpo, $gettone));

    expect($cambia($gettone('carla@example.com'), 'inventato', ['ruolo' => 'x']))->toBe([403, 'permesso_negato'])
        ->and($cambia($anna, 'inventato', ['ruolo' => 'x']))->toBe([404, 'non_trovato'])
        ->and($cambia($anna, $persone['carla']['id'], ['ruolo' => 'proprietario']))->toBe([422, 'dati_non_validi'])
        ->and($cambia($anna, $persone['anna']['id'], ['ruolo' => 'x']))->toBe([422, 'dati_non_validi'])
        ->and($cambia($bruno, $persone['anna']['id'], ['ruolo' => 'membro']))->toBe([409, 'proprietario_intoccabile'])
        // Un amministratore non tocca un amministratore, e non fa un amministratore.
        ->and($cambia($bruno, $dario['id'], ['ruolo' => 'membro']))->toBe([403, 'permesso_negato'])
        ->and($cambia($bruno, $persone['carla']['id'], ['ruolo' => 'amministratore']))->toBe([403, 'permesso_negato'])
        ->and($cambia($bruno, $persone['carla']['id'], ['ruolo' => 'membro'])[0])->toBe(200);
});

// T4.3

it('workspace.membri.elimina toglie il membro e i suoi gettoni di quel workspace, e non quelli dell\'accesso né degli altri workspace (T4.3)', function () {
    [$finto, $studio, $persone, $gettone] = squadra();
    $altro = $finto->workspace('Altro', $persone['carla']);
    $anna = $gettone('anna@example.com');
    $accessoDiCarla = entraNelFinto('carla@example.com')['gettone']['gettone'];
    $carlaQui = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accessoDiCarla)->json('data.gettone');
    $carlaLi = alFinto('POST', '/v1/gettoni', ['workspace_id' => $altro['id']], $accessoDiCarla)->json('data.gettone');

    $risposta = alFinto('DELETE', "/v1/workspace/membri/{$persone['carla']['id']}", gettone: $anna);

    expect($risposta->status())->toBe(204)
        ->and($risposta->body())->toBe('')
        ->and(collect(alFinto('GET', '/v1/workspace/membri', gettone: $anna)->json('data'))->pluck('id')->all())->not->toContain($persone['carla']['id'])
        ->and(esito(alFinto('GET', '/v1/io', gettone: $carlaQui)))->toBe([401, 'gettone_non_valido'])
        ->and(alFinto('GET', '/v1/io', gettone: $accessoDiCarla)->status())->toBe(200)
        ->and(alFinto('GET', '/v1/io', gettone: $carlaLi)->json('data.workspace.id'))->toBe($altro['id'])
        // Tolta una volta, non c'è più: 404. E se rientra, il gettone di prima non risorge.
        ->and(esito(alFinto('DELETE', "/v1/workspace/membri/{$persone['carla']['id']}", gettone: $anna)))->toBe([404, 'non_trovato']);

    $finto->membro($studio, $persone['carla'], 'membro');

    expect(esito(alFinto('GET', '/v1/io', gettone: $carlaQui)))->toBe([401, 'gettone_non_valido']);
});

it('workspace.membri.elimina non tocca il proprietario (409), né un amministratore se chi chiama lo è (403), né chi è di un altro workspace (404) (T4.3)', function () {
    [$finto, , $persone, $gettone] = squadra();
    $estraneo = $finto->persona('elena@example.com', PASSWORD, nome: 'Elena');
    $finto->workspace('Altro', $estraneo);
    $bruno = $gettone('bruno@example.com');
    $toglie = fn (string $gettone, string $id) => esito(alFinto('DELETE', "/v1/workspace/membri/{$id}", gettone: $gettone));

    expect($toglie($gettone('carla@example.com'), $persone['bruno']['id']))->toBe([403, 'permesso_negato'])
        ->and($toglie($gettone('anna@example.com'), $persone['anna']['id']))->toBe([409, 'proprietario_intoccabile'])
        ->and($toglie($bruno, $persone['anna']['id']))->toBe([409, 'proprietario_intoccabile'])
        // L'amministratore non toglie sé stesso: è un amministratore.
        ->and($toglie($bruno, $persone['bruno']['id']))->toBe([403, 'permesso_negato'])
        ->and($toglie($bruno, $estraneo['id']))->toBe([404, 'non_trovato'])
        ->and($toglie($bruno, $persone['carla']['id'])[0])->toBe(204);
});

// T4.4

it('workspace.inviti.crea dà 201 con l\'invito, senza il codice, che si legge solo da ultimoInvito() (T4.4)', function () {
    [$finto, , , $gettone] = squadra();
    $this->freezeTime();
    $anna = $gettone('anna@example.com');

    expect($finto->ultimoInvito('dora@example.com'))->toBeNull();

    $risposta = alFinto('POST', '/v1/workspace/inviti', ['email' => '  Dora@Example.com ', 'ruolo' => 'membro'], $anna);
    $invito = $risposta->json('data');
    $codice = $finto->ultimoInvito('DORA@example.com');

    expect($risposta->status())->toBe(201)
        ->and(array_keys($invito))->toBe(['id', 'email', 'ruolo', 'scade_il', 'creato_il'])
        ->and($invito['email'])->toBe('dora@example.com')
        ->and($invito['ruolo'])->toBe('membro')
        ->and($invito['scade_il'])->toBe(iso(now()->addDays(7)->startOfMillisecond()))
        ->and($invito['creato_il'])->toBe(iso(now()->startOfMillisecond()))
        ->and($risposta->header('Location'))->toBe("/v1/workspace/inviti/{$invito['id']}")
        ->and($codice)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        // Il codice non esce da nessuna risposta.
        ->and($risposta->body())->not->toContain($codice)
        ->and(alFinto('GET', '/v1/workspace/inviti', gettone: $anna)->body())->not->toContain($codice);
});

it('workspace.inviti.crea risponde allo stesso modo per un\'email con un account e per una senza (T4.4, G11)', function () {
    [$finto, , , $gettone] = squadra();
    $finto->persona('dora@example.com', PASSWORD, nome: 'Dora');
    $anna = $gettone('anna@example.com');

    $conAccount = alFinto('POST', '/v1/workspace/inviti', ['email' => 'dora@example.com', 'ruolo' => 'membro'], $anna);
    $senza = alFinto('POST', '/v1/workspace/inviti', ['email' => 'ettore@example.com', 'ruolo' => 'membro'], $anna);
    $forma = fn ($risposta) => [$risposta->status(), array_keys($risposta->json('data')), $risposta->json('data.ruolo'), array_keys($risposta->headers())];

    expect($forma($conAccount))->toBe($forma($senza));
});

it('workspace.inviti.crea: un amministratore invita solo come membro (403), il proprietario non si invita (422), il membro non invita (403 prima del corpo) (T4.4)', function () {
    [, , , $gettone] = squadra();
    $anna = $gettone('anna@example.com');
    $bruno = $gettone('bruno@example.com');
    $invita = fn (string $gettone, array $corpo) => alFinto('POST', '/v1/workspace/inviti', $corpo, $gettone);

    expect(esito($invita($gettone('carla@example.com'), ['campo' => 'in più'])))->toBe([403, 'permesso_negato'])
        ->and(esito($invita($bruno, ['email' => 'dora@example.com', 'ruolo' => 'amministratore'])))->toBe([403, 'permesso_negato'])
        ->and($invita($bruno, ['email' => 'dora@example.com', 'ruolo' => 'membro'])->status())->toBe(201)
        ->and($invita($anna, ['email' => 'ettore@example.com', 'ruolo' => 'amministratore'])->json('data.ruolo'))->toBe('amministratore');

    $proprietario = $invita($anna, ['email' => 'franco@example.com', 'ruolo' => 'proprietario']);
    $senzaEmail = $invita($anna, ['ruolo' => 'membro']);
    $inPiu = $invita($anna, ['email' => 'franco@example.com', 'ruolo' => 'membro', 'workspace_id' => 'altro']);

    expect(esito($proprietario))->toBe([422, 'dati_non_validi'])->and($proprietario->json('errors.0.pointer'))->toBe('#/ruolo')
        ->and($senzaEmail->json('errors.0.pointer'))->toBe('#/email')
        ->and($inPiu->json('errors.0.pointer'))->toBe('#/workspace_id');
});

it('workspace.inviti.crea: chi è già membro è 409 gia_membro, un invito vivo per la stessa email 409 invito_esistente, e uno scaduto si rifà (T4.4)', function () {
    [, , , $gettone] = squadra();
    $anna = $gettone('anna@example.com');
    $invita = fn (string $email) => alFinto('POST', '/v1/workspace/inviti', ['email' => $email, 'ruolo' => 'membro'], $anna);

    expect(esito($invita('carla@example.com')))->toBe([409, 'gia_membro'])
        ->and($invita('dora@example.com')->status())->toBe(201)
        ->and(esito($invita('dora@example.com')))->toBe([409, 'invito_esistente']);

    // Dopo sette giorni l'invito è scaduto: non è nell'elenco e se ne rifà uno; i gettoni durano 12 ore, e si rientra.
    $this->travel(8)->days();
    $anna = $gettone('anna@example.com');

    expect(alFinto('GET', '/v1/workspace/inviti', gettone: $anna)->json('data'))->toBe([])
        ->and(alFinto('POST', '/v1/workspace/inviti', ['email' => 'dora@example.com', 'ruolo' => 'membro'], $anna)->status())->toBe(201)
        ->and(alFinto('GET', '/v1/workspace/inviti', gettone: $anna)->json('data.*.email'))->toBe(['dora@example.com']);
});

it('workspace.inviti.crea frena: oltre 5 inviti in un\'ora verso la stessa email, 429 con Retry-After (T4.4)', function () {
    [, , , $gettone] = squadra();
    $anna = $gettone('anna@example.com');
    $invita = fn () => alFinto('POST', '/v1/workspace/inviti', ['email' => 'dora@example.com', 'ruolo' => 'membro'], $anna);

    // Il primo è 201, i quattro dopo 409 invito_esistente: il freno li conta tutti, prima del resto.
    expect($invita()->status())->toBe(201);

    foreach (range(1, 4) as $_) {
        expect($invita()->status())->toBe(409);
    }

    $frenata = $invita();

    expect(esito($frenata))->toBe([429, 'troppe_richieste'])
        ->and((int) $frenata->header('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(3600);

    $this->travel(61)->minutes();

    expect(alFinto('POST', '/v1/workspace/inviti', ['email' => 'dora@example.com', 'ruolo' => 'membro'], $gettone('anna@example.com'))->status())->toBe(409);
});

it('workspace.inviti.crea: al più 50 inviti in un\'ora dal workspace (429), e al più 100 vivi (409 limite_raggiunto) (T4.4)', function () {
    [, , , $gettone] = squadra();
    $anna = $gettone('anna@example.com');
    $invita = fn (int $numero, string $gettone) => alFinto('POST', '/v1/workspace/inviti', ['email' => "invitata{$numero}@example.com", 'ruolo' => 'membro'], $gettone);

    foreach (range(1, 50) as $numero) {
        expect($invita($numero, $anna)->status())->toBe(201);
    }

    expect(esito($invita(51, $anna)))->toBe([429, 'troppe_richieste']);

    $this->travel(61)->minutes();
    $anna = $gettone('anna@example.com');

    foreach (range(51, 100) as $numero) {
        expect($invita($numero, $anna)->status())->toBe(201);
    }

    $this->travel(61)->minutes();
    $anna = $gettone('anna@example.com');

    expect(esito($invita(101, $anna)))->toBe([409, 'limite_raggiunto']);

    // La revoca libera un posto sotto il tetto.
    $primo = alFinto('GET', '/v1/workspace/inviti?limite=1', gettone: $anna)->json('data.0.id');

    expect(alFinto('DELETE', "/v1/workspace/inviti/{$primo}", gettone: $anna)->status())->toBe(204)
        ->and($invita(101, $anna)->status())->toBe(201);
});

it('workspace.inviti.crea con la stessa Idempotency-Key e lo stesso corpo dà la stessa risposta senza un secondo invito; con un altro corpo è 422 (T4.4)', function () {
    [$finto, , , $gettone] = squadra();
    $anna = $gettone('anna@example.com');
    $corpo = ['email' => 'dora@example.com', 'ruolo' => 'membro'];
    $chiave = conChiave('invita-dora-1');

    $prima = alFinto('POST', '/v1/workspace/inviti', $corpo, $anna, intestazioni: $chiave);
    $codice = $finto->ultimoInvito('dora@example.com');
    $seconda = alFinto('POST', '/v1/workspace/inviti', ['ruolo' => 'membro', 'email' => ' dora@example.com'], $anna, intestazioni: $chiave);
    $altro = alFinto('POST', '/v1/workspace/inviti', ['email' => 'dora@example.com', 'ruolo' => 'amministratore'], $anna, intestazioni: $chiave);

    expect($prima->status())->toBe(201)
        ->and($seconda->status())->toBe(201)
        ->and($seconda->json())->toBe($prima->json())
        ->and($seconda->header('Location'))->toBe($prima->header('Location'))
        // Ripetuta, non rimanda la posta: il codice è quello di prima.
        ->and($finto->ultimoInvito('dora@example.com'))->toBe($codice)
        ->and(alFinto('GET', '/v1/workspace/inviti', gettone: $anna)->json('data'))->toHaveCount(1)
        ->and(esito($altro))->toBe([422, 'chiave_idempotenza_riusata']);

    // Una chiave che non è ASCII visibile è un errore sull'header, e un errore non si ricorda.
    $storta = alFinto('POST', '/v1/workspace/inviti', ['email' => 'ettore@example.com', 'ruolo' => 'membro'], $anna, intestazioni: conChiave('con spazio'));

    expect(esito($storta))->toBe([422, 'dati_non_validi'])->and($storta->json('errors.0.header'))->toBe('Idempotency-Key');
});

it('workspace.inviti.elenca dà i vivi dal più recente, a cursore, senza il codice (T4.4)', function () {
    [, , , $gettone] = squadra();
    $anna = $gettone('anna@example.com');

    foreach (['dora', 'ettore', 'franco'] as $nome) {
        alFinto('POST', '/v1/workspace/inviti', ['email' => "{$nome}@example.com", 'ruolo' => 'membro'], $anna);
    }

    $prima = alFinto('GET', '/v1/workspace/inviti?limite=2', gettone: $anna);
    $dopo = alFinto('GET', '/v1/workspace/inviti?limite=2&cursore='.urlencode($prima->json('successivo')), gettone: $anna);

    expect($prima->json('data.*.email'))->toBe(['franco@example.com', 'ettore@example.com'])
        ->and($prima->json('successivo'))->toBeString()
        ->and($dopo->json('data.*.email'))->toBe(['dora@example.com'])
        ->and($dopo->json('successivo'))->toBeNull()
        ->and(array_keys($dopo->json('data.0')))->toBe(['id', 'email', 'ruolo', 'scade_il', 'creato_il'])
        ->and(esito(alFinto('GET', '/v1/workspace/inviti', gettone: $gettone('carla@example.com'))))->toBe([403, 'permesso_negato'])
        ->and(esito(alFinto('GET', '/v1/workspace/inviti?limite=0', gettone: $anna)))->toBe([422, 'dati_non_validi']);
});

it('workspace.inviti.elimina revoca l\'invito (204, poi 404) e il suo codice non vale più; l\'invito a un amministratore lo revoca il proprietario (T4.4)', function () {
    [$finto, , , $gettone] = squadra();
    $anna = $gettone('anna@example.com');
    $bruno = $gettone('bruno@example.com');
    $semplice = alFinto('POST', '/v1/workspace/inviti', ['email' => 'dora@example.com', 'ruolo' => 'membro'], $anna)->json('data.id');
    $daAmministratore = alFinto('POST', '/v1/workspace/inviti', ['email' => 'ettore@example.com', 'ruolo' => 'amministratore'], $anna)->json('data.id');
    $codice = $finto->ultimoInvito('dora@example.com');
    $finto->persona('dora@example.com', PASSWORD, nome: 'Dora');
    $accesso = entraNelFinto('dora@example.com')['gettone']['gettone'];

    expect(esito(alFinto('DELETE', "/v1/workspace/inviti/{$daAmministratore}", gettone: $bruno)))->toBe([403, 'permesso_negato'])
        ->and(esito(alFinto('DELETE', "/v1/workspace/inviti/{$semplice}", gettone: $gettone('carla@example.com'))))->toBe([403, 'permesso_negato'])
        ->and(alFinto('DELETE', "/v1/workspace/inviti/{$semplice}", gettone: $bruno)->status())->toBe(204)
        ->and(esito(alFinto('DELETE', "/v1/workspace/inviti/{$semplice}", gettone: $bruno)))->toBe([404, 'non_trovato'])
        ->and(alFinto('DELETE', "/v1/workspace/inviti/{$daAmministratore}", gettone: $anna)->status())->toBe(204)
        ->and(alFinto('GET', '/v1/workspace/inviti', gettone: $anna)->json('data'))->toBe([])
        ->and(esito(alFinto('POST', '/v1/inviti/accettazione', ['codice' => $codice], $accesso)))->toBe([422, 'verifica_non_riuscita']);
});

// T4.5

it('inviti.accettazione.crea: la persona del gettone dell\'accesso entra col ruolo dell\'invito, se l\'email è la sua (T4.5)', function () {
    [$finto, $studio, , $gettone] = squadra();
    $dora = $finto->persona('dora@example.com', PASSWORD, nome: 'Dora');
    alFinto('POST', '/v1/workspace/inviti', ['email' => 'dora@example.com', 'ruolo' => 'amministratore'], $gettone('anna@example.com'));
    $codice = $finto->ultimoInvito('dora@example.com');
    $accesso = entraNelFinto('dora@example.com')['gettone']['gettone'];

    $risposta = alFinto('POST', '/v1/inviti/accettazione', ['codice' => $codice], $accesso);

    expect($risposta->status())->toBe(201)
        ->and($risposta->json('data'))->toBe(['id' => $dora['id'], 'nome' => 'Dora', 'email' => 'dora@example.com', 'ruolo' => 'amministratore'])
        ->and(alFinto('GET', '/v1/io/workspace', gettone: $accesso)->json('data.0.id'))->toBe($studio['id'])
        // L'invito si è consumato: non si accetta due volte, e non è più nell'elenco.
        ->and(esito(alFinto('POST', '/v1/inviti/accettazione', ['codice' => $codice], $accesso)))->toBe([422, 'verifica_non_riuscita'])
        ->and(alFinto('GET', '/v1/workspace/inviti', gettone: $gettone('anna@example.com'))->json('data'))->toBe([]);
});

it('inviti.accettazione.crea: ogni fallimento è la stessa 422, e il gettone di un workspace è 403 (T4.5)', function () {
    [$finto, , , $gettone] = squadra();
    $finto->persona('dora@example.com', PASSWORD, nome: 'Dora');
    $finto->persona('ettore@example.com', PASSWORD, nome: 'Ettore');
    alFinto('POST', '/v1/workspace/inviti', ['email' => 'dora@example.com', 'ruolo' => 'membro'], $gettone('anna@example.com'));
    $codice = $finto->ultimoInvito('dora@example.com');
    $accessoDiEttore = entraNelFinto('ettore@example.com')['gettone']['gettone'];
    $accetta = fn (string $codice) => alFinto('POST', '/v1/inviti/accettazione', ['codice' => $codice], $accessoDiEttore);

    // Un invito per un'altra email e un codice sconosciuto: la stessa risposta, parola per parola.
    expect(esito($accetta($codice)))->toBe([422, 'verifica_non_riuscita'])
        ->and($accetta($codice)->json())->toBe($accetta('inventato')->json())
        ->and(esito(alFinto('POST', '/v1/inviti/accettazione', ['codice' => $codice], $gettone('carla@example.com'))))->toBe([403, 'gettone_con_workspace']);

    $mancante = alFinto('POST', '/v1/inviti/accettazione', [], $accessoDiEttore);
    $inPiu = alFinto('POST', '/v1/inviti/accettazione', ['codice' => $codice, 'altro' => 1], $accessoDiEttore);

    expect(esito($mancante))->toBe([422, 'dati_non_validi'])->and($mancante->json('errors.0.pointer'))->toBe('#/codice')
        ->and($inPiu->json('errors.0.pointer'))->toBe('#/altro');
});

it('inviti.accettazione.crea: chi è già membro è 409 gia_membro (T4.5)', function () {
    [$finto, $studio, , $gettone] = squadra();
    $dora = $finto->persona('dora@example.com', PASSWORD, nome: 'Dora');
    alFinto('POST', '/v1/workspace/inviti', ['email' => 'dora@example.com', 'ruolo' => 'membro'], $gettone('anna@example.com'));
    $codice = $finto->ultimoInvito('dora@example.com');
    // Dora entra per un'altra via, mentre l'invito è ancora vivo.
    $finto->membro($studio, $dora, 'membro');

    expect(esito(alFinto('POST', '/v1/inviti/accettazione', ['codice' => $codice], entraNelFinto('dora@example.com')['gettone']['gettone'])))->toBe([409, 'gia_membro']);
});

it('utenti.crea con `invito`: la persona nasce con l\'email verificata ed entra nel workspace, anche a registrazione chiusa (T4.5)', function () {
    [$finto, $studio, , $gettone] = squadra();
    alFinto('POST', '/v1/workspace/inviti', ['email' => 'dora@example.com', 'ruolo' => 'membro'], $gettone('anna@example.com'));
    $codice = $finto->ultimoInvito('dora@example.com');

    $risposta = alFinto('POST', '/v1/utenti', ['email' => 'Dora@example.com', 'password' => PASSWORD, 'termini_accettati' => true, 'invito' => $codice]);
    $accesso = entraNelFinto('dora@example.com');

    expect($risposta->status())->toBe(202)
        ->and($risposta->json())->toBe(['data' => ['email' => 'dora@example.com']])
        ->and($finto->ultimoCodice('dora@example.com'))->toBeNull()
        ->and($accesso['gettone']['utente']['email_verificata_il'])->toBeString()
        ->and(alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso['gettone']['gettone'])->json('data.ruolo'))->toBe('membro')
        // L'invito si è consumato.
        ->and(alFinto('GET', '/v1/workspace/inviti', gettone: $gettone('anna@example.com'))->json('data'))->toBe([]);
});

it('utenti.crea con `invito` per un\'altra email, revocato o sconosciuto è sempre la stessa 422; per un\'email con un account non lo accetta (T4.5)', function () {
    [$finto, , , $gettone] = squadra();
    $anna = $gettone('anna@example.com');
    alFinto('POST', '/v1/workspace/inviti', ['email' => 'dora@example.com', 'ruolo' => 'membro'], $anna);
    $codice = $finto->ultimoInvito('dora@example.com');
    $registra = fn (string $email, string $invito) => alFinto('POST', '/v1/utenti', ['email' => $email, 'password' => PASSWORD, 'termini_accettati' => true, 'invito' => $invito]);

    $altraEmail = $registra('ettore@example.com', $codice);
    $sconosciuto = $registra('dora@example.com', 'inventato');

    expect(esito($altraEmail))->toBe([422, 'verifica_non_riuscita'])
        ->and($altraEmail->json())->toBe($sconosciuto->json());

    $id = alFinto('GET', '/v1/workspace/inviti', gettone: $anna)->json('data.0.id');
    alFinto('DELETE', "/v1/workspace/inviti/{$id}", gettone: $anna);

    expect(esito($registra('dora@example.com', $codice)))->toBe([422, 'verifica_non_riuscita']);

    // Chi ha già un account riceve la stessa 202, e l'invito non lo accetta: lo accetta lei, col suo gettone.
    alFinto('POST', '/v1/workspace/inviti', ['email' => 'carla2@example.com', 'ruolo' => 'membro'], $anna);
    $finto->persona('carla2@example.com', PASSWORD, nome: 'Carla');
    $perCarla = $finto->ultimoInvito('carla2@example.com');
    $risposta = $registra('carla2@example.com', $perCarla);

    expect($risposta->status())->toBe(202)
        ->and(alFinto('GET', '/v1/io/workspace', gettone: entraNelFinto('carla2@example.com')['gettone']['gettone'])->json('data'))->toBe([]);
});

// ultimoInvito, e il ricevitore

it('ultimoInvito() è la casella di posta del finto: l\'ultimo codice partito per l\'email, e non il codice di verifica (T4.4)', function () {
    [$finto, , , $gettone] = squadra();
    $anna = $gettone('anna@example.com');

    alFinto('POST', '/v1/workspace/inviti', ['email' => 'dora@example.com', 'ruolo' => 'membro'], $anna);
    $primo = $finto->ultimoInvito('dora@example.com');
    $id = alFinto('GET', '/v1/workspace/inviti', gettone: $anna)->json('data.0.id');
    alFinto('DELETE', "/v1/workspace/inviti/{$id}", gettone: $anna);
    alFinto('POST', '/v1/workspace/inviti', ['email' => 'dora@example.com', 'ruolo' => 'membro'], $anna);

    expect($primo)->not->toBe($finto->ultimoInvito('dora@example.com'))
        ->and($finto->ultimoCodice('dora@example.com'))->toBeNull();
});
