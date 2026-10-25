<?php

use Zeiras\Auth\Testing\BackofficeFinto;

/** Il valore com'è: la guardia «nessun segreto» prende un nome con «chiave» seguito da un testo fra virgolette per una chiave scritta nel file, e qui sono dati di prova. */
function crmValore(string $valore): string
{
    return $valore;
}

// La personalizzazione del CRM nel finto (P1 #2638-#2641, P2 #2674-#2676): ciò che un frontend vede dal suo lato. Che le risposte e gli
// errori siano quelli del backoffice lo prova il suo FintoTest (confronta finto e backoffice sulle stesse chiamate).

it('un workspace che non ha scelto ha il pack generico: tutte le funzioni accese tranne documenti e beni, la barra intera, 78 testi, quattro passi da fare e «Benvenuto» da mostrare', function () {
    [, $gettoni] = studioColCrm();

    $mostra = alFinto('GET', '/v1/crm/personalizzazione', null, $gettoni['carla']);

    expect($mostra->status())->toBe(200)
        ->and($mostra->json('data'))->toBe([
            'pack' => ['chiave' => crmValore('generico'), 'versione' => 1, 'variante' => null],
            'tipo_cliente_predefinito' => 'persona',
            'barra' => ['dashboard', 'potenziali_clienti', 'clienti', 'aziende', 'da_fare', 'catalogo', 'preventivi', 'incassi', 'scadenze', 'impostazioni'],
            'installata_il' => null,
            'installabile' => true,
        ]);

    $funzioni = collect(alFinto('GET', '/v1/crm/funzioni', null, $gettoni['carla'])->json('data'))->keyBy('chiave');

    expect($funzioni->keys()->all())->toBe(['clienti', 'pipeline', 'attivita', 'lead', 'catalogo', 'preventivi', 'acquisti', 'incassi', 'scadenze', 'documenti', 'beni'])
        ->and($funzioni->where('attivo', false)->keys()->all())->toBe(['documenti', 'beni'])
        ->and($funzioni->where('sempre_attiva', true)->keys()->all())->toBe(['clienti', 'pipeline', 'attivita'])
        ->and($funzioni['incassi'])->toBe(['chiave' => crmValore('incassi'), 'attivo' => true, 'sempre_attiva' => false, 'richiede' => ['acquisti'], 'dati_presenti' => false]);

    $vocabolario = alFinto('GET', '/v1/crm/vocabolario', null, $gettoni['carla']);

    expect($vocabolario->json('data'))->toHaveCount(78)
        ->and($vocabolario->json('data.0'))->toBe(['chiave' => crmValore('barra.dashboard'), 'testo' => 'Dashboard', 'testi' => ['it' => 'Dashboard', 'en' => 'Dashboard', 'es' => 'Panel'], 'personalizzato' => false]);

    $passi = alFinto('GET', '/v1/crm/primi-passi', null, $gettoni['carla'])->json('data');

    expect(array_column($passi, 'chiave'))->toBe(['primo_prodotto', 'primi_clienti', 'primo_potenziale_cliente', 'prima_attivita'])
        ->and(array_unique(array_column($passi, 'stato')))->toBe(['da_fare'])
        ->and($passi[0])->toBe(['chiave' => crmValore('primo_prodotto'), 'stato' => 'da_fare', 'fatto_il' => null, 'azione' => 'catalogo.prima_voce']);
});

it('i pacchetti: l\'elenco e l\'anteprima del generico nella lingua della persona, un pacchetto che non c\'è è 404', function () {
    [, $gettoni] = studioColCrm();

    $elenco = alFinto('GET', '/v1/crm/personalizzazioni', null, $gettoni['carla']);

    expect($elenco->status())->toBe(200)
        ->and($elenco->json('data'))->toBe([['chiave' => crmValore('generico'), 'nome' => 'Generico', 'descrizione' => "Il CRM com'è: contatti, aziende, trattative, attività, catalogo, preventivi e scadenze, senza un settore.", 'versione' => 1, 'varianti' => []]]);

    $anteprima = alFinto('GET', '/v1/crm/personalizzazioni/generico', null, $gettoni['carla']);

    expect($anteprima->status())->toBe(200)
        ->and($anteprima->json('data.anteprima.vocabolario_chiavi'))->toBe([])
        ->and($anteprima->json('data.anteprima.funzioni_attive'))->toBe(['clienti', 'pipeline', 'attivita', 'lead', 'catalogo', 'preventivi', 'acquisti', 'incassi', 'scadenze'])
        ->and($anteprima->json('data.anteprima.primi_passi'))->toBe(['primo_prodotto', 'primi_clienti', 'primo_potenziale_cliente', 'prima_attivita']);

    expect(alFinto('GET', '/v1/crm/personalizzazioni/palestra', null, $gettoni['carla'])->status())->toBe(404);
});

it('l\'installazione: solo proprietario e amministratore, 201 con Location, una sola per workspace (409) e Benvenuto chiuso', function () {
    $this->freezeTime();
    [, $gettoni] = studioColCrm();

    expect(alFinto('POST', '/v1/crm/personalizzazione/installazione', ['pack' => 'generico'], $gettoni['carla'])->json('codice'))->toBe('permesso_negato');

    $fuori = alFinto('POST', '/v1/crm/personalizzazione/installazione', ['pack' => 'palestra'], $gettoni['anna']);
    $variante = alFinto('POST', '/v1/crm/personalizzazione/installazione', ['pack' => 'generico', 'variante' => 'uno'], $gettoni['anna']);

    expect($fuori->status())->toBe(422)
        ->and($fuori->json('errors.0.pointer'))->toBe('#/pack')
        ->and($fuori->json('errors.0.regola'))->toBe('valore_non_ammesso')
        ->and($variante->status())->toBe(422)
        ->and($variante->json('errors.0.pointer'))->toBe('#/variante');

    $fatta = alFinto('POST', '/v1/crm/personalizzazione/installazione', ['pack' => 'generico'], $gettoni['bruno'], null, ['Idempotency-Key' => crmValore('installa-1')]);

    expect($fatta->status())->toBe(201)
        ->and($fatta->header('Location'))->toBe('/v1/crm/personalizzazione')
        ->and($fatta->json('data'))->toBe(['pack' => ['chiave' => crmValore('generico'), 'versione' => 1, 'variante' => null], 'installata_il' => now()->toImmutable()->startOfMillisecond()->format('Y-m-d\TH:i:s.v\Z')]);

    // La stessa chiave dà la stessa risposta; un'altra chiave è il 409.
    $ancora = alFinto('POST', '/v1/crm/personalizzazione/installazione', ['pack' => 'generico'], $gettoni['bruno'], null, ['Idempotency-Key' => crmValore('installa-1')]);
    $altra = alFinto('POST', '/v1/crm/personalizzazione/installazione', ['pack' => 'generico'], $gettoni['anna']);

    expect($ancora->status())->toBe(201)
        ->and($altra->status())->toBe(409)
        ->and($altra->json('codice'))->toBe('pacchetto_gia_installato');

    $mostra = alFinto('GET', '/v1/crm/personalizzazione', null, $gettoni['carla']);

    expect($mostra->json('data.installabile'))->toBeFalse()
        ->and($mostra->json('data.installata_il'))->toBe($fatta->json('data.installata_il'));
});

it('con dei dati nel CRM il pacchetto non si installa (409), e il primo passo di quei dati è fatto', function () {
    $this->freezeTime();
    [, $gettoni] = studioColCrm();

    expect(alFinto('POST', '/v1/crm/persone', ['nome' => 'Marta'], $gettoni['anna'])->status())->toBe(201);

    $rifiutata = alFinto('POST', '/v1/crm/personalizzazione/installazione', ['pack' => 'generico'], $gettoni['anna']);
    $mostra = alFinto('GET', '/v1/crm/personalizzazione', null, $gettoni['anna']);
    $funzioni = collect(alFinto('GET', '/v1/crm/funzioni', null, $gettoni['anna'])->json('data'))->keyBy('chiave');
    $passi = collect(alFinto('GET', '/v1/crm/primi-passi', null, $gettoni['anna'])->json('data'))->keyBy('chiave');

    expect($rifiutata->status())->toBe(409)
        ->and($rifiutata->json('codice'))->toBe('personalizzazione_con_dati')
        ->and($mostra->json('data.installabile'))->toBeFalse()
        ->and($mostra->json('data.installata_il'))->toBeNull()
        ->and($funzioni['clienti']['dati_presenti'])->toBeTrue()
        ->and($funzioni['pipeline']['dati_presenti'])->toBeFalse()
        ->and($passi['primi_clienti']['stato'])->toBe('fatto')
        ->and($passi['primi_clienti']['fatto_il'])->toBe(now()->toImmutable()->startOfMillisecond()->format('Y-m-d\TH:i:s.v\Z'))
        ->and($passi['primo_prodotto']['stato'])->toBe('da_fare');

    // Un passo già fatto resta fatto: saltarlo è 200 senza scrivere.
    $salta = alFinto('PATCH', '/v1/crm/primi-passi/primi_clienti', ['stato' => 'saltato'], $gettoni['anna']);

    expect($salta->status())->toBe(200)->and($salta->json('data.stato'))->toBe('fatto');
});

it('le funzioni: nessuna cascata, le vincolate sono 409, la barra e i passi seguono quelle accese', function () {
    [, $gettoni] = studioColCrm();
    $modifica = fn (string $funzione, mixed $attivo, string $chi = 'anna') => alFinto('PATCH', "/v1/crm/funzioni/{$funzione}", ['attivo' => $attivo], $gettoni[$chi]);

    expect($modifica('catalogo', false)->json('codice'))->toBe('funzione_vincolata')        // preventivi e acquisti lo richiedono
        ->and($modifica('clienti', false)->json('codice'))->toBe('funzione_vincolata')      // sempre attiva
        ->and($modifica('documenti', true)->status())->toBe(200)                            // non richiede niente
        ->and($modifica('catalogo', true)->status())->toBe(200)                             // lo stesso valore: 200 senza scrivere
        ->and($modifica('clienti', true)->status())->toBe(200)                              // anche una sempre attiva: il valore uguale vince sul vincolo
        ->and($modifica('catalogo', false, 'carla')->json('codice'))->toBe('permesso_negato')
        ->and($modifica('magia', false)->status())->toBe(404)
        ->and(alFinto('PATCH', '/v1/crm/funzioni/magia', [], $gettoni['anna'])->status())->toBe(404)      // il 404 viene prima del corpo
        ->and(alFinto('PATCH', '/v1/crm/funzioni/catalogo', [], $gettoni['carla'])->json('codice'))->toBe('permesso_negato');   // il 403 viene prima del corpo

    $testo = $modifica('scadenze', 'false');

    expect($testo->status())->toBe(422)->and($testo->json('errors.0.pointer'))->toBe('#/attivo');

    foreach (['incassi', 'acquisti', 'preventivi', 'catalogo'] as $funzione) {
        expect($modifica($funzione, false)->json('data.attivo'))->toBeFalse();
    }

    expect($modifica('preventivi', true)->json('codice'))->toBe('funzione_vincolata');      // il catalogo è spento

    $mostra = alFinto('GET', '/v1/crm/personalizzazione', null, $gettoni['carla']);
    $passi = alFinto('GET', '/v1/crm/primi-passi', null, $gettoni['carla'])->json('data');

    expect($mostra->json('data.barra'))->toBe(['dashboard', 'potenziali_clienti', 'clienti', 'aziende', 'da_fare', 'scadenze', 'impostazioni'])
        ->and(array_column($passi, 'chiave'))->toBe(['primi_clienti', 'primo_potenziale_cliente', 'prima_attivita']);

    // Chi riaccende da sé, in ordine.
    expect($modifica('catalogo', true)->json('data.attivo'))->toBeTrue()
        ->and($modifica('preventivi', true)->json('data.attivo'))->toBeTrue()
        ->and(alFinto('GET', '/v1/crm/primi-passi', null, $gettoni['carla'])->json('data.0.chiave'))->toBe('primo_prodotto');
});

it('il vocabolario: una rinomina vince sul pack in tutte e tre le lingue, null la toglie, e gli errori sono tutti insieme e non scrivono niente', function () {
    [, $gettoni] = studioColCrm();
    $modifica = fn (array $corpo, string $chi = 'anna') => alFinto('PATCH', '/v1/crm/vocabolario', $corpo, $gettoni[$chi]);

    $fatta = $modifica(['barra.clienti' => ['it' => ' Soci ', 'en' => 'Members', 'es' => 'Socios']]);
    $soci = collect($fatta->json('data'))->firstWhere('chiave', 'barra.clienti');

    expect($fatta->status())->toBe(200)
        ->and($fatta->json('data'))->toHaveCount(78)
        ->and($soci)->toBe(['chiave' => crmValore('barra.clienti'), 'testo' => 'Soci', 'testi' => ['it' => 'Soci', 'en' => 'Members', 'es' => 'Socios'], 'personalizzato' => true]);

    // Il testo è nella lingua della persona del gettone (italiano), le tre lingue sono in `testi`; la rinomina la vede anche un membro.
    $letta = collect(alFinto('GET', '/v1/crm/vocabolario', null, $gettoni['carla'])->json('data'))->firstWhere('chiave', 'barra.clienti');

    expect($letta['testo'])->toBe('Soci')->and($letta['testi']['en'])->toBe('Members');

    // Una chiave buona accanto alle sbagliate: tutto o niente, non se ne scrive nessuna.
    $rifiutata = $modifica(['barra.catalogo' => ['it' => 'Prodotti', 'en' => 'Products', 'es' => 'Productos'], 'barra.aziende' => ['it' => 'Ditte', 'en' => str_repeat('x', 201)], 'barra.inesistente' => ['it' => 'x', 'en' => 'x', 'es' => 'x'], 'barra.da_fare' => ['it' => 'Fare', 'en' => 'Do', 'es' => 'Hacer', 'fr' => 'Faire']]);

    expect($rifiutata->status())->toBe(422)
        ->and(array_map(fn (array $errore) => [$errore['pointer'], $errore['regola']], $rifiutata->json('errors')))->toBe([
            ['#/barra.aziende/en', 'troppo_lungo'],
            ['#/barra.aziende/es', 'obbligatorio'],
            ['#/barra.inesistente', 'campo_estraneo'],
            ['#/barra.da_fare/fr', 'campo_estraneo'],
        ]);

    $dopo = collect(alFinto('GET', '/v1/crm/vocabolario', null, $gettoni['carla'])->json('data'));

    expect($dopo->firstWhere('chiave', 'barra.catalogo')['personalizzato'])->toBeFalse()
        ->and($dopo->firstWhere('chiave', 'barra.aziende')['personalizzato'])->toBeFalse()
        ->and($dopo->firstWhere('chiave', 'barra.da_fare')['personalizzato'])->toBeFalse();

    $tolta = $modifica(['barra.clienti' => null]);

    expect(collect($tolta->json('data'))->firstWhere('chiave', 'barra.clienti')['personalizzato'])->toBeFalse()
        ->and($modifica([])->json('errors.0.pointer'))->toBe('#')
        ->and($modifica(['barra.clienti' => null], 'carla')->json('codice'))->toBe('permesso_negato');
});

it('i primi passi: si salta e si riporta a da_fare, fatto non si scrive (422), un passo che non c\'è o con la funzione spenta è 404', function () {
    [, $gettoni] = studioColCrm();
    $modifica = fn (string $passo, array $corpo, string $chi = 'anna') => alFinto('PATCH', "/v1/crm/primi-passi/{$passo}", $corpo, $gettoni[$chi]);

    $salta = $modifica('primo_prodotto', ['stato' => 'saltato']);

    expect($salta->status())->toBe(200)
        ->and($salta->json('data'))->toBe(['chiave' => crmValore('primo_prodotto'), 'stato' => 'saltato', 'fatto_il' => null, 'azione' => 'catalogo.prima_voce'])
        ->and($modifica('primo_prodotto', ['stato' => 'da_fare'])->json('data.stato'))->toBe('da_fare');

    $fatto = $modifica('primo_prodotto', ['stato' => 'fatto']);

    expect($fatto->status())->toBe(422)
        ->and($fatto->json('errors.0.pointer'))->toBe('#/stato')
        ->and($modifica('primo_prodotto', ['stato' => 'saltato'], 'carla')->json('codice'))->toBe('permesso_negato')
        ->and($modifica('ultimo_passo', ['stato' => 'saltato'])->status())->toBe(404)
        ->and($modifica('ultimo_passo', [])->status())->toBe(404)                                    // il 404 viene prima del corpo
        ->and($modifica('primo_prodotto', [], 'carla')->json('codice'))->toBe('permesso_negato');    // il 403 viene prima del corpo

    foreach (['incassi', 'acquisti', 'preventivi', 'catalogo'] as $funzione) {
        alFinto('PATCH', "/v1/crm/funzioni/{$funzione}", ['attivo' => false], $gettoni['anna']);
    }

    expect($modifica('primo_prodotto', ['stato' => 'saltato'])->status())->toBe(404);
});

it('i testi seguono la lingua della persona del gettone: per una persona spagnola il pacchetto e il vocabolario escono in spagnolo', function () {
    $finto = BackofficeFinto::attiva();
    $eva = $finto->persona('eva@example.com', PASSWORD, 'Eva', lingua: 'es');
    $studio = $finto->workspace('Estudio', $eva);
    $finto->attivaApp($studio, 'crm');
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('eva@example.com')['gettone']['gettone'])->json('data.gettone');

    $pacchetto = alFinto('GET', '/v1/crm/personalizzazioni', null, $gettone)->json('data.0');
    $dashboard = collect(alFinto('GET', '/v1/crm/vocabolario', null, $gettone)->json('data'))->firstWhere('chiave', 'barra.dashboard');

    expect($pacchetto['nome'])->toBe('General')
        ->and($pacchetto['descrizione'])->toStartWith('El CRM tal cual')
        ->and($dashboard['testo'])->toBe('Panel')
        ->and($dashboard['testi']['it'])->toBe('Dashboard');
});
