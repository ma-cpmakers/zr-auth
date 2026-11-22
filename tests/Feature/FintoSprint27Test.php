<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// Sprint 27 del backoffice: il cestino delle cartelle (#1695), inviti.verifica.crea (#1731) e l'accordo sul trattamento dei dati
// (#1700). Qui ciò che un frontend vede dal suo lato; che le risposte e gli errori siano quelli del backoffice lo prova il
// FintoTest del backoffice (G67).

const ACCORDO_DI_PROVA = ['versione' => 'bozza-0.1', 'in_vigore_dal' => '2026-10-12', 'impronta' => 'bb748167b1d63fa9306ad2e348524d06c87b1c718188954a0954ddec9f27101e'];

const DOCUMENTI_DELLO_SPRINT_27 = [
    'informativa-privacy' => ['versione' => 'bozza-0.1', 'in_vigore_dal' => '2026-10-11', 'impronta' => 'c017cdba0f35ad579ff3b001cf1600f63d9731bc51c610f52b38e47e78abd57a'],
    'termini-d-uso' => ['versione' => 'bozza-0.1', 'in_vigore_dal' => '2026-10-11', 'impronta' => '73ca77acbe0a4b7264e287bbbee56238ba1145b9bf4c5cc7ecd063e9969f08fd'],
];

/** L'accesso di una persona che ha già accettato i documenti suoi: con l'interruttore acceso ogni metodo li vuole. */
function accessoConIDocumentiAccettati(string $email): string
{
    $accesso = entraNelFinto($email)['gettone']['gettone'];

    foreach (DOCUMENTI_DELLO_SPRINT_27 as $documento => $dati) {
        alFinto('POST', '/v1/legale/accettazioni', ['documento' => $documento, 'versione' => $dati['versione']], $accesso);
    }

    return $accesso;
}

it('board.cartelle.elenca: ogni cartella porta stato e cestino; di default le attive, con ?stato=cestino le nel cestino (#1695)', function () {
    $this->freezeTime();
    [$finto, $studio, , , $gettone] = conLeBoard();
    $finto->cartella($studio, 'Viva');
    $finto->cartella($studio, 'Vecchia', stato: 'cestino');

    $attive = alFinto('GET', '/v1/board/cartelle', null, $gettone);
    $cestino = alFinto('GET', '/v1/board/cartelle?stato=cestino', null, $gettone);

    expect(array_column($attive->json('data'), 'nome'))->toBe(['Viva'])
        ->and($attive->json('data.0.stato'))->toBe('attiva')
        ->and($attive->json('data.0.nel_cestino_il'))->toBeNull()
        ->and($attive->json('data.0.cancellata_il'))->toBeNull()
        ->and(array_column($cestino->json('data'), 'nome'))->toBe(['Vecchia'])
        ->and($cestino->json('data.0.stato'))->toBe('cestino')
        ->and($cestino->json('data.0.nel_cestino_il'))->not->toBeNull()
        ->and($cestino->json('data.0.cancellata_il'))->toBe(iso(now()->toImmutable()->startOfMillisecond()->addDays(30)))
        ->and(esito(alFinto('GET', '/v1/board/cartelle?stato=archiviata', null, $gettone)))->toBe([422, 'dati_non_validi']);
});

it('inviti.verifica.crea: un invito vivo dice workspace, ruolo e invitante; uno che non vale è sempre {"valido": false} (#1731)', function () {
    [$finto, , , $gettone] = squadra();
    $finto->persona('dora@example.com', PASSWORD, nome: 'Dora');
    alFinto('POST', '/v1/workspace/inviti', ['email' => 'dora@example.com', 'ruolo' => 'amministratore'], $gettone('anna@example.com'));
    $codice = $finto->ultimoInvito('dora@example.com');

    $vivo = alFinto('POST', '/v1/inviti/verifica', ['codice' => $codice, 'email' => 'dora@example.com']);
    $sconosciuto = alFinto('POST', '/v1/inviti/verifica', ['codice' => str_repeat('a', 43)]);

    expect($vivo->status())->toBe(200)
        ->and($vivo->json('data'))->toBe(['valido' => true, 'workspace_nome' => 'Studio', 'invitante_nome' => 'Anna', 'ruolo' => 'amministratore'])
        ->and($sconosciuto->json())->toBe(['data' => ['valido' => false]])
        ->and(esito(alFinto('POST', '/v1/inviti/verifica', ['codice' => 'corto'])))->toBe([422, 'dati_non_validi'])
        ->and(esito(alFinto('POST', '/v1/inviti/verifica', ['codice' => $codice, 'email' => 'dora@example.com', 'extra' => 1])))->toBe([422, 'dati_non_validi']);
});

it('inviti.verifica.crea: un invito revocato o scaduto non vale; il freno è per codice (#1731)', function () {
    [$finto, , , $gettone] = squadra();
    $this->freezeTime();
    $anna = $gettone('anna@example.com');
    alFinto('POST', '/v1/workspace/inviti', ['email' => 'dora@example.com', 'ruolo' => 'membro'], $anna);
    $revocato = $finto->ultimoInvito('dora@example.com');
    alFinto('DELETE', '/v1/workspace/inviti/'.alFinto('GET', '/v1/workspace/inviti', gettone: $anna)->json('data.0.id'), gettone: $anna);
    alFinto('POST', '/v1/workspace/inviti', ['email' => 'eva@example.com', 'ruolo' => 'membro'], $anna);
    $scaduto = $finto->ultimoInvito('eva@example.com');

    // Il revocato si prova prima di far scadere il tempo: dopo 8 giorni sarebbe comunque scaduto, e il test passerebbe anche se la revoca fosse ignorata.
    expect(alFinto('POST', '/v1/inviti/verifica', ['codice' => $revocato, 'email' => 'dora@example.com'])->json('data'))->toBe(['valido' => false])
        ->and(alFinto('POST', '/v1/inviti/verifica', ['codice' => $scaduto, 'email' => 'eva@example.com'])->json('data.valido'))->toBeTrue();

    $this->travel(8)->days();

    // Scaduto lo sa solo chi porta il codice di 8 cifre e l'email dell'invito (#4166).
    expect(alFinto('POST', '/v1/inviti/verifica', ['codice' => $scaduto, 'email' => 'eva@example.com'])->json('data'))->toBe(['valido' => false, 'motivo' => 'scaduto']);

    foreach (range(1, 30) as $i) {
        alFinto('POST', '/v1/inviti/verifica', ['codice' => $revocato, 'email' => 'dora@example.com']);
    }

    // Il freno è per codice (30 al minuto, `troppe_richieste`); i codici che non valgono hanno anche bloccato l'IP, per ogni email (`troppi_codici_invito`).
    expect(esito(alFinto('POST', '/v1/inviti/verifica', ['codice' => $revocato, 'email' => 'dora@example.com'])))->toBe([429, 'troppe_richieste'])
        ->and(esito(alFinto('POST', '/v1/inviti/verifica', ['codice' => $scaduto, 'email' => 'eva@example.com'])))->toBe([429, 'troppi_codici_invito']);

    $this->travel(16)->minutes();

    expect(alFinto('POST', '/v1/inviti/verifica', ['codice' => $scaduto, 'email' => 'eva@example.com'])->status())->toBe(200);
});

it('inviti.verifica.crea col codice di 8 cifre: vuole l\'email, vale per la sola email dell\'invito, e dopo 5 codici che non valgono il blocco è 429 troppi_codici_invito (#4166)', function () {
    [$finto, , , $gettone] = squadra();
    $this->freezeTime();
    alFinto('POST', '/v1/workspace/inviti', ['email' => 'dora@example.com', 'ruolo' => 'membro'], $gettone('anna@example.com'));
    $codice = $finto->ultimoInvito('dora@example.com');
    $conSpazio = substr($codice, 0, 4).' '.substr($codice, 4);
    $verifica = fn (string $dato, ?string $email) => alFinto('POST', '/v1/inviti/verifica', array_filter(['codice' => $dato, 'email' => $email]));

    expect($codice)->toMatch('/^\d{8}$/')
        ->and($verifica($codice, 'dora@example.com')->json('data.valido'))->toBeTrue()
        ->and($verifica($conSpazio, 'Dora@Example.com')->json('data.valido'))->toBeTrue()
        ->and($verifica($codice, null)->json('errors.0.pointer'))->toBe('#/email')
        ->and($verifica($codice, 'altra@example.com')->json())->toBe(['data' => ['valido' => false]]);

    foreach (range(1, 4) as $n) {
        $verifica(sprintf('%08d', $n), 'dora@example.com');
    }

    $bloccato = $verifica($codice, 'dora@example.com');

    expect(esito($bloccato))->toBe([429, 'troppi_codici_invito'])->and($bloccato->header('Retry-After'))->toBe('900');

    $this->travel(16)->minutes();

    expect($verifica($codice, 'dora@example.com')->json('data.valido'))->toBeTrue();

    $this->travel(8)->days();

    expect($verifica($codice, 'dora@example.com')->json('data'))->toBe(['valido' => false, 'motivo' => 'scaduto'])
        ->and($verifica($codice, 'altra@example.com')->json('data'))->toBe(['valido' => false]);
});

it('accordo: spento il campo è ammesso e ignorato; acceso, una nuova azienda lo vuole a true, con documento e versione accanto (#1700)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);
    $spento = entraNelFinto('anna@example.com')['gettone']['gettone'];

    expect(alFinto('POST', '/v1/io/workspace', ['nome' => 'Uno'], $spento)->status())->toBe(201)
        ->and(alFinto('POST', '/v1/io/workspace', ['nome' => 'Due', 'accordo_trattamento_dati_accettato' => true], $spento)->status())->toBe(201);

    $finto->documentiLegali(DOCUMENTI_DELLO_SPRINT_27, ACCORDO_DI_PROVA);
    $acceso = accessoConIDocumentiAccettati('anna@example.com');

    foreach ([[], ['accordo_trattamento_dati_accettato' => false], ['accordo_trattamento_dati_accettato' => null]] as $corpo) {
        $risposta = alFinto('POST', '/v1/io/workspace', ['nome' => 'Tre'] + $corpo, $acceso);

        expect(esito($risposta))->toBe([422, 'dati_non_validi'])
            ->and($risposta->json('errors.0.pointer'))->toBe('#/accordo_trattamento_dati_accettato')
            ->and($risposta->json('errors.0.regola'))->toBe('obbligatorio')
            ->and($risposta->json('accordo'))->toBe(['documento' => 'accordo-trattamento-dati', 'versione' => 'bozza-0.1']);
    }

    expect(esito(alFinto('POST', '/v1/io/workspace', ['nome' => 'Tre', 'accordo_trattamento_dati_accettato' => 'true'], $acceso)))->toBe([422, 'dati_non_validi'])
        ->and(alFinto('POST', '/v1/io/workspace', ['nome' => 'Tre', 'accordo_trattamento_dati_accettato' => true], $acceso)->status())->toBe(201);
});

it('accordo: un\'azienda che ha accettato non lo richiede più, a io.workspace.crea né ad aziende.modifica (#1700)', function () {
    $finto = BackofficeFinto::attiva()->documentiLegali(DOCUMENTI_DELLO_SPRINT_27, ACCORDO_DI_PROVA);
    $finto->persona('anna@example.com', PASSWORD);
    $accesso = accessoConIDocumentiAccettati('anna@example.com');
    $azienda = alFinto('POST', '/v1/io/workspace', ['nome' => 'Uno', 'accordo_trattamento_dati_accettato' => true], $accesso)->json('data.azienda_id');

    expect(alFinto('POST', '/v1/io/workspace', ['nome' => 'Secondo', 'azienda_id' => $azienda], $accesso)->status())->toBe(201)
        ->and(alFinto('PATCH', "/v1/aziende/{$azienda}", ['nome' => 'Nuovo nome'], $accesso)->json('data.nome'))->toBe('Nuovo nome');
});

it('accordo: aziende.modifica lo vuole se l\'azienda non ha accettato, e il campo da solo basta (#1700)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio', $anna);
    $finto->documentiLegali(DOCUMENTI_DELLO_SPRINT_27, ACCORDO_DI_PROVA);
    $accesso = accessoConIDocumentiAccettati('anna@example.com');
    $percorso = "/v1/aziende/{$studio['azienda_id']}";

    $senza = alFinto('PATCH', $percorso, ['nome' => 'Nuovo'], $accesso);

    expect(esito($senza))->toBe([422, 'dati_non_validi'])
        ->and($senza->json('errors.0.pointer'))->toBe('#/accordo_trattamento_dati_accettato')
        ->and(alFinto('PATCH', $percorso, ['accordo_trattamento_dati_accettato' => true], $accesso)->status())->toBe(200)
        ->and(alFinto('GET', $percorso, gettone: $accesso)->json('data.nome'))->toBe('Studio');

    $vuoto = alFinto('PATCH', $percorso, ['accordo_trattamento_dati_accettato' => true], $accesso);

    expect(esito($vuoto))->toBe([422, 'dati_non_validi'])
        ->and($vuoto->json('errors.0.pointer'))->toBe('#');
});

it('accordo: aziende.mostra dice se l\'azienda deve ancora accettare, e aziende.modifica lo dice false dopo l\'accettazione (#1765)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio', $anna);
    $percorso = "/v1/aziende/{$studio['azienda_id']}";
    $spento = entraNelFinto('anna@example.com')['gettone']['gettone'];

    expect(alFinto('GET', $percorso, gettone: $spento)->json('data.accordo_trattamento_dati_da_accettare'))->toBeFalse();

    $finto->documentiLegali(DOCUMENTI_DELLO_SPRINT_27, ACCORDO_DI_PROVA);
    $acceso = accessoConIDocumentiAccettati('anna@example.com');

    expect(alFinto('GET', $percorso, gettone: $acceso)->json('data.accordo_trattamento_dati_da_accettare'))->toBeTrue();

    $accettato = alFinto('PATCH', $percorso, ['accordo_trattamento_dati_accettato' => true], $acceso);

    expect($accettato->status())->toBe(200)
        ->and($accettato->json('data.accordo_trattamento_dati_da_accettare'))->toBeFalse()
        ->and(alFinto('GET', $percorso, gettone: $acceso)->json('data.accordo_trattamento_dati_da_accettare'))->toBeFalse();
});

it('io.inviti.verifica.crea: chi è già membro ha gia_membro e il workspace, chi no ha quello di inviti.verifica.crea, ogni altro caso è {"valido": false} (#2177)', function () {
    [$finto, $studio, , $gettone] = squadra();
    $dora = $finto->persona('dora@example.com', PASSWORD, nome: 'Dora');
    $finto->persona('elio@example.com', PASSWORD, nome: 'Elio');
    alFinto('POST', '/v1/workspace/inviti', ['email' => 'dora@example.com', 'ruolo' => 'membro'], $gettone('anna@example.com'));
    alFinto('POST', '/v1/workspace/inviti', ['email' => 'elio@example.com', 'ruolo' => 'membro'], $gettone('anna@example.com'));
    $perDora = $finto->ultimoInvito('dora@example.com');
    $perElio = $finto->ultimoInvito('elio@example.com');
    // Dora entra nel workspace per un'altra via mentre il suo invito vive.
    $finto->membro($studio, $dora, 'membro');
    $carla = entraNelFinto('carla@example.com')['gettone']['gettone'];
    $elio = entraNelFinto('elio@example.com')['gettone']['gettone'];

    $membro = alFinto('POST', '/v1/io/inviti/verifica', ['codice' => $perDora], entraNelFinto('dora@example.com')['gettone']['gettone']);
    $fuori = alFinto('POST', '/v1/io/inviti/verifica', ['codice' => $perElio], $elio);
    $conWorkspace = alFinto('POST', '/v1/io/inviti/verifica', ['codice' => $perDora], $gettone('dora@example.com'));
    $anonimo = alFinto('POST', '/v1/inviti/verifica', ['codice' => $perElio, 'email' => 'elio@example.com']);

    // Un codice di 8 cifre vale solo per l'email dell'invito (#4166): per chiunque altro, membro o no, è un codice che non c'è.
    expect($membro->status())->toBe(200)
        ->and($membro->json('data'))->toBe(['valido' => false, 'gia_membro' => true, 'workspace' => ['id' => $studio['id'], 'slug' => $studio['slug'], 'nome' => 'Studio']])
        ->and($conWorkspace->json('data'))->toBe($membro->json('data'))
        ->and($fuori->json('data'))->toBe($anonimo->json('data'))->and($fuori->json('data.valido'))->toBeTrue()
        ->and(alFinto('POST', '/v1/io/inviti/verifica', ['codice' => $perDora], $elio)->json())->toBe(['data' => ['valido' => false]])
        ->and(alFinto('POST', '/v1/io/inviti/verifica', ['codice' => $perDora], $carla)->json())->toBe(['data' => ['valido' => false]])
        ->and(alFinto('POST', '/v1/io/inviti/verifica', ['codice' => str_repeat('a', 43)], $carla)->json())->toBe(['data' => ['valido' => false]]);
});

it('io.inviti.verifica.crea: l\'invito accettato è gia_membro per chi lo ha accettato, e niente per un altro; il revocato no (#2177, #4166)', function () {
    [$finto, $studio, , $gettone] = squadra();
    $finto->persona('dora@example.com', PASSWORD, nome: 'Dora');
    $finto->persona('elio@example.com', PASSWORD, nome: 'Elio');
    $anna = $gettone('anna@example.com');
    $carla = entraNelFinto('carla@example.com')['gettone']['gettone'];

    alFinto('POST', '/v1/workspace/inviti', ['email' => 'dora@example.com', 'ruolo' => 'membro'], $anna);
    $accettato = $finto->ultimoInvito('dora@example.com');
    $dora = entraNelFinto('dora@example.com')['gettone']['gettone'];
    alFinto('POST', '/v1/inviti/accettazione', ['codice' => $accettato], $dora);

    alFinto('POST', '/v1/workspace/inviti', ['email' => 'elio@example.com', 'ruolo' => 'membro'], $anna);
    $revocato = $finto->ultimoInvito('elio@example.com');
    alFinto('DELETE', '/v1/workspace/inviti/'.alFinto('GET', '/v1/workspace/inviti', gettone: $anna)->json('data.0.id'), gettone: $anna);

    expect(alFinto('POST', '/v1/io/inviti/verifica', ['codice' => $accettato], $dora)->json('data'))->toBe(['valido' => false, 'gia_membro' => true, 'workspace' => ['id' => $studio['id'], 'slug' => $studio['slug'], 'nome' => 'Studio']])
        ->and(alFinto('POST', '/v1/io/inviti/verifica', ['codice' => $accettato], $carla)->json())->toBe(['data' => ['valido' => false]])
        ->and(alFinto('POST', '/v1/io/inviti/verifica', ['codice' => $revocato], entraNelFinto('elio@example.com')['gettone']['gettone'])->json())->toBe(['data' => ['valido' => false]]);
});

it('io.inviti.verifica.crea: senza gettone 401, un codice fuori forma 422 sul pointer, il freno è per persona e il blocco dei codici sbagliati per IP (#2177, #4166)', function () {
    [, , , $gettone] = squadra();
    $carla = entraNelFinto('carla@example.com')['gettone']['gettone'];

    expect(esito(alFinto('POST', '/v1/io/inviti/verifica', ['codice' => str_repeat('a', 43)])))->toBe([401, 'gettone_assente'])
        ->and(esito(alFinto('POST', '/v1/io/inviti/verifica', ['codice' => 'corto'], $carla)))->toBe([422, 'dati_non_validi'])
        ->and(esito(alFinto('POST', '/v1/io/inviti/verifica', ['codice' => str_repeat('a', 43), 'extra' => 1], $carla)))->toBe([422, 'dati_non_validi']);

    foreach (range(1, 30) as $n) {
        alFinto('POST', '/v1/io/inviti/verifica', ['codice' => substr(hash('sha256', (string) $n), 0, 43)], $carla);
    }

    // Il freno è della persona (`troppe_richieste`); i trenta codici che non valgono hanno anche bloccato l'IP, e con lui ogni altra persona per 15 minuti (`troppi_codici_invito`).
    expect(esito(alFinto('POST', '/v1/io/inviti/verifica', ['codice' => str_repeat('b', 43)], $carla)))->toBe([429, 'troppe_richieste'])
        ->and(esito(alFinto('POST', '/v1/io/inviti/verifica', ['codice' => str_repeat('b', 43)], $gettone('anna@example.com'))))->toBe([429, 'troppi_codici_invito']);

    $this->travel(16)->minutes();

    expect(alFinto('POST', '/v1/io/inviti/verifica', ['codice' => str_repeat('b', 43)], $gettone('anna@example.com'))->status())->toBe(200);
});
