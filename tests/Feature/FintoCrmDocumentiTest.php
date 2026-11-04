<?php

// #2923 #2924 #2927 (CRM-10): i modelli, i documenti firmati e il link da firmare, nel finto. Qui ciò che un frontend vede dal suo lato, e il ramo del
// contatto minorenne, che dall'API di oggi non si raggiunge (`data_nascita` non è un campo che `crm.persone.*` scrive): il test scrive il valore nel finto.
// Che le risposte e gli errori siano quelli del backoffice lo prova il suo FintoTest.

/** @return array<string, mixed> il corpo di una firma in presenza */
function firmaDelFinto(string $persona, array $in = []): array
{
    return $in + ['persona_id' => $persona, 'modello' => 'consenso_immagini', 'versione' => 1, 'lingua' => 'it', 'firmatario' => ['nome' => 'Giulia', 'cognome' => 'Bianchi', 'legame' => 'se_stesso'], 'tratto' => ['tipo' => 'spunta']];
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
    $firma = fn (string $persona, string $legame) => alFinto('POST', '/v1/crm/documenti', firmaDelFinto($persona, ['firmatario' => ['nome' => 'Mara', 'cognome' => 'Verdi', 'legame' => $legame]]), $gettoni['anna'], intestazioni: ['Idempotency-Key' => "k-{$persona}-{$legame}"]);

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
        ->and(alFinto('GET', '/v1/crm/modelli/iscrizione?versione=2', null, $gettoni['carla'])->status())->toBe(404);

    $firmato = alFinto('POST', '/v1/crm/documenti', firmaDelFinto($persona, ['modello' => 'iscrizione']), $gettoni['carla'], intestazioni: ['Idempotency-Key' => 'k-1']);

    expect($firmato->status())->toBe(201)
        ->and($firmato->header('Location'))->toBe('')
        ->and(array_keys($firmato->json('data')))->toBe(['id', 'stato', 'firmato_il', 'firmato_da', 'versione', 'impronta'])
        ->and($firmato->json('data.impronta'))->toBe(hash('sha256', alFinto('GET', "/v1/crm/modelli/iscrizione?persona_id={$persona}", null, $gettoni['carla'])->json('data.testo')));
});

it('il membro vede un documento ridotto, e il PDF è del proprietario e degli amministratori', function () {
    [, $gettoni] = studioColCrm();
    $persona = alFinto('POST', '/v1/crm/persone', ['nome' => 'Giulia'], $gettoni['anna'])->json('data.id');
    $documento = alFinto('POST', '/v1/crm/documenti', firmaDelFinto($persona), $gettoni['carla'], intestazioni: ['Idempotency-Key' => 'k-1'])->json('data.id');

    $intero = alFinto('GET', "/v1/crm/documenti?persona_id={$persona}", null, $gettoni['anna'])->json('data.0');
    $ridotto = alFinto('GET', "/v1/crm/documenti?persona_id={$persona}", null, $gettoni['carla'])->json('data.0');
    $pdf = alFinto('GET', "/v1/crm/documenti/{$documento}/pdf", null, $gettoni['bruno']);

    expect(array_keys($intero))->toContain('firmatario', 'impronta', 'firmato_da', 'lingua', 'versione')
        ->and(array_keys($ridotto))->toBe(['id', 'modello', 'stato', 'modo', 'firmato_il', 'revocato_il'])
        ->and(alFinto('GET', "/v1/crm/documenti/{$documento}/pdf", null, $gettoni['carla'])->json('codice'))->toBe('permesso_negato')
        ->and($pdf->status())->toBe(200)
        ->and($pdf->header('Content-Type'))->toBe('application/pdf')
        ->and($pdf->header('Content-Disposition'))->toStartWith('attachment; filename=consenso_immagini-v1-')
        ->and($pdf->body())->toStartWith('%PDF-');
});
