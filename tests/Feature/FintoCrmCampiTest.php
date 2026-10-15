<?php

use Illuminate\Http\Client\Response;

// #2024 (G67): le sezioni e i campi del CRM nel finto. Qui ciò che un frontend vede dal suo lato; che le risposte e gli errori
// siano quelli del backoffice lo prova il suo FintoTest (confronta finto e backoffice sulle stesse chiamate).

/** Le sezioni di un oggetto, nell'ordine dell'elenco: [id => nome.it]. */
function sezioniDelFinto(string $gettone, string $oggetto): array
{
    $voci = alFinto('GET', "/v1/crm/sezioni?oggetto={$oggetto}", null, $gettone)->json('data');

    return array_combine(array_column($voci, 'id'), array_map(fn (array $voce) => $voce['nome']['it'], $voci));
}

/** Una modifica che deve riuscire: dà la risposta. */
function riuscita(Response $risposta): Response
{
    expect($risposta->status())->toBeLessThan(300, $risposta->body());

    return $risposta;
}

/** I campi di una sezione, nell'ordine dell'elenco: le chiavi. */
function campiDellaSezione(string $gettone, string $oggetto, string $sezione): array
{
    $campi = alFinto('GET', "/v1/crm/campi?oggetto={$oggetto}", null, $gettone)->json('data');

    return array_values(array_column(array_filter($campi, fn (array $campo) => $campo['sezione_id'] === $sezione), 'chiave'));
}

it('crm.campi.elenca e crm.sezioni.elenca danno il catalogo degli standard: persona 24 campi in 6 sezioni, organizzazione 16 in 4, trattativa 11 in 2 (FC.2)', function () {
    [, $gettoni] = studioColCrm();

    foreach ([['persona', 24, 6], ['organizzazione', 16, 4], ['trattativa', 11, 2]] as [$oggetto, $campi, $sezioni]) {
        $elencoCampi = alFinto('GET', "/v1/crm/campi?oggetto={$oggetto}", null, $gettoni['carla']);
        $elencoSezioni = alFinto('GET', "/v1/crm/sezioni?oggetto={$oggetto}", null, $gettoni['carla']);

        expect($elencoCampi->status())->toBe(200)
            ->and($elencoCampi->json('data'))->toHaveCount($campi)
            ->and($elencoCampi->json('successivo'))->toBeNull()
            ->and($elencoSezioni->json('data'))->toHaveCount($sezioni)
            ->and($elencoSezioni->json('data.0'))->toMatchArray(['id' => "{$oggetto}.principali", 'oggetto' => $oggetto, 'nome' => ['it' => 'Principali', 'en' => 'Main', 'es' => 'Principales'], 'posizione' => 1, 'fissa' => true, 'creato_il' => '2026-10-13T00:00:00.000Z', 'aggiornato_il' => '2026-10-13T00:00:00.000Z'])
            ->and(array_column($elencoSezioni->json('data'), 'posizione'))->toBe(range(1, $sezioni))
            ->and(array_unique(array_column($elencoCampi->json('data'), 'origine')))->toBe(['standard'])
            ->and(array_unique(array_column($elencoCampi->json('data'), 'obbligatorio')))->toBe([false]);
    }

    $persona = collect(alFinto('GET', '/v1/crm/campi?oggetto=persona', null, $gettoni['anna'])->json('data'))->keyBy('chiave');

    $nome = $persona['nome'];
    unset($nome['chiave']);

    expect($persona['nome']['chiave'])->toBe('nome')
        ->and($nome)->toBe([
            'id' => 'persona.nome', 'oggetto' => 'persona', 'origine' => 'standard', 'tipo' => 'testo', 'opzioni' => null,
            'nome' => ['it' => 'Nome', 'en' => 'First name', 'es' => 'Nombre'], 'visibilita' => 'visibile', 'sezione_id' => 'persona.principali',
            'posizione' => 1, 'obbligatorio' => false, 'indispensabile' => true, 'in_campi' => false,
            'creato_il' => '2026-10-13T00:00:00.000Z', 'aggiornato_il' => '2026-10-13T00:00:00.000Z', 'archiviato_il' => null, 'ripristinabile_fino' => null,
        ])
        ->and($persona['organizzazione_id']['sistema'])->toBe('organizzazione')
        ->and($persona['ruolo'])->not->toHaveKey('sistema')
        ->and(array_intersect_key($persona['reparto'], array_flip(['visibilita', 'sezione_id', 'posizione', 'in_campi'])))->toBe(['visibilita' => 'nascosto', 'sezione_id' => 'persona.lavoro', 'posizione' => 2, 'in_campi' => true])
        ->and($persona->where('indispensabile', true)->keys()->all())->toBe(['nome', 'cognome', 'email']);
});

it('gli elenchi vanno a pagine con un cursore legato all\'oggetto, e un parametro che non va è 422 sul suo nome (FC.1)', function () {
    [, $gettoni] = studioColCrm();
    $prima = alFinto('GET', '/v1/crm/campi?oggetto=persona&limite=10', null, $gettoni['anna']);
    $seconda = alFinto('GET', '/v1/crm/campi?oggetto=persona&limite=10&cursore='.urlencode($prima->json('successivo')), null, $gettoni['anna']);
    $terza = alFinto('GET', '/v1/crm/campi?oggetto=persona&limite=10&cursore='.urlencode($seconda->json('successivo')), null, $gettoni['anna']);
    $tutti = alFinto('GET', '/v1/crm/campi?oggetto=persona', null, $gettoni['anna'])->json('data');

    expect($prima->json('data'))->toHaveCount(10)
        ->and($seconda->json('data'))->toHaveCount(10)
        ->and($terza->json('data'))->toHaveCount(4)
        ->and($terza->json('successivo'))->toBeNull()
        ->and([...$prima->json('data'), ...$seconda->json('data'), ...$terza->json('data')])->toBe($tutti);

    $sezioni = alFinto('GET', '/v1/crm/sezioni?oggetto=persona&limite=4', null, $gettoni['anna']);
    $resto = alFinto('GET', '/v1/crm/sezioni?oggetto=persona&limite=4&cursore='.urlencode($sezioni->json('successivo')), null, $gettoni['anna']);

    expect(array_column($sezioni->json('data'), 'id'))->toBe(['persona.principali', 'persona.lavoro', 'persona.dati_personali', 'persona.recapiti'])
        ->and(array_column($resto->json('data'), 'id'))->toBe(['persona.consensi', 'persona.altro'])
        ->and($resto->json('successivo'))->toBeNull();

    foreach ([
        'senza oggetto' => ['/v1/crm/campi', 'oggetto', 'obbligatorio'],
        'oggetto che non c\'è' => ['/v1/crm/sezioni?oggetto=progetto', 'oggetto', 'valore_non_ammesso'],
        'limite a zero' => ['/v1/crm/campi?oggetto=persona&limite=0', 'limite', 'fuori_intervallo'],
        'limite oltre 300' => ['/v1/crm/sezioni?oggetto=persona&limite=301', 'limite', 'fuori_intervallo'],
        'cursore inventato' => ['/v1/crm/campi?oggetto=persona&cursore=abc', 'cursore', 'formato'],
        'cursore di un altro oggetto' => ['/v1/crm/campi?oggetto=trattativa&cursore='.urlencode($prima->json('successivo')), 'cursore', 'formato'],
        'cursore di un\'altra lista' => ['/v1/crm/sezioni?oggetto=persona&cursore='.urlencode($prima->json('successivo')), 'cursore', 'formato'],
    ] as [$percorso, $parametro, $regola]) {
        $risposta = alFinto('GET', $percorso, null, $gettoni['anna']);

        expect(esito($risposta))->toBe([422, 'dati_non_validi'])
            ->and($risposta->json('errors.0.parameter'))->toBe($parametro)
            ->and($risposta->json('errors.0.regola'))->toBe($regola);
    }
});

it('crm.sezioni.crea: in fondo all\'elenco, con Location e il nome nelle lingue date; il nome `it` è unico senza maiuscole né accenti (SZ.2)', function () {
    $this->freezeTime();
    [, $gettoni] = studioColCrm();

    $nuova = alFinto('POST', '/v1/crm/sezioni', ['oggetto' => 'persona', 'nome' => ['it' => 'Famiglia', 'en' => 'Family', 'es' => '']], $gettoni['bruno']);
    $id = $nuova->json('data.id');

    expect($nuova->status())->toBe(201)
        ->and($nuova->header('Location'))->toBe("/v1/crm/sezioni/{$id}")
        ->and($nuova->json('data'))->toBe([
            'id' => $id, 'oggetto' => 'persona', 'nome' => ['it' => 'Famiglia', 'en' => 'Family', 'es' => null], 'posizione' => 7, 'fissa' => false,
            'creato_il' => iso(now()->toImmutable()->startOfMillisecond()), 'aggiornato_il' => iso(now()->toImmutable()->startOfMillisecond()),
        ])
        ->and(array_keys(sezioniDelFinto($gettoni['anna'], 'persona')))->toBe(['persona.principali', 'persona.lavoro', 'persona.dati_personali', 'persona.recapiti', 'persona.consensi', 'persona.altro', $id])
        ->and(sezioniDelFinto($gettoni['anna'], 'organizzazione'))->not->toHaveKey($id);

    foreach (['famiglia', ' FAMÍGLIA ', 'lavoro', 'RECAPITI'] as $doppione) {
        $risposta = alFinto('POST', '/v1/crm/sezioni', ['oggetto' => 'persona', 'nome' => ['it' => $doppione]], $gettoni['anna']);

        expect(esito($risposta))->toBe([422, 'dati_non_validi'])
            ->and($risposta->json('errors.0.pointer'))->toBe('#/nome/it')
            ->and($risposta->json('errors.0.regola'))->toBe('duplicato');
    }

    // Lo stesso nome in un altro oggetto è un'altra sezione.
    expect(alFinto('POST', '/v1/crm/sezioni', ['oggetto' => 'trattativa', 'nome' => ['it' => 'Famiglia']], $gettoni['anna'])->status())->toBe(201);

    // I corpi che non vanno: sul pointer del campo, senza scrivere niente.
    foreach ([
        [['oggetto' => 'persona'], '#/nome', 'obbligatorio'],
        [['oggetto' => 'persona', 'nome' => ['en' => 'Only English']], '#/nome/it', 'obbligatorio'],
        [['oggetto' => 'persona', 'nome' => ['it' => '']], '#/nome/it', 'obbligatorio'],
        [['oggetto' => 'persona', 'nome' => ['it' => str_repeat('a', 61)]], '#/nome/it', 'troppo_lungo'],
        [['oggetto' => 'persona', 'nome' => ['it' => "A\u{200B}B"]], '#/nome/it', 'carattere_non_ammesso'],
        [['oggetto' => 'cliente', 'nome' => ['it' => 'Altro']], '#/oggetto', 'valore_non_ammesso'],
        [['nome' => ['it' => 'Senza oggetto']], '#/oggetto', 'obbligatorio'],
    ] as [$corpo, $pointer, $regola]) {
        $risposta = alFinto('POST', '/v1/crm/sezioni', $corpo, $gettoni['anna']);

        expect(esito($risposta))->toBe([422, 'dati_non_validi'])
            ->and($risposta->json('errors.0.pointer'))->toBe($pointer)
            ->and($risposta->json('errors.0.regola'))->toBe($regola);
    }

    expect(sezioniDelFinto($gettoni['anna'], 'persona'))->toHaveCount(7);
});

it('crm.sezioni.crea: al cinquantunesimo posto è 409 limite_raggiunto, e con Idempotency-Key la stessa chiamata dà la stessa sezione (SZ.2)', function () {
    [, $gettoni] = studioColCrm();

    $ripetuta = 'prima-chiamata';
    $una = alFinto('POST', '/v1/crm/sezioni', ['oggetto' => 'trattativa', 'nome' => ['it' => 'Prima']], $gettoni['anna'], null, ['Idempotency-Key' => $ripetuta]);
    $ancora = alFinto('POST', '/v1/crm/sezioni', ['oggetto' => 'trattativa', 'nome' => ['it' => 'Prima']], $gettoni['anna'], null, ['Idempotency-Key' => $ripetuta]);

    expect($una->status())->toBe(201)
        ->and($ancora->json('data.id'))->toBe($una->json('data.id'))
        ->and(sezioniDelFinto($gettoni['anna'], 'trattativa'))->toHaveCount(3);

    foreach (range(1, 47) as $n) {
        expect(alFinto('POST', '/v1/crm/sezioni', ['oggetto' => 'trattativa', 'nome' => ['it' => "Sezione {$n}"]], $gettoni['anna'])->status())->toBe(201);
    }

    $oltre = alFinto('POST', '/v1/crm/sezioni', ['oggetto' => 'trattativa', 'nome' => ['it' => 'Una di troppo']], $gettoni['anna']);

    expect(esito($oltre))->toBe([409, 'limite_raggiunto'])
        ->and($oltre->json('limite'))->toBe(50)
        ->and(sezioniDelFinto($gettoni['anna'], 'trattativa'))->toHaveCount(50);
});

it('crm.sezioni.modifica: il nome (null torna al catalogo, o toglie la lingua di una creata) e il posto da 2; «Principali» non cambia (SZ.3)', function () {
    $this->freezeTime();
    [, $gettoni] = studioColCrm();
    $creata = alFinto('POST', '/v1/crm/sezioni', ['oggetto' => 'persona', 'nome' => ['it' => 'Famiglia', 'en' => 'Family']], $gettoni['anna'])->json('data.id');
    $this->travel(5)->seconds();

    // Una seminata: il nome in una lingua cambia, le altre restano quelle del catalogo.
    $rinominata = alFinto('PATCH', '/v1/crm/sezioni/persona.lavoro', ['nome' => ['it' => 'Professione']], $gettoni['bruno']);

    expect($rinominata->status())->toBe(200)
        ->and($rinominata->json('data.nome'))->toBe(['it' => 'Professione', 'en' => 'Work', 'es' => 'Trabajo'])
        ->and($rinominata->json('data.posizione'))->toBe(2)
        ->and($rinominata->json('data.creato_il'))->toBe('2026-10-13T00:00:00.000Z')
        ->and($rinominata->json('data.aggiornato_il'))->toBe(iso(now()->toImmutable()->startOfMillisecond()));

    // null torna al catalogo; la stessa modifica due volte non scrive, e `aggiornato_il` resta.
    $this->travel(5)->seconds();
    $torna = alFinto('PATCH', '/v1/crm/sezioni/persona.lavoro', ['nome' => ['it' => null]], $gettoni['anna']);
    $this->travel(5)->seconds();
    $uguale = alFinto('PATCH', '/v1/crm/sezioni/persona.lavoro', ['nome' => ['it' => null]], $gettoni['anna']);

    expect($torna->json('data.nome.it'))->toBe('Lavoro')
        ->and($uguale->json('data'))->toBe($torna->json('data'));

    // Il nome del catalogo scritto per esteso non cambia niente, come nel backoffice.
    $this->travel(5)->seconds();
    $catalogo = alFinto('PATCH', '/v1/crm/sezioni/persona.altro', ['nome' => ['it' => 'Altro', 'en' => 'Other']], $gettoni['anna']);

    expect($catalogo->status())->toBe(200)->and($catalogo->json('data.aggiornato_il'))->toBe('2026-10-13T00:00:00.000Z');

    // Una creata: l'inglese si toglie con null, l'italiano no.
    $senzaInglese = alFinto('PATCH', "/v1/crm/sezioni/{$creata}", ['nome' => ['en' => null, 'es' => 'Familia']], $gettoni['anna']);
    $senzaItaliano = alFinto('PATCH', "/v1/crm/sezioni/{$creata}", ['nome' => ['it' => null]], $gettoni['anna']);

    expect($senzaInglese->json('data.nome'))->toBe(['it' => 'Famiglia', 'en' => null, 'es' => 'Familia'])
        ->and(esito($senzaItaliano))->toBe([422, 'dati_non_validi'])
        ->and($senzaItaliano->json('errors.0.pointer'))->toBe('#/nome/it')
        ->and($senzaItaliano->json('errors.0.regola'))->toBe('obbligatorio');

    // Il posto: da 2, oltre la fine è in fondo, e le altre scalano.
    $secondo = alFinto('PATCH', "/v1/crm/sezioni/{$creata}", ['posizione' => 2], $gettoni['anna']);

    expect($secondo->json('data.posizione'))->toBe(2)
        ->and(array_keys(sezioniDelFinto($gettoni['anna'], 'persona')))->toBe(['persona.principali', $creata, 'persona.lavoro', 'persona.dati_personali', 'persona.recapiti', 'persona.consensi', 'persona.altro']);

    $principali = alFinto('PATCH', '/v1/crm/sezioni/persona.principali', ['posizione' => 3], $gettoni['anna']);
    $inFondo = alFinto('PATCH', "/v1/crm/sezioni/{$creata}", ['posizione' => 99], $gettoni['anna']);

    expect(esito($principali))->toBe([409, 'sezione_di_sistema'])
        ->and($inFondo->json('data.posizione'))->toBe(7)
        ->and(array_key_last(sezioniDelFinto($gettoni['anna'], 'persona')))->toBe($creata);

    $primo = alFinto('PATCH', "/v1/crm/sezioni/{$creata}", ['posizione' => 1], $gettoni['anna']);

    expect(esito($primo))->toBe([422, 'dati_non_validi'])
        ->and($primo->json('errors.0.pointer'))->toBe('#/posizione')
        ->and($primo->json('errors.0.regola'))->toBe('fuori_intervallo');

    // Il nome di un'altra sezione, senza maiuscole né accenti, è 422; il proprio, no.
    $doppione = alFinto('PATCH', "/v1/crm/sezioni/{$creata}", ['nome' => ['it' => 'RECAPITI']], $gettoni['anna']);

    expect(esito($doppione))->toBe([422, 'dati_non_validi'])
        ->and($doppione->json('errors.0.pointer'))->toBe('#/nome/it')
        ->and($doppione->json('errors.0.regola'))->toBe('duplicato')
        ->and(alFinto('PATCH', "/v1/crm/sezioni/{$creata}", ['nome' => ['it' => 'FAMIGLIA']], $gettoni['anna'])->json('data.nome.it'))->toBe('FAMIGLIA');
});

it('crm.sezioni.modifica ed elimina: il corpo viene prima della sezione, poi il 404, il 403 a un membro, e la sezione di un altro oggetto non si confonde (SZ.3, SZ.4)', function () {
    [, $gettoni] = studioColCrm();

    foreach ([
        [['nome' => []], '#/nome', 'obbligatorio'],
        [[], '#', 'obbligatorio'],
        [['posizione' => '2'], '#/posizione', 'tipo'],
    ] as [$corpo, $pointer, $regola]) {
        $risposta = alFinto('PATCH', '/v1/crm/sezioni/persona.inesistente', $corpo, $gettoni['anna']);

        expect(esito($risposta))->toBe([422, 'dati_non_validi'])
            ->and($risposta->json('errors.0.pointer'))->toBe($pointer)
            ->and($risposta->json('errors.0.regola'))->toBe($regola);
    }

    foreach (['persona.inesistente', 'Persona.Lavoro', '01jzzzzzzzzzzzzzzzzzzzzzzz', 'x'] as $id) {
        expect(esito(alFinto('PATCH', "/v1/crm/sezioni/{$id}", ['posizione' => 2], $gettoni['anna'])))->toBe([404, 'non_trovato'])
            ->and(esito(alFinto('DELETE', "/v1/crm/sezioni/{$id}", null, $gettoni['anna'])))->toBe([404, 'non_trovato']);
    }

    // Prima il ruolo: un membro è 403 anche per una sezione che non c'è e per un corpo che non va.
    expect(esito(alFinto('PATCH', '/v1/crm/sezioni/persona.lavoro', ['posizione' => 2], $gettoni['carla'])))->toBe([403, 'permesso_negato'])
        ->and(esito(alFinto('PATCH', '/v1/crm/sezioni/nessuna', [], $gettoni['carla'])))->toBe([403, 'permesso_negato'])
        ->and(esito(alFinto('POST', '/v1/crm/sezioni', [], $gettoni['carla'])))->toBe([403, 'permesso_negato'])
        ->and(esito(alFinto('DELETE', '/v1/crm/sezioni/persona.lavoro', null, $gettoni['carla'])))->toBe([403, 'permesso_negato'])
        ->and(esito(alFinto('PATCH', '/v1/crm/campi/persona.ruolo', ['visibilita' => 'nascosto'], $gettoni['carla'])))->toBe([403, 'permesso_negato']);

    // Senza gettone.
    expect(esito(alFinto('GET', '/v1/crm/campi?oggetto=persona')))->toBe([401, 'gettone_assente']);
});

it('crm.sezioni.elimina: la sezione sparisce (404 da lì) e il nome torna libero; «Principali» e una sezione con campi sono 409 (SZ.4)', function () {
    [, $gettoni] = studioColCrm();
    $creata = alFinto('POST', '/v1/crm/sezioni', ['oggetto' => 'persona', 'nome' => ['it' => 'Famiglia']], $gettoni['anna'])->json('data.id');

    expect(esito(alFinto('DELETE', '/v1/crm/sezioni/persona.principali', null, $gettoni['anna'])))->toBe([409, 'sezione_di_sistema'])
        ->and(esito(alFinto('DELETE', '/v1/crm/sezioni/organizzazione.principali', null, $gettoni['anna'])))->toBe([409, 'sezione_di_sistema'])
        ->and(esito(alFinto('DELETE', '/v1/crm/sezioni/persona.lavoro', null, $gettoni['anna'])))->toBe([409, 'sezione_non_vuota']);

    // Una creata con un campo dentro non si elimina; senza, sì.
    riuscita(alFinto('PATCH', '/v1/crm/campi/persona.ruolo', ['sezione_id' => $creata], $gettoni['anna']));

    expect(esito(alFinto('DELETE', "/v1/crm/sezioni/{$creata}", null, $gettoni['anna'])))->toBe([409, 'sezione_non_vuota']);

    riuscita(alFinto('PATCH', '/v1/crm/campi/persona.ruolo', ['sezione_id' => 'persona.lavoro', 'posizione' => 1], $gettoni['anna']));
    $eliminata = alFinto('DELETE', "/v1/crm/sezioni/{$creata}", null, $gettoni['anna']);

    expect($eliminata->status())->toBe(204)
        ->and($eliminata->body())->toBe('')
        ->and(esito(alFinto('PATCH', "/v1/crm/sezioni/{$creata}", ['posizione' => 2], $gettoni['anna'])))->toBe([404, 'non_trovato'])
        ->and(esito(alFinto('DELETE', "/v1/crm/sezioni/{$creata}", null, $gettoni['anna'])))->toBe([404, 'non_trovato'])
        ->and(sezioniDelFinto($gettoni['anna'], 'persona'))->toHaveCount(6)
        ->and(alFinto('POST', '/v1/crm/sezioni', ['oggetto' => 'persona', 'nome' => ['it' => 'Famiglia']], $gettoni['anna'])->status())->toBe(201);

    // Una seminata vuota si elimina come una creata: «Altro» dei contatti dopo aver spostato i suoi tre campi.
    foreach (['fonte', 'codice_cliente', 'data_primo_contatto'] as $chiave) {
        riuscita(alFinto('PATCH', "/v1/crm/campi/persona.{$chiave}", ['sezione_id' => 'persona.lavoro'], $gettoni['anna']));
    }

    expect(alFinto('DELETE', '/v1/crm/sezioni/persona.altro', null, $gettoni['anna'])->status())->toBe(204)
        ->and(sezioniDelFinto($gettoni['anna'], 'persona'))->not->toHaveKey('persona.altro')
        ->and(esito(alFinto('PATCH', '/v1/crm/sezioni/persona.altro', ['posizione' => 2], $gettoni['anna'])))->toBe([404, 'non_trovato'])
        ->and(sezioniDelFinto($gettoni['anna'], 'organizzazione'))->toHaveKey('organizzazione.altro');
});

it('crm.campi.modifica: il nome per lingua (null torna al catalogo) e la visibilità; una modifica che non cambia niente non porta avanti aggiornato_il (CP.2)', function () {
    $this->freezeTime();
    [, $gettoni] = studioColCrm();
    $this->travel(5)->seconds();
    $adesso = iso(now()->toImmutable()->startOfMillisecond());

    $nome = alFinto('PATCH', '/v1/crm/campi/persona.ruolo', ['nome' => ['it' => 'Mansione', 'en' => 'Job']], $gettoni['bruno']);

    expect($nome->status())->toBe(200)
        ->and($nome->json('data.nome'))->toBe(['it' => 'Mansione', 'en' => 'Job', 'es' => 'Cargo'])
        ->and($nome->json('data.aggiornato_il'))->toBe($adesso)
        ->and($nome->json('data.creato_il'))->toBe('2026-10-13T00:00:00.000Z');

    // Le stesse cose di adesso (il nome inglese, la visibilità e la sezione che ha) non sono una modifica.
    $this->travel(5)->seconds();
    $stessi = alFinto('PATCH', '/v1/crm/campi/persona.ruolo', ['nome' => ['en' => 'Job'], 'visibilita' => 'visibile', 'sezione_id' => 'persona.lavoro'], $gettoni['anna']);

    expect($stessi->json('data'))->toBe($nome->json('data'));

    $torna = alFinto('PATCH', '/v1/crm/campi/persona.ruolo', ['nome' => ['it' => null, 'en' => 'Role']], $gettoni['anna']);

    expect($torna->json('data.nome'))->toBe(['it' => 'Ruolo', 'en' => 'Role', 'es' => 'Cargo']);

    $this->travel(5)->seconds();
    $visibile = alFinto('PATCH', '/v1/crm/campi/persona.sito', ['visibilita' => 'visibile'], $gettoni['anna']);
    $this->travel(5)->seconds();
    $ancora = alFinto('PATCH', '/v1/crm/campi/persona.sito', ['visibilita' => 'visibile'], $gettoni['anna']);

    expect($visibile->json('data.visibilita'))->toBe('visibile')
        ->and($ancora->json('data'))->toBe($visibile->json('data'))
        ->and($ancora->json('data.aggiornato_il'))->not->toBe('2026-10-13T00:00:00.000Z');
});

it('crm.campi.modifica: la sezione e il posto; senza posizione un campo che cambia sezione va in fondo, e gli altri scalano senza aggiornato_il (CP.2)', function () {
    $this->freezeTime();
    [, $gettoni] = studioColCrm();

    expect(campiDellaSezione($gettoni['anna'], 'persona', 'persona.lavoro'))->toBe(['ruolo', 'reparto']);

    $this->travel(5)->seconds();
    $inFondo = alFinto('PATCH', '/v1/crm/campi/persona.titolo', ['sezione_id' => 'persona.lavoro'], $gettoni['anna']);

    expect($inFondo->json('data.sezione_id'))->toBe('persona.lavoro')
        ->and($inFondo->json('data.posizione'))->toBe(3)
        ->and(campiDellaSezione($gettoni['anna'], 'persona', 'persona.lavoro'))->toBe(['ruolo', 'reparto', 'titolo'])
        ->and(campiDellaSezione($gettoni['anna'], 'persona', 'persona.dati_personali'))->toBe(['secondo_nome', 'genere', 'data_nascita', 'luogo_nascita', 'cittadinanza']);

    $primo = alFinto('PATCH', '/v1/crm/campi/persona.titolo', ['posizione' => 1], $gettoni['anna']);
    $reparto = collect(alFinto('GET', '/v1/crm/campi?oggetto=persona', null, $gettoni['anna'])->json('data'))->keyBy('chiave')['reparto'];

    expect($primo->json('data.posizione'))->toBe(1)
        ->and(campiDellaSezione($gettoni['anna'], 'persona', 'persona.lavoro'))->toBe(['titolo', 'ruolo', 'reparto'])
        ->and($reparto['posizione'])->toBe(3)
        ->and($reparto['aggiornato_il'])->toBe('2026-10-13T00:00:00.000Z');

    // Oltre la fine è in fondo, e lo stesso posto non cambia niente.
    $oltre = alFinto('PATCH', '/v1/crm/campi/persona.titolo', ['posizione' => 50], $gettoni['anna']);
    $stesso = alFinto('PATCH', '/v1/crm/campi/persona.titolo', ['posizione' => 50], $gettoni['anna']);

    expect($oltre->json('data.posizione'))->toBe(3)
        ->and(campiDellaSezione($gettoni['anna'], 'persona', 'persona.lavoro'))->toBe(['ruolo', 'reparto', 'titolo'])
        ->and($stesso->json('data'))->toBe($oltre->json('data'));

    // L'elenco dei campi segue l'ordine delle sezioni.
    riuscita(alFinto('PATCH', '/v1/crm/sezioni/persona.altro', ['posizione' => 2], $gettoni['anna']));
    $campi = alFinto('GET', '/v1/crm/campi?oggetto=persona', null, $gettoni['anna'])->json('data');

    expect(array_values(array_unique(array_column($campi, 'sezione_id'))))->toBe(['persona.principali', 'persona.altro', 'persona.lavoro', 'persona.dati_personali', 'persona.recapiti', 'persona.consensi']);
});

it('crm.campi.modifica: un campo indispensabile non si nasconde e non lascia «Principali»; la sezione che non c\'è o è di un altro oggetto è 422 non_trovato (CP.3)', function () {
    [, $gettoni] = studioColCrm();

    foreach ([
        [['visibilita' => 'nascosto'], '#/visibilita', 'campo_indispensabile'],
        [['sezione_id' => 'persona.lavoro'], '#/sezione_id', 'campo_indispensabile'],
    ] as [$corpo, $pointer, $regola]) {
        $risposta = alFinto('PATCH', '/v1/crm/campi/persona.email', $corpo, $gettoni['anna']);

        expect(esito($risposta))->toBe([422, 'dati_non_validi'])
            ->and($risposta->json('errors.0.pointer'))->toBe($pointer)
            ->and($risposta->json('errors.0.regola'))->toBe($regola);
    }

    // Il posto in «Principali» si cambia, e visibile resta visibile.
    $posto = alFinto('PATCH', '/v1/crm/campi/persona.email', ['posizione' => 1, 'visibilita' => 'visibile'], $gettoni['anna']);

    expect($posto->status())->toBe(200)
        ->and($posto->json('data.posizione'))->toBe(1)
        ->and(campiDellaSezione($gettoni['anna'], 'persona', 'persona.principali'))->toBe(['email', 'nome', 'cognome', 'telefoni', 'organizzazione_id', 'responsabile_id', 'stato']);

    foreach (['persona.inesistente', 'organizzazione.lavoro', 'nessuna', ''] as $sezione) {
        $risposta = alFinto('PATCH', '/v1/crm/campi/persona.ruolo', ['sezione_id' => $sezione === '' ? null : $sezione], $gettoni['anna']);

        expect(esito($risposta))->toBe([422, 'dati_non_validi'])
            ->and($risposta->json('errors.0.pointer'))->toBe('#/sezione_id');
    }

    $altroOggetto = alFinto('PATCH', '/v1/crm/campi/persona.ruolo', ['sezione_id' => 'organizzazione.altro'], $gettoni['anna']);

    expect($altroOggetto->json('errors.0.regola'))->toBe('non_trovato')
        ->and(campiDellaSezione($gettoni['anna'], 'persona', 'persona.lavoro'))->toBe(['ruolo', 'reparto']);
});

it('crm.campi.modifica: il nome `it` è unico fra i campi dell\'oggetto col nome che hanno adesso, e un campo che non c\'è è 404 dopo il corpo (CP.2)', function () {
    [, $gettoni] = studioColCrm();

    foreach (['cognome', ' COGNOME', 'Sito'] as $doppione) {
        $risposta = alFinto('PATCH', '/v1/crm/campi/persona.ruolo', ['nome' => ['it' => $doppione]], $gettoni['anna']);

        expect(esito($risposta))->toBe([422, 'dati_non_validi'])
            ->and($risposta->json('errors.0.pointer'))->toBe('#/nome/it')
            ->and($risposta->json('errors.0.regola'))->toBe('duplicato');
    }

    // Il nome nuovo di un campo conta per tutti: «Mansione» è preso, e «Ruolo» torna libero.
    riuscita(alFinto('PATCH', '/v1/crm/campi/persona.ruolo', ['nome' => ['it' => 'Mansione']], $gettoni['anna']));

    expect(esito(alFinto('PATCH', '/v1/crm/campi/persona.reparto', ['nome' => ['it' => 'MANSIONE']], $gettoni['anna'])))->toBe([422, 'dati_non_validi'])
        ->and(alFinto('PATCH', '/v1/crm/campi/persona.reparto', ['nome' => ['it' => 'Ruolo']], $gettoni['anna'])->json('data.nome.it'))->toBe('Ruolo')
        ->and(alFinto('PATCH', '/v1/crm/campi/organizzazione.nome', ['nome' => ['it' => 'Cognome']], $gettoni['anna'])->json('data.nome.it'))->toBe('Cognome');

    foreach ([
        [[], '#', 'obbligatorio'],
        [['visibilita' => 'segreto'], '#/visibilita', 'valore_non_ammesso'],
        [['posizione' => 0], '#/posizione', 'fuori_intervallo'],
        [['sezione_id' => str_repeat('a', 41)], '#/sezione_id', 'troppo_lungo'],
        [['nome' => ['it' => str_repeat('a', 61)]], '#/nome/it', 'troppo_lungo'],
    ] as [$corpo, $pointer, $regola]) {
        $risposta = alFinto('PATCH', '/v1/crm/campi/persona.inesistente', $corpo, $gettoni['anna']);

        expect(esito($risposta))->toBe([422, 'dati_non_validi'])
            ->and($risposta->json('errors.0.pointer'))->toBe($pointer)
            ->and($risposta->json('errors.0.regola'))->toBe($regola);
    }

    foreach (['persona.inesistente', 'cliente.nome', 'Persona.Ruolo', 'x'] as $id) {
        expect(esito(alFinto('PATCH', "/v1/crm/campi/{$id}", ['visibilita' => 'visibile'], $gettoni['anna'])))->toBe([404, 'non_trovato']);
    }
});

it('le sezioni e i campi sono di ogni workspace, e il CRM spento è 403 app_non_attiva prima del corpo (FC.1)', function () {
    [$finto, $gettoni] = studioColCrm();
    $altro = $finto->workspace('Altro studio', $finto->persona('dora@example.com', PASSWORD, 'Dora'));
    $finto->attivaApp($altro, 'crm');
    $dora = alFinto('POST', '/v1/gettoni', ['workspace_id' => $altro['id']], entraNelFinto('dora@example.com')['gettone']['gettone'])->json('data.gettone');
    $creata = alFinto('POST', '/v1/crm/sezioni', ['oggetto' => 'persona', 'nome' => ['it' => 'Famiglia']], $gettoni['anna'])->json('data.id');
    riuscita(alFinto('PATCH', '/v1/crm/campi/persona.ruolo', ['nome' => ['it' => 'Mansione'], 'sezione_id' => $creata], $gettoni['anna']));

    // L'altro workspace vede il catalogo com'è, e la sezione del primo per lui non c'è.
    expect(sezioniDelFinto($dora, 'persona'))->toHaveCount(6)
        ->and(esito(alFinto('PATCH', "/v1/crm/sezioni/{$creata}", ['posizione' => 2], $dora)))->toBe([404, 'non_trovato'])
        ->and(esito(alFinto('DELETE', "/v1/crm/sezioni/{$creata}", null, $dora)))->toBe([404, 'non_trovato'])
        ->and(alFinto('PATCH', '/v1/crm/campi/persona.ruolo', ['sezione_id' => $creata], $dora)->json('errors.0.regola'))->toBe('non_trovato')
        ->and(collect(alFinto('GET', '/v1/crm/campi?oggetto=persona', null, $dora)->json('data'))->keyBy('chiave')['ruolo']['nome']['it'])->toBe('Ruolo');

    expect(alFinto('PATCH', '/v1/app/crm', ['stato' => 'disponibile'], $gettoni['anna'])->status())->toBe(200);

    foreach ([['GET', '/v1/crm/campi?oggetto=persona', null], ['PATCH', '/v1/crm/campi/persona.ruolo', []], ['POST', '/v1/crm/sezioni', []], ['GET', '/v1/crm/sezioni', null]] as [$metodo, $percorso, $corpo]) {
        expect(esito(alFinto($metodo, $percorso, $corpo, $gettoni['anna'])))->toBe([403, 'app_non_attiva']);
    }
});

it('i testi dei nuovi errori sono quelli del backoffice (FC.1)', function () {
    [, $gettoni] = studioColCrm();

    $sistema = alFinto('PATCH', '/v1/crm/sezioni/persona.principali', ['posizione' => 3], $gettoni['anna']);
    $vuota = alFinto('DELETE', '/v1/crm/sezioni/persona.lavoro', null, $gettoni['anna']);
    $indispensabile = alFinto('PATCH', '/v1/crm/campi/persona.nome', ['visibilita' => 'nascosto'], $gettoni['anna']);

    expect($sistema->json('title'))->toBe('Sezione di sistema')
        ->and($sistema->json('detail'))->toBe('«Principali» è la sezione fissa dei campi di un oggetto: non si rinomina, non si sposta e non si elimina. Il metodo non ha scritto niente.')
        ->and($vuota->json('detail'))->toBe("Questa sezione ha ancora campi: si elimina solo senza. Sposta i suoi campi in un'altra sezione con crm.campi.modifica, poi ripeti.")
        ->and($indispensabile->json('errors.0.detail'))->toBe('Questo campo è indispensabile e non si nasconde: lascia `visibilita` a `visibile`.');
});

it('crm.campi.crea: un campo «tuo» in fondo a «Principali», chiave dal nome, opzioni con id, tipi di V1, tetto e nome unico; modifica lo accetta (CC.1-CC.7, CC.11)', function () {
    [, $gettoni] = studioColCrm();
    $anna = $gettoni['anna'];

    $creato = riuscita(alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Livello di studio'], 'tipo' => 'elenco', 'opzioni' => [['nome' => ['it' => 'Diploma'], 'colore' => '#A1B2C3'], ['nome' => ['it' => 'Laurea']]]], $anna));
    $campo = $creato->json('data');

    expect($creato->status())->toBe(201)
        ->and($creato->header('Location'))->toBe('/v1/crm/campi/persona.livello_di_studio')
        ->and($campo['chiave'])->toBe('livello_di_studio')
        ->and($campo)->toMatchArray(['id' => 'persona.livello_di_studio', 'origine' => 'tuo', 'tipo' => 'elenco', 'sezione_id' => 'persona.principali', 'obbligatorio' => false, 'indispensabile' => false, 'in_campi' => true])
        ->and($campo['opzioni'][0]['colore'])->toBe('#a1b2c3')
        ->and($campo['opzioni'][0]['id'])->toHaveLength(26)
        ->and($campo['nome'])->toBe(['it' => 'Livello di studio', 'en' => null, 'es' => null])
        ->and(collect(alFinto('GET', '/v1/crm/campi?oggetto=persona', null, $anna)->json('data'))->pluck('id')->all())->toContain('persona.livello_di_studio');

    foreach ([['tipo' => 'email'], ['tipo' => 'elenco'], ['tipo' => 'testo', 'opzioni' => [['nome' => ['it' => 'X']]]], ['nome' => ['it' => 'ruolo'], 'tipo' => 'testo'], ['tipo' => 'testo', 'sezione_id' => 'trattativa.dettagli']] as $corpo) {
        $risposta = alFinto('POST', '/v1/crm/campi', $corpo + ['oggetto' => 'persona', 'nome' => ['it' => 'Altro']], $anna);
        expect($risposta->status())->toBe(422, $risposta->body());
    }

    expect(riuscita(alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Ruolo!'], 'tipo' => 'testo'], $anna))->json('data.chiave'))->toBe('ruolo_2');

    $nome = riuscita(alFinto('PATCH', '/v1/crm/campi/persona.livello_di_studio', ['nome' => ['en' => 'Education']], $anna));
    expect($nome->json('data.nome'))->toBe(['it' => 'Livello di studio', 'en' => 'Education', 'es' => null]);
    expect(alFinto('PATCH', '/v1/crm/campi/persona.livello_di_studio', ['nome' => ['it' => null]], $anna)->status())->toBe(422);
});

// #2125 (CRM-12 B): i valori dei campi «tuoi» sui contatti. Che le risposte siano quelle del backoffice lo prova il suo FintoTest.
it('i valori dei campi «tuoi» di un contatto: campi {} senza valori, normalizzati, Merge Patch che toglie con null, e nessuno nell\'elenco (CV.1-CV.5, CV.7)', function () {
    [, $gettoni] = studioColCrm();
    $anna = $gettoni['anna'];

    riuscita(alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Pagina'], 'tipo' => 'url'], $anna));
    riuscita(alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Budget'], 'tipo' => 'valuta'], $anna));
    riuscita(alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Punteggio'], 'tipo' => 'numero'], $anna));
    $taglia = riuscita(alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Taglia'], 'tipo' => 'elenco', 'opzioni' => [['nome' => ['it' => 'S']], ['nome' => ['it' => 'M']]]], $anna))->json('data');

    $creato = riuscita(alFinto('POST', '/v1/crm/persone', ['nome' => 'Giulia'], $anna));
    $id = $creato->json('data.id');

    // Senza valori è un oggetto vuoto in JSON, mai una lista.
    expect($creato->status())->toBe(201)
        ->and($creato->body())->toContain('"campi":{}')
        ->and(alFinto('GET', "/v1/crm/persone/{$id}", null, $anna)->body())->toContain('"campi":{}');

    $risposta = riuscita(alFinto('PATCH', "/v1/crm/persone/{$id}", ['campi' => [
        'punteggio' => 10.0, 'budget' => ['importo' => 700], 'taglia' => strtoupper($taglia['opzioni'][1]['id']), 'pagina' => 'https://example.com/a',
    ]], $anna));

    // L'ordine è quello dei campi; l'intero resta intero, la valuta è quella del CRM, l'id dell'opzione sta in minuscolo.
    expect($risposta->json('data.campi'))->toBe([
        'pagina' => 'https://example.com/a', 'budget' => ['importo' => 700, 'valuta' => 'EUR'], 'punteggio' => 10, 'taglia' => $taglia['opzioni'][1]['id'],
    ]);

    $prima = alFinto('GET', "/v1/crm/persone/{$id}", null, $anna)->json('data.aggiornato_il');
    $uguale = riuscita(alFinto('PATCH', "/v1/crm/persone/{$id}", ['campi' => ['punteggio' => 10]], $anna));
    expect($uguale->json('data.aggiornato_il'))->toBe($prima);

    $tolto = riuscita(alFinto('PATCH', "/v1/crm/persone/{$id}", ['campi' => ['pagina' => null, 'budget' => ['importo' => 800]]], $anna));
    expect($tolto->json('data.campi'))->toBe(['budget' => ['importo' => 800, 'valuta' => 'EUR'], 'punteggio' => 10, 'taglia' => $taglia['opzioni'][1]['id']]);

    // L'elenco e la ricerca hanno la forma breve, senza `campi`.
    expect(alFinto('GET', '/v1/crm/persone', null, $anna)->json('data.0'))->not->toHaveKey('campi');

    // La cronologia nomina `campi.<chiave>`: col prima e il dopo per i tipi che lo ammettono, il solo nome per un url.
    $voci = collect(alFinto('GET', "/v1/crm/persone/{$id}/cronologia", null, $anna)->json('data'))->where('tipo', 'modificata')->sortBy(fn (array $voce) => count($voce['campi']))->values();
    expect($voci)->toHaveCount(2)
        ->and($voci[0]['campi'])->toBe([['campo' => 'campi.pagina'], ['campo' => 'campi.budget', 'prima' => ['importo' => 700, 'valuta' => 'EUR'], 'dopo' => ['importo' => 800, 'valuta' => 'EUR']]])
        ->and(array_column($voci[1]['campi'], 'campo'))->toBe(['campi.pagina', 'campi.budget', 'campi.punteggio', 'campi.taglia']);
});

it('un valore che non va, uno standard o una chiave che non c\'è è 422 sul pointer del campo e non scrive niente (CV.2, CV.3, CV.6)', function () {
    [, $gettoni] = studioColCrm();
    $anna = $gettoni['anna'];

    riuscita(alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Punteggio'], 'tipo' => 'numero'], $anna));
    riuscita(alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Obbligo'], 'tipo' => 'testo', 'obbligatorio' => true], $anna));
    $id = riuscita(alFinto('POST', '/v1/crm/persone', ['nome' => 'Giulia', 'campi' => ['obbligo' => 'x']], $anna))->json('data.id');

    foreach ([
        [['punteggio' => '12'], '#/campi/punteggio'],
        [['punteggio' => 1.23456], '#/campi/punteggio'],
        [['ruolo' => 'x'], '#/campi/ruolo'],
        [['non_c_e' => 1], '#/campi/non_c_e'],
        [['obbligo' => null], '#/campi/obbligo'],
    ] as [$campi, $pointer]) {
        $risposta = alFinto('PATCH', "/v1/crm/persone/{$id}", ['campi' => $campi + ['punteggio' => 5]], $anna);

        expect($risposta->status())->toBe(422, $risposta->body())
            ->and($risposta->json('errors.0.pointer'))->toBe($pointer);
    }

    // Un campo obbligatorio vuole il suo valore alla creazione.
    expect(alFinto('POST', '/v1/crm/persone', ['nome' => 'Altro'], $anna)->status())->toBe(422)
        ->and(alFinto('GET', "/v1/crm/persone/{$id}", null, $anna)->json('data.campi'))->toBe(['obbligo' => 'x']);

    // L'oblio svuota i valori.
    riuscita(alFinto('POST', "/v1/crm/persone/{$id}/anonimizzazione", null, $anna));
    expect(alFinto('GET', "/v1/crm/persone/{$id}", null, $anna)->body())->toContain('"campi":{}');
});

// #2133 (CRM-12 B): crm.campi.modifica cambia anche `obbligatorio`, dei soli campi «tuoi». Che le risposte siano quelle del backoffice lo
// prova il suo FintoTest.
it('crm.campi.modifica: obbligatorio si cambia su un campo «tuo» e vale per le scritture nuove; su uno standard è 422 campo_estraneo (OB.1-OB.3)', function () {
    [, $gettoni] = studioColCrm();
    $anna = $gettoni['anna'];

    $campo = riuscita(alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Codice'], 'tipo' => 'testo'], $anna))->json('data');
    $vecchia = riuscita(alFinto('POST', '/v1/crm/persone', ['nome' => 'Vecchia'], $anna))->json('data.id');

    $reso = riuscita(alFinto('PATCH', '/v1/crm/campi/persona.codice', ['obbligatorio' => true], $anna));
    $uguale = riuscita(alFinto('PATCH', '/v1/crm/campi/persona.codice', ['obbligatorio' => true], $anna));

    expect($reso->json('data.obbligatorio'))->toBeTrue()
        ->and($reso->json('data.aggiornato_il'))->toBeGreaterThan($campo['aggiornato_il'])
        ->and($uguale->json('data.aggiornato_il'))->toBe($reso->json('data.aggiornato_il'))
        ->and(alFinto('POST', '/v1/crm/persone', ['nome' => 'Nuova'], $anna)->status())->toBe(422);

    // Il contatto di prima senza il valore resta modificabile.
    riuscita(alFinto('PATCH', "/v1/crm/persone/{$vecchia}", ['ruolo' => 'Cliente'], $anna));

    expect(riuscita(alFinto('PATCH', '/v1/crm/campi/persona.codice', ['obbligatorio' => false], $anna))->json('data.obbligatorio'))->toBeFalse()
        ->and(alFinto('POST', '/v1/crm/persone', ['nome' => 'Nuova'], $anna)->status())->toBe(201);

    foreach (['si', 1, null] as $valore) {
        $risposta = alFinto('PATCH', '/v1/crm/campi/persona.codice', ['obbligatorio' => $valore], $anna);
        expect($risposta->status())->toBe(422, $risposta->body())->and($risposta->json('errors.0.pointer'))->toBe('#/obbligatorio');
    }

    $standard = alFinto('PATCH', '/v1/crm/campi/persona.ruolo', ['obbligatorio' => true], $anna);
    expect($standard->status())->toBe(422, $standard->body())
        ->and($standard->json('errors.0.pointer'))->toBe('#/obbligatorio')
        ->and($standard->json('errors.0.regola'))->toBe('campo_estraneo');
});

// #2154 (CRM-12 C1): crm.campi.elimina e il ripristino. Che le risposte siano quelle del backoffice lo prova il suo FintoTest.
it('crm.campi.elimina: 204, il campo esce dall\'elenco e compare in archiviati=true; i suoi valori non si leggono né si scrivono (CE.1, CE.2)', function () {
    [, $gettoni] = studioColCrm();
    $anna = $gettoni['anna'];

    riuscita(alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Livello'], 'tipo' => 'testo'], $anna));
    riuscita(alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Codice'], 'tipo' => 'testo', 'obbligatorio' => true], $anna));
    $persona = riuscita(alFinto('POST', '/v1/crm/persone', ['nome' => 'Giulia', 'campi' => ['codice' => 'A1', 'livello' => 'alto']], $anna))->json('data.id');

    $eliminato = alFinto('DELETE', '/v1/crm/campi/persona.livello', null, $anna);
    $eliminatoObbligatorio = alFinto('DELETE', '/v1/crm/campi/persona.codice', null, $anna);
    $archiviati = alFinto('GET', '/v1/crm/campi?oggetto=persona&archiviati=true', null, $anna);
    $scrittura = alFinto('PATCH', "/v1/crm/persone/{$persona}", ['campi' => ['livello' => 'basso']], $anna);

    expect($eliminato->status())->toBe(204)
        ->and($eliminatoObbligatorio->status())->toBe(204)
        ->and(collect(alFinto('GET', '/v1/crm/campi?oggetto=persona', null, $anna)->json('data'))->pluck('id')->all())->not->toContain('persona.livello')
        ->and(array_column($archiviati->json('data'), 'id'))->toBe(['persona.codice', 'persona.livello'])
        ->and($archiviati->json('data.1.archiviato_il'))->not->toBeNull()
        ->and($archiviati->json('data.1.ripristinabile_fino'))->toBeGreaterThan($archiviati->json('data.1.archiviato_il'))
        ->and(alFinto('GET', "/v1/crm/persone/{$persona}", null, $anna)->json('data.campi'))->toBe([])
        ->and($scrittura->status())->toBe(422)
        ->and($scrittura->json('errors.0.pointer'))->toBe('#/campi/livello')
        ->and($scrittura->json('errors.0.regola'))->toBe('non_trovato')
        // `codice` era obbligatorio: eliminato non vincola più.
        ->and(alFinto('POST', '/v1/crm/persone', ['nome' => 'Zeno'], $anna)->status())->toBe(201)
        ->and(alFinto('GET', '/v1/crm/campi?oggetto=persona&archiviati=forse', null, $anna)->status())->toBe(422);
});

it('crm.campi.elimina: uno standard è 409 campo_di_sistema, uno già eliminato o che non c\'è è 404, il membro 403 (CE.3, CE.8)', function () {
    [, $gettoni] = studioColCrm();
    $anna = $gettoni['anna'];
    riuscita(alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Livello'], 'tipo' => 'testo'], $anna));

    $standard = alFinto('DELETE', '/v1/crm/campi/persona.ruolo', null, $anna);
    $membro = alFinto('DELETE', '/v1/crm/campi/persona.livello', null, $gettoni['carla']);

    expect($standard->status())->toBe(409)
        ->and($standard->json('codice'))->toBe('campo_di_sistema')
        ->and($membro->status())->toBe(403)
        ->and($membro->json('codice'))->toBe('permesso_negato')
        ->and(alFinto('DELETE', '/v1/crm/campi/persona.livello', null, $anna)->status())->toBe(204)
        ->and(alFinto('DELETE', '/v1/crm/campi/persona.livello', null, $anna)->status())->toBe(404)
        ->and(alFinto('DELETE', '/v1/crm/campi/persona.inesistente', null, $anna)->status())->toBe(404);
});

it('crm.campi.modifica {archiviato: false} ripristina in fondo alla sezione coi valori; true è 422; un nome preso è 422 duplicato; dopo 30 giorni è 404 (CE.4, CE.5)', function () {
    [, $gettoni] = studioColCrm();
    $anna = $gettoni['anna'];
    riuscita(alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Livello'], 'tipo' => 'testo'], $anna));
    riuscita(alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Codice'], 'tipo' => 'testo'], $anna));
    $persona = riuscita(alFinto('POST', '/v1/crm/persone', ['nome' => 'Giulia', 'campi' => ['livello' => 'alto']], $anna))->json('data.id');
    alFinto('DELETE', '/v1/crm/campi/persona.livello', null, $anna);

    $vero = alFinto('PATCH', '/v1/crm/campi/persona.livello', ['archiviato' => true], $anna);
    $estraneo = alFinto('PATCH', '/v1/crm/campi/persona.livello', ['archiviato' => false, 'nome' => ['it' => 'Altro']], $anna);
    $ripristinato = riuscita(alFinto('PATCH', '/v1/crm/campi/persona.livello', ['archiviato' => false], $anna));
    $elenco = array_column(array_filter(alFinto('GET', '/v1/crm/campi?oggetto=persona', null, $anna)->json('data'), fn (array $campo) => $campo['sezione_id'] === 'persona.principali'), 'id');

    expect($vero->status())->toBe(422)
        ->and($vero->json('errors.0.pointer'))->toBe('#/archiviato')
        ->and($vero->json('errors.0.regola'))->toBe('valore_non_ammesso')
        ->and($estraneo->status())->toBe(422)
        ->and($estraneo->json('errors.0.regola'))->toBe('campo_estraneo')
        ->and($ripristinato->json('data.archiviato_il'))->toBeNull()
        ->and(array_slice($elenco, -2))->toBe(['persona.codice', 'persona.livello'])
        ->and(alFinto('GET', "/v1/crm/persone/{$persona}", null, $anna)->json('data.campi'))->toBe(['livello' => 'alto'])
        // Su un campo vivo `archiviato: false` non cambia niente.
        ->and(riuscita(alFinto('PATCH', '/v1/crm/campi/persona.codice', ['archiviato' => false], $anna))->json('data.id'))->toBe('persona.codice');

    // Il nome preso nel frattempo: il ripristino è 422 duplicato e il campo resta eliminato.
    alFinto('DELETE', '/v1/crm/campi/persona.livello', null, $anna);
    riuscita(alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Livello'], 'tipo' => 'testo'], $anna));
    $preso = alFinto('PATCH', '/v1/crm/campi/persona.livello', ['archiviato' => false], $anna);

    expect($preso->status())->toBe(422)
        ->and($preso->json('errors.0.pointer'))->toBe('#/nome/it')
        ->and($preso->json('errors.0.regola'))->toBe('duplicato')
        ->and(array_column(alFinto('GET', '/v1/crm/campi?oggetto=persona&archiviati=true', null, $anna)->json('data'), 'id'))->toBe(['persona.livello']);

    // Eliminato 31 giorni fa passa nell'archivio: per l'API non c'è più, nemmeno per ripristinarlo (il gettone non scade: il tempo torna indietro).
    riuscita(alFinto('POST', '/v1/crm/campi', ['oggetto' => 'persona', 'nome' => ['it' => 'Vecchio'], 'tipo' => 'testo'], $anna));
    $this->travelTo(now()->subDays(31));
    alFinto('DELETE', '/v1/crm/campi/persona.vecchio', null, $anna);
    $this->travelBack();

    expect(alFinto('PATCH', '/v1/crm/campi/persona.vecchio', ['archiviato' => false], $anna)->status())->toBe(404)
        ->and(array_column(alFinto('GET', '/v1/crm/campi?oggetto=persona&archiviati=true', null, $anna)->json('data'), 'id'))->toBe(['persona.livello']);
});
