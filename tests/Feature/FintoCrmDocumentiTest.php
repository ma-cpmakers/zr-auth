<?php

// #2923 #2924 #2927 (CRM-10): i modelli, i documenti firmati e il link da firmare, nel finto. Qui ciò che un frontend vede dal suo lato, e il ramo del
// contatto minorenne, che dall'API di oggi non si raggiunge (`data_nascita` non è un campo che `crm.persone.*` scrive): il test scrive il valore nel finto.
// Che le risposte e gli errori siano quelli del backoffice lo prova il suo FintoTest.

/** @return array<string, string> l'intestazione di una creazione (la guardia dei segreti non legge un valore che non è fra virgolette) */
function chiaveDiCreazione(string $valore): array
{
    return ['Idempotency-Key' => $valore];
}

/** @return array<string, mixed> il corpo di una firma in presenza */
function firmaDelFinto(string $persona, array $in = []): array
{
    return $in + ['persona_id' => $persona, 'modello' => 'consenso_immagini', 'versione' => 2, 'lingua' => 'it', 'firmatario' => ['nome' => 'Giulia', 'cognome' => 'Bianchi', 'legame' => 'se_stesso'], 'tratto' => ['tipo' => 'spunta']];
}

it('un contatto minorenne si firma da un genitore o da un tutore; la data di nascita è il valore del campo data_nascita (Documenti::minorenne)', function () {
    [$finto, $gettoni] = studioColCrm();
    $minorenne = alFinto('POST', '/v1/crm/persone', ['nome' => 'Pia', 'cognome' => 'Verdi'], $gettoni['anna'])->json('data.id');
    $maggiorenne = alFinto('POST', '/v1/crm/persone', ['nome' => 'Mario', 'cognome' => 'Rossi'], $gettoni['anna'])->json('data.id');
    $scrivi = function (string $persona, string $nascita): void {
        $this->crm['valori']['persona'][$persona]['data_nascita'] = $nascita;
    };
    $adesso = now('Europe/Rome');
    Closure::bind($scrivi, $finto, $finto::class)($minorenne, $adesso->copy()->subYears(17)->format('Y-m-d'));
    Closure::bind($scrivi, $finto, $finto::class)($maggiorenne, $adesso->copy()->subYears(18)->format('Y-m-d'));
    $firma = fn (string $persona, string $legame) => alFinto('POST', '/v1/crm/documenti', firmaDelFinto($persona, ['firmatario' => ['nome' => 'Mara', 'cognome' => 'Verdi', 'legame' => $legame]]), $gettoni['anna'], intestazioni: chiaveDiCreazione("k-{$persona}-{$legame}"));

    $rifiutata = $firma($minorenne, 'se_stesso');

    expect($rifiutata->status())->toBe(422)
        ->and($rifiutata->json('errors.0.pointer'))->toBe('#/firmatario/legame')
        ->and($firma($minorenne, 'altro')->status())->toBe(422)
        ->and($firma($minorenne, 'genitore')->status())->toBe(201)
        ->and($firma($minorenne, 'tutore')->status())->toBe(201)
        ->and($firma($maggiorenne, 'se_stesso')->status())->toBe(201);
});

it('il testo del documento lo compila il finto, dal modello e dal contatto: i quattro segnaposto e solo quelli, e la versione corrente', function () {
    [, $gettoni, $studio] = studioColCrm();
    $persona = alFinto('POST', '/v1/crm/persone', ['nome' => 'Giulia', 'cognome' => 'Bianchi'], $gettoni['anna'])->json('data.id');

    $modello = alFinto('GET', "/v1/crm/modelli/iscrizione?persona_id={$persona}", null, $gettoni['carla'])->json('data');
    $senza = alFinto('GET', '/v1/crm/modelli/iscrizione', null, $gettoni['carla'])->json('data');

    expect($modello['testo'])->toContain('Giulia Bianchi')->toContain($studio['nome'])->not->toContain('{{')
        ->and($senza['testo'])->toContain('{{nome}} {{cognome}}')->toContain('{{palestra}}')
        ->and($modello['impronta'])->toBe(hash('sha256', $modello['testo']))
        ->and(array_keys($modello))->toBe(['id', 'chiave', 'nome', 'versione', 'revocabile', 'lingua', 'testo', 'impronta'])
        ->and(alFinto('GET', '/v1/crm/modelli/iscrizione?versione=3', null, $gettoni['carla'])->status())->toBe(404);

    $firmato = alFinto('POST', '/v1/crm/documenti', firmaDelFinto($persona, ['modello' => 'iscrizione']), $gettoni['carla'], intestazioni: chiaveDiCreazione('k-1'));

    expect($firmato->status())->toBe(201)
        ->and($firmato->header('Location'))->toBe('')
        ->and(array_keys($firmato->json('data')))->toBe(['id', 'stato', 'firmato_il', 'firmato_da', 'versione', 'impronta'])
        ->and($firmato->json('data.impronta'))->toBe(hash('sha256', alFinto('GET', "/v1/crm/modelli/iscrizione?persona_id={$persona}", null, $gettoni['carla'])->json('data.testo')));
});

it('il membro vede un documento ridotto, e il PDF è del proprietario e degli amministratori', function () {
    [, $gettoni] = studioColCrm();
    $persona = alFinto('POST', '/v1/crm/persone', ['nome' => 'Giulia'], $gettoni['anna'])->json('data.id');
    $documento = alFinto('POST', '/v1/crm/documenti', firmaDelFinto($persona), $gettoni['carla'], intestazioni: chiaveDiCreazione('k-1'))->json('data.id');

    $intero = alFinto('GET', "/v1/crm/documenti?persona_id={$persona}", null, $gettoni['anna'])->json('data.0');
    $ridotto = alFinto('GET', "/v1/crm/documenti?persona_id={$persona}", null, $gettoni['carla'])->json('data.0');
    $pdf = alFinto('GET', "/v1/crm/documenti/{$documento}/pdf", null, $gettoni['bruno']);

    expect(array_keys($intero))->toContain('firmatario', 'impronta', 'firmato_da', 'lingua', 'versione')
        ->and(array_keys($ridotto))->toBe(['id', 'modello', 'stato', 'modo', 'firmato_il', 'revocato_il'])
        ->and(alFinto('GET', "/v1/crm/documenti/{$documento}/pdf", null, $gettoni['carla'])->json('codice'))->toBe('permesso_negato')
        ->and($pdf->status())->toBe(200)
        ->and($pdf->header('Content-Type'))->toBe('application/pdf')
        ->and($pdf->header('Content-Disposition'))->toStartWith('attachment; filename=consenso_immagini-v2-')
        ->and($pdf->body())->toStartWith('%PDF-');
});

it('un contatto con un documento firmato non si unisce: contatto_non_unibile con blocchi [documenti], come nel backoffice', function () {
    [$gettoni, $resta, $unito] = duePersoneDoppie();
    alFinto('POST', '/v1/crm/documenti', firmaDelFinto($unito), $gettoni['anna'], intestazioni: chiaveDiCreazione('k-1'));

    $unione = alFinto('POST', '/v1/crm/persone/unioni', corpoDellUnione($gettoni, $resta, $unito), $gettoni['anna']);

    expect($unione->status())->toBe(409)
        ->and($unione->json('codice'))->toBe('contatto_non_unibile')
        ->and($unione->json('blocchi'))->toBe(['documenti']);
});

it('un tratto con un a capo nel base64, più largo di 1000 pixel o con i dati troncati è 422 su #/tratto/immagine', function () {
    [, $gettoni] = studioColCrm();
    $persona = alFinto('POST', '/v1/crm/persone', ['nome' => 'Giulia'], $gettoni['anna'])->json('data.id');
    $png = function (int $larghezza, int $altezza): string {
        $immagine = imagecreatetruecolor($larghezza, $altezza);
        ob_start();
        imagepng($immagine);

        return (string) ob_get_clean();
    };
    $rumore = imagecreatetruecolor(80, 80);
    foreach (range(0, 79) as $y) {
        foreach (range(0, 79) as $x) {
            imagesetpixel($rumore, $x, $y, random_int(0, 0xFFFFFF));
        }
    }
    ob_start();
    imagepng($rumore);
    $intero = (string) ob_get_clean();
    $provaCon = fn (string $immagine) => alFinto('POST', '/v1/crm/documenti', firmaDelFinto($persona, ['tratto' => ['tipo' => 'immagine', 'immagine' => $immagine]]), $gettoni['anna'], intestazioni: chiaveDiCreazione('k-'.md5($immagine)));
    $valido = base64_encode($png(10, 10));

    foreach ([substr($valido, 0, 12)."\n".substr($valido, 12), base64_encode($png(1001, 1)), base64_encode(substr($intero, 0, (int) (strlen($intero) * 0.6)))] as $storto) {
        $risposta = $provaCon($storto);

        expect($risposta->status())->toBe(422)->and($risposta->json('errors.0.pointer'))->toBe('#/tratto/immagine');
    }

    expect($provaCon($valido)->status())->toBe(201);
});

it('dopo l\'oblio del contatto il documento non ha più firmatario né impronta (null), e restano modello, versione, stato e data', function () {
    [, $gettoni] = studioColCrm();
    $persona = alFinto('POST', '/v1/crm/persone', ['nome' => 'Giulia', 'cognome' => 'Bianchi'], $gettoni['anna'])->json('data.id');
    alFinto('POST', '/v1/crm/documenti', firmaDelFinto($persona), $gettoni['anna'], intestazioni: chiaveDiCreazione('k-1'));

    $oblio = alFinto('POST', "/v1/crm/persone/{$persona}/anonimizzazione", null, $gettoni['anna']);
    $elenco = alFinto('GET', "/v1/crm/documenti?persona_id={$persona}", null, $gettoni['anna']);

    expect($oblio->status())->toBeIn([200, 201, 202, 204])
        ->and($elenco->status())->toBe(200)
        ->and($elenco->json('data.0'))->toMatchArray(['modello' => 'consenso_immagini', 'versione' => 2, 'stato' => 'firmato', 'impronta' => null])
        ->and($elenco->json('data.0.firmatario'))->toMatchArray(['nome' => '', 'cognome' => '']);
});

it('la firma legata al testo letto (#3039): l\'impronta uguale a quella di testo.crea è 201, un\'altra è 409 impronta_non_corrispondente senza documento, una malformata è 422', function () {
    [, $gettoni] = studioColCrm();
    $persona = alFinto('POST', '/v1/crm/persone', ['nome' => 'Pia', 'cognome' => 'Verdi'], $gettoni['anna'])->json('data.id');
    $genitore = ['nome' => 'Mara', 'cognome' => 'Verdi', 'legame' => 'genitore'];
    $letta = alFinto('POST', '/v1/crm/modelli/consenso_immagini/testo', ['persona_id' => $persona, 'lingua' => 'it', 'firmatario' => $genitore], $gettoni['anna'])->json('data.impronta');
    $senza = alFinto('POST', '/v1/crm/modelli/consenso_immagini/testo', ['persona_id' => $persona, 'lingua' => 'it', 'firmatario' => ['nome' => 'Pia', 'cognome' => 'Verdi', 'legame' => 'se_stesso']], $gettoni['anna'])->json('data.impronta');
    $firma = fn (string $impronta, string $chiave) => alFinto('POST', '/v1/crm/documenti', firmaDelFinto($persona, ['firmatario' => $genitore, 'impronta' => $impronta]), $gettoni['anna'], intestazioni: chiaveDiCreazione($chiave));

    $diversa = $firma($senza, 'k-fl-1');
    $malformata = $firma(strtoupper($letta), 'k-fl-2');
    $uguale = $firma($letta, 'k-fl-3');

    expect($diversa->status())->toBe(409)->and($diversa->json('codice'))->toBe('impronta_non_corrispondente')
        ->and($malformata->status())->toBe(422)->and($malformata->json('errors.0.pointer'))->toBe('#/impronta')
        ->and($uguale->status())->toBe(201)->and($uguale->json('data.impronta'))->toBe($letta)
        ->and(alFinto('GET', '/v1/crm/documenti?persona_id='.$persona, null, $gettoni['anna'])->json('data'))->toHaveCount(1);
});

it('nome e cognome di chi firma (#3040): al più 80 caratteri, solo lettere, spazi, apostrofo, trattino e punto; una virgola, una cifra o 81 caratteri sono 422 sul campo', function (string $campo, string $valore, int $stato) {
    [, $gettoni] = studioColCrm();
    $persona = alFinto('POST', '/v1/crm/persone', ['nome' => 'Pia', 'cognome' => 'Verdi'], $gettoni['anna'])->json('data.id');
    $firmatario = ['nome' => 'Mara', 'cognome' => 'Verdi', 'legame' => 'se_stesso'];
    $firmatario[$campo] = $valore;

    $r = alFinto('POST', '/v1/crm/documenti', firmaDelFinto($persona, ['firmatario' => $firmatario]), $gettoni['anna'], intestazioni: chiaveDiCreazione('k-nm-'.md5($campo.$valore)));

    expect($r->status())->toBe($stato);

    if ($stato === 422) {
        expect($r->json('errors.0.pointer'))->toBe("#/firmatario/{$campo}");
    }
})->with([
    'virgola' => ['nome', 'Mara, che', 422], 'cifra' => ['cognome', 'Verdi2', 422], 'ottantuno' => ['cognome', str_repeat('b', 81), 422], 'graffa' => ['nome', 'Ma{x}', 422],
    'apostrofo' => ['cognome', "D'Angelo", 201], 'trattino' => ['cognome', 'De-Luca', 201], 'accenti' => ['nome', 'José María', 201], 'ottanta' => ['cognome', str_repeat('é', 80), 201],
]);
