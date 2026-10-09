<?php

use Random\Engine\Mt19937;
use Random\Randomizer;
use Zeiras\Auth\Testing\BackofficeFinto;

// T6.1 e T6.4 (#1171: B1.4, B1.6; #1172: B2.4): il finto legge come il backoffice — io.mostra, io.workspace.elenca,
// app.elenca e workspace.membri.elenca, coi dati delle sue persone e dei suoi workspace: in ordine di nome (maiuscole e
// accenti non contano) e poi di id, a pagine col cursore della sola chiave, lo slug del workspace, gettone_senza_workspace
// dove lo dà il backoffice, il catalogo delle app.

const TESTO_DEL_CURSORE = 'Il campo cursore non è un cursore di questa lista: usa il valore di successivo della pagina prima.';

/** Il gettone di chi ha questa email: quello dell'accesso, o quello del workspace, se c'è. */
function gettoneDelFinto(string $email, ?array $workspace = null): string
{
    $gettone = entraNelFinto($email)['gettone']['gettone'];

    if ($workspace === null) {
        return $gettone;
    }

    $risposta = alFinto('POST', '/v1/gettoni', ['workspace_id' => $workspace['id']], $gettone);
    expect($risposta->status())->toBe(201);

    return $risposta->json('data.gettone');
}

/** I dati di un `successivo`, decodificati: ciò che legge chi ha l'indirizzo. */
function datiDelCursore(string $cursore): mixed
{
    return json_decode((string) base64_decode(strtr(explode('.', $cursore)[0], '-_', '+/')), true);
}

/**
 * Tutte le voci di una lista del finto, una pagina dopo l'altra col `successivo`: ogni cursore porta la sola chiave
 * dell'ultima voce della sua pagina.
 *
 * @return list<array<string, mixed>>
 */
function tutteLePagine(string $percorso, string $gettone, int $limite, string $chiave = 'id'): array
{
    $voci = [];
    $cursore = null;

    do {
        $pagina = alFinto('GET', $percorso.'?limite='.$limite.($cursore === null ? '' : '&cursore='.$cursore), gettone: $gettone);
        expect($pagina->status())->toBe(200)
            ->and(count($pagina->json('data')))->toBeLessThanOrEqual($limite);
        $cursore = $pagina->json('successivo');
        $voci = [...$voci, ...$pagina->json('data')];

        if ($cursore !== null) {
            expect(datiDelCursore($cursore))->toBe([$chiave => $voci[count($voci) - 1][$chiave]]);
        }
    } while ($cursore !== null);

    return $voci;
}

/** Due voci con lo stesso nome, in ordine di id. */
function perId(array $una, array $altra): array
{
    return strcmp($una['id'], $altra['id']) < 0 ? [$una, $altra] : [$altra, $una];
}

it('workspace() dà lo slug del backoffice: il nome in slug, al più 40 caratteri, poi sei caratteri casuali; gettoni.crea dà lo stesso workspace (T6.4)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio Anna', $anna);
    $altroStudio = $finto->workspace('Studio Anna', $anna);
    $accenti = $finto->workspace('Caffè & Città', $anna);
    $senzaLettere = $finto->workspace('!!!', $anna);
    // Il taglio a 40 cade su un trattino, che non resta in fondo.
    $tagliato = $finto->workspace('Aaaaaaaaa Bbbbbbbbb Ccccccccc Ddddddddd Eeeee', $anna);

    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone']);

    expect(array_keys($studio))->toBe(['id', 'nome', 'slug', 'azienda_id'])
        ->and($studio['nome'])->toBe('Studio Anna')
        ->and($studio['slug'])->toMatch('/^studio-anna-[a-z0-9]{6}$/')
        ->and($altroStudio['slug'])->toMatch('/^studio-anna-[a-z0-9]{6}$/')->not->toBe($studio['slug'])
        ->and($accenti['slug'])->toMatch('/^caffe-citta-[a-z0-9]{6}$/')
        ->and($senzaLettere['slug'])->toMatch('/^workspace-[a-z0-9]{6}$/')
        ->and($tagliato['slug'])->toMatch('/^aaaaaaaaa-bbbbbbbbb-ccccccccc-ddddddddd-[a-z0-9]{6}$/')
        ->and($gettone->json('data.workspace'))->toBe($studio);
});

it('lo slug è unico nel finto: se i caratteri casuali ridanno uno slug già preso, se ne estraggono altri (T6.4)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $caso = new ReflectionProperty(BackofficeFinto::class, 'caso');
    // La stessa sequenza due volte: la prima estrazione del secondo workspace ridà lo slug del primo.
    $caso->setValue($finto, new Randomizer(new Mt19937(83)));
    $primo = $finto->workspace('Studio Anna', $anna);
    $caso->setValue($finto, new Randomizer(new Mt19937(83)));
    $secondo = $finto->workspace('Studio Anna', $anna);

    expect($primo['slug'])->toMatch('/^studio-anna-[a-z0-9]{6}$/')
        ->and($secondo['slug'])->toMatch('/^studio-anna-[a-z0-9]{6}$/')->not->toBe($primo['slug']);
});

it('io.mostra dà la persona del gettone e, col gettone di un workspace, il workspace con lo slug e il ruolo letto a ogni chiamata (T6.1)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $bruno = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno', lingua: 'en');
    $studio = $finto->workspace('Studio Anna', $anna);
    $finto->membro($studio, $bruno, 'membro');
    $delWorkspace = gettoneDelFinto('bruno@example.com', $studio);

    $senzaWorkspace = alFinto('GET', '/v1/io', gettone: gettoneDelFinto('bruno@example.com'));
    $prima = alFinto('GET', '/v1/io', gettone: $delWorkspace);
    $finto->membro($studio, $bruno, 'amministratore');
    $dopo = alFinto('GET', '/v1/io', gettone: $delWorkspace);

    expect($senzaWorkspace->status())->toBe(200)
        ->and($senzaWorkspace->header('Content-Type'))->toBe('application/json')
        ->and($senzaWorkspace->header('Link'))->toBe(linkDi('io.mostra'))
        ->and($senzaWorkspace->json())->toBe(['data' => ['utente' => $bruno, 'workspace' => null, 'ruolo' => null, 'notifiche_non_lette' => null]])
        ->and($prima->json())->toBe(['data' => ['utente' => $bruno, 'workspace' => $studio, 'ruolo' => 'membro', 'notifiche_non_lette' => 0]])
        ->and($dopo->json())->toBe(['data' => ['utente' => $bruno, 'workspace' => $studio, 'ruolo' => 'amministratore', 'notifiche_non_lette' => 0]]);
});

it('io.workspace.elenca dà i workspace della persona, col ruolo e lo slug, in ordine di nome — maiuscole e accenti non contano — e poi di id, con ogni suo gettone (T6.1)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $bruno = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $beta = $finto->workspace('beta', $anna);
    $albero = $finto->workspace('Àlbero', $bruno);
    $finto->membro($albero, $anna, 'amministratore');
    $alfa = $finto->workspace('Alfa', $anna);
    $alfaDiBruno = $finto->workspace('alfa', $bruno);
    $finto->membro($alfaDiBruno, $anna, 'membro');
    $finto->workspace('Studio Bruno', $bruno);
    [$primoAlfa, $secondoAlfa] = perId([...$alfa, 'ruolo' => 'proprietario'], [...$alfaDiBruno, 'ruolo' => 'membro']);
    $attese = [[...$albero, 'ruolo' => 'amministratore'], $primoAlfa, $secondoAlfa, [...$beta, 'ruolo' => 'proprietario']];

    $coiGettoni = array_map(
        fn (string $gettone) => alFinto('GET', '/v1/io/workspace', gettone: $gettone),
        [gettoneDelFinto('anna@example.com'), gettoneDelFinto('anna@example.com', $beta)],
    );

    foreach ($coiGettoni as $risposta) {
        expect($risposta->status())->toBe(200)
            ->and($risposta->header('Link'))->toBe(linkDi('io.workspace.elenca'))
            ->and($risposta->json())->toBe(['data' => $attese, 'successivo' => null]);
    }
});

it('io.workspace.elenca dà le pagine a cursore: ogni workspace una volta, e il successivo porta il solo id (T6.1)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);

    foreach (['Delta', 'Alfa', 'Gamma', 'Alfa', 'Beta'] as $nome) {
        $finto->workspace($nome, $anna);
    }

    $gettone = gettoneDelFinto('anna@example.com');
    $tutti = alFinto('GET', '/v1/io/workspace', gettone: $gettone)->json('data');

    expect(array_column($tutti, 'nome'))->toBe(['Alfa', 'Alfa', 'Beta', 'Delta', 'Gamma'])
        ->and(tutteLePagine('/v1/io/workspace', $gettone, 2))->toBe($tutti);
});

it('app.elenca dà il catalogo delle app in ordine di codice, pm disponibile e le altre in_arrivo, ai tre ruoli del workspace, e a pagine col solo codice (T6.1; #1289, AP3)', function (string $ruolo) {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $bruno = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $studio = $finto->workspace('Studio Anna', $anna);
    $chi = $ruolo === 'proprietario' ? 'anna@example.com' : 'bruno@example.com';

    if ($ruolo !== 'proprietario') {
        $finto->membro($studio, $bruno, $ruolo);
    }

    $gettone = gettoneDelFinto($chi, $studio);
    $risposta = alFinto('GET', '/v1/app', gettone: $gettone);
    // Lo stato di ogni app, scritto per intero (R23): pm è disponibile dallo sprint 5 del backoffice (#1289, AP3).
    $nome = fn (string $testo) => ['it' => $testo, 'en' => $testo, 'es' => $testo];
    $catalogo = [
        ['codice' => 'automations', 'stato' => 'in_arrivo', 'nome' => $nome('Automations')],
        ['codice' => 'bookings', 'stato' => 'in_arrivo', 'nome' => $nome('Bookings')],
        ['codice' => 'content', 'stato' => 'in_arrivo', 'nome' => $nome('Content')],
        ['codice' => 'crm', 'stato' => 'in_arrivo', 'nome' => $nome('CRM')],
        ['codice' => 'pm', 'stato' => 'disponibile', 'nome' => $nome('Project Management')],
        ['codice' => 'reports', 'stato' => 'in_arrivo', 'nome' => $nome('Reports')],
    ];

    expect($risposta->status())->toBe(200)
        ->and($risposta->header('Link'))->toBe(linkDi('app.elenca'))
        ->and($risposta->json())->toBe(['data' => $catalogo, 'successivo' => null])
        ->and(tutteLePagine('/v1/app', $gettone, 4, 'codice'))->toBe($catalogo);
})->with(['proprietario', 'amministratore', 'membro']);

it('workspace.membri.elenca dà le persone del workspace del gettone, con id della persona, nome, email e ruolo, in ordine di nome e poi di id, ai tre ruoli (T6.1)', function (string $ruolo) {
    $finto = BackofficeFinto::attiva();
    // In minuscolo anna e carla: in binario verrebbero dopo Bruno, e le maiuscole non contano.
    $anna = $finto->persona('anna@example.com', PASSWORD, nome: 'anna');
    $carla = $finto->persona('carla@example.com', PASSWORD, nome: 'carla');
    $bruno = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $altroBruno = $finto->persona('bruno.due@example.com', PASSWORD, nome: 'Bruno');
    $elena = $finto->persona('elena@example.com', PASSWORD, nome: 'Elena');
    $studio = $finto->workspace('Studio Anna', $anna);
    $finto->membro($studio, $carla, 'amministratore');
    $finto->membro($studio, $bruno, 'membro');
    $finto->membro($studio, $altroBruno, 'membro');
    // Un altro workspace, coi suoi membri: Bruno è anche lì, con un altro ruolo.
    $altro = $finto->workspace('Altro', $elena);
    $franco = $finto->persona('franco@example.com', PASSWORD, nome: 'Franco');
    $finto->membro($altro, $franco, 'membro');
    $finto->membro($altro, $bruno, 'amministratore');
    $chi = ['proprietario' => 'anna@example.com', 'amministratore' => 'carla@example.com', 'membro' => 'bruno@example.com'][$ruolo];
    $membro = fn (array $persona, string $ruolo) => ['id' => $persona['id'], 'nome' => $persona['nome'], 'email' => $persona['email'], 'ruolo' => $ruolo];

    $gettone = gettoneDelFinto($chi, $studio);
    $risposta = alFinto('GET', '/v1/workspace/membri', gettone: $gettone);
    // Col gettone dell'altro workspace, che non è il primo nato, i membri di quello: Bruno col ruolo che ha lì.
    $diAltro = alFinto('GET', '/v1/workspace/membri', gettone: gettoneDelFinto('elena@example.com', $altro));

    expect($risposta->status())->toBe(200)
        ->and($risposta->header('Link'))->toBe(linkDi('workspace.membri.elenca'))
        ->and($risposta->json())->toBe(['data' => [
            $membro($anna, 'proprietario'),
            ...perId($membro($bruno, 'membro'), $membro($altroBruno, 'membro')),
            $membro($carla, 'amministratore'),
        ], 'successivo' => null])
        ->and(tutteLePagine('/v1/workspace/membri', $gettone, 1))->toBe($risposta->json('data'))
        ->and($diAltro->json())->toBe(['data' => [
            $membro($bruno, 'amministratore'),
            $membro($elena, 'proprietario'),
            $membro($franco, 'membro'),
        ], 'successivo' => null]);
})->with(['proprietario', 'amministratore', 'membro']);

it('una lista senza limite dà pagine di 50 voci, e la pagina dopo parte dalla cinquantunesima (T6.1)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $nomi = array_map(fn (int $n) => sprintf('Workspace %02d', $n), range(1, 51));

    foreach ($nomi as $nome) {
        $finto->workspace($nome, $anna);
    }

    $gettone = gettoneDelFinto('anna@example.com');
    $prima = alFinto('GET', '/v1/io/workspace', gettone: $gettone);
    $seconda = alFinto('GET', '/v1/io/workspace?cursore='.$prima->json('successivo'), gettone: $gettone);

    expect($prima->status())->toBe(200)
        ->and(array_column($prima->json('data'), 'nome'))->toBe(array_slice($nomi, 0, 50))
        ->and($prima->json('successivo'))->toBeString()
        ->and($seconda->status())->toBe(200)
        ->and(array_column($seconda->json('data'), 'nome'))->toBe(['Workspace 51'])
        ->and($seconda->json('successivo'))->toBeNull();
});

it("app.elenca e workspace.membri.elenca rispondono 403 gettone_senza_workspace al gettone dell'accesso, nella lingua della persona (T6.1)", function (string $percorso, string $metodo) {
    $finto = BackofficeFinto::attiva();
    $finto->workspace('Studio Anna', $finto->persona('anna@example.com', PASSWORD, lingua: 'en'));

    $risposta = alFinto('GET', $percorso, gettone: gettoneDelFinto('anna@example.com'));

    expect($risposta->status())->toBe(403)
        ->and($risposta->header('Content-Type'))->toBe('application/problem+json')
        ->and($risposta->header('Link'))->toBe(linkDi($metodo))
        ->and($risposta->json())->toBe(problemaAtteso('gettone_senza_workspace', 403, 'Token without workspace',
            'This method works on the data of a workspace, and the token is not of a workspace: ask for the workspace token.'));
})->with([
    'app.elenca' => ['/v1/app', 'app.elenca'],
    'workspace.membri.elenca' => ['/v1/workspace/membri', 'workspace.membri.elenca'],
]);

it('le letture senza gettone sono 401 gettone_assente', function (string $percorso) {
    BackofficeFinto::attiva();

    expect(alFinto('GET', $percorso)->json('codice'))->toBe('gettone_assente');
})->with(['/v1/io', '/v1/io/workspace', '/v1/app', '/v1/workspace/membri']);

it('rifiuta un limite fuori da 1-100 e un cursore che non è un successivo di questa lista: 422 sul parametro, coi testi del backoffice (T6.1)', function (string $percorso, Closure $query, array $errore) {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio Anna', $anna);
    $finto->workspace('Altro', $anna);
    $gettone = gettoneDelFinto('anna@example.com', $studio);

    $risposta = alFinto('GET', $percorso.'?'.$query($gettone), gettone: $gettone);

    expect($risposta->status())->toBe(422)
        ->and($risposta->json())->toBe(problemaAtteso('dati_non_validi', 422, 'Dati non validi',
            'Alcuni valori non vanno bene: li trovi in errors, con cosa non va e dove stanno.', ['errors' => [$errore]]));
})->with([
    // R7: la Closure arriva intera perché il parametro è tipato Closure.
    'limite zero' => ['/v1/workspace/membri', fn () => 'limite=0', ['detail' => 'Il campo limite deve essere fra 1 e 100.', 'parameter' => 'limite']],
    'limite oltre 100' => ['/v1/io/workspace', fn () => 'limite=101', ['detail' => 'Il campo limite deve essere fra 1 e 100.', 'parameter' => 'limite']],
    'limite che non è un numero' => ['/v1/app', fn () => 'limite=due', ['detail' => 'Il campo limite deve essere un numero intero.', 'parameter' => 'limite']],
    'cursore inventato' => ['/v1/io/workspace', fn () => 'cursore=inventato', ['detail' => TESTO_DEL_CURSORE, 'parameter' => 'cursore']],
    "cursore di un'altra lista" => ['/v1/io/workspace', fn (string $gettone) => 'cursore='.alFinto('GET', '/v1/app?limite=1', gettone: $gettone)->json('successivo'), ['detail' => TESTO_DEL_CURSORE, 'parameter' => 'cursore']],
    'cursore ritoccato' => ['/v1/io/workspace', fn (string $gettone) => 'cursore=x'.alFinto('GET', '/v1/io/workspace?limite=1', gettone: $gettone)->json('successivo'), ['detail' => TESTO_DEL_CURSORE, 'parameter' => 'cursore']],
]);

it("io.aziende.elenca dà le aziende dei workspace della persona, una volta sola, col nome del primo workspace che l'ha fatta nascere, in ordine di nome e poi di id, con ogni suo gettone (AZ1, AZ2)", function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $bruno = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $beta = $finto->workspace('beta', $anna);
    $albero = $finto->workspace('Àlbero', $bruno);
    $finto->membro($albero, $anna, 'amministratore');
    $alfa = $finto->workspace('Alfa', $anna);
    $finto->workspace('Studio Bruno', $bruno);

    // Un secondo workspace nella stessa azienda di beta (io.workspace.crea con il suo azienda_id): l'azienda non raddoppia.
    $secondo = alFinto('POST', '/v1/io/workspace', ['nome' => 'Beta due', 'azienda_id' => $beta['azienda_id']], gettoneDelFinto('anna@example.com'));
    expect($secondo->status())->toBe(201)
        ->and($secondo->json('data.azienda_id'))->toBe($beta['azienda_id']);

    // Rinominare il workspace non rinomina l'azienda (il backoffice la nomina una volta, alla nascita).
    expect(alFinto('PATCH', '/v1/workspace', ['nome' => 'Tutt’altro nome'], gettoneDelFinto('anna@example.com', $beta))->status())->toBe(200);

    $attese = [
        ['id' => $albero['azienda_id'], 'nome' => 'Àlbero'],
        ['id' => $alfa['azienda_id'], 'nome' => 'Alfa'],
        ['id' => $beta['azienda_id'], 'nome' => 'beta'],
    ];

    foreach ([gettoneDelFinto('anna@example.com'), gettoneDelFinto('anna@example.com', $beta)] as $gettone) {
        $risposta = alFinto('GET', '/v1/io/aziende', gettone: $gettone);

        expect($risposta->status())->toBe(200)
            ->and($risposta->header('Link'))->toBe(linkDi('io.aziende.elenca'))
            ->and($risposta->json())->toBe(['data' => $attese, 'successivo' => null]);
    }

    // Studio Bruno non è di Anna: la sua azienda non compare. Per Bruno compaiono le due sue.
    $diBruno = alFinto('GET', '/v1/io/aziende', gettone: gettoneDelFinto('bruno@example.com'));

    expect(array_column($diBruno->json('data'), 'nome'))->toBe(['Àlbero', 'Studio Bruno']);
});

it('io.aziende.elenca dà le pagine a cursore col solo id; chi non ha workspace non ha aziende; un limite fuori misura è un 422 e nessun gettone un 401 (AZ1, AZ3)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');

    foreach (['Delta', 'Alfa', 'Gamma', 'Alfa', 'Beta'] as $nome) {
        $finto->workspace($nome, $anna);
    }

    $gettone = gettoneDelFinto('anna@example.com');
    $tutte = alFinto('GET', '/v1/io/aziende', gettone: $gettone)->json('data');

    expect(array_column($tutte, 'nome'))->toBe(['Alfa', 'Alfa', 'Beta', 'Delta', 'Gamma'])
        ->and(tutteLePagine('/v1/io/aziende', $gettone, 2))->toBe($tutte)
        ->and(alFinto('GET', '/v1/io/aziende', gettone: gettoneDelFinto('bruno@example.com'))->json())->toBe(['data' => [], 'successivo' => null])
        ->and(alFinto('GET', '/v1/io/aziende?limite=0', gettone: $gettone)->status())->toBe(422)
        ->and(alFinto('GET', '/v1/io/aziende?cursore=inventato', gettone: $gettone)->status())->toBe(422)
        ->and(alFinto('GET', '/v1/io/aziende')->status())->toBe(401);
});
