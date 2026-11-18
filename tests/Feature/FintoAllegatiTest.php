<?php

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Zeiras\Auth\Testing\BackofficeFinto;

// #2793 (zr-board): i file di una scheda nel finto. Qui ciò che un frontend vede dal suo lato (le forme, la scansione, i 409 e i 422, la
// visibilità dei riservati); che risposte ed errori siano quelli del backoffice lo prova il FintoTest del backoffice.

const PNG_FINTO = "\x89PNG\r\n\x1a\n";

/**
 * Un workspace con `pm`, una fase, la proprietaria e un membro (di serie con l'antivirus: i file nascono `in_scansione`; senza è come in produzione): [finto, workspace, fase, gettone della proprietaria, gettone del membro].
 *
 * @return array{BackofficeFinto, array<string, mixed>, array<string, mixed>, string, string}
 */
function conUnaSchedaDaAllegare(bool $antivirus = true): array
{
    $finto = BackofficeFinto::attiva();
    $antivirus && $finto->conAntivirus();
    $anna = $finto->persona('anna@example.com', PASSWORD, 'Anna Bianchi');
    $luca = $finto->persona('luca@example.com', PASSWORD, 'Luca Verdi');
    $studio = $finto->workspace('Studio', $anna);
    $finto->membro($studio, $luca, 'membro');
    $finto->attivaApp($studio, 'pm', 'crm');
    $fase = $finto->scheda($studio, 'Una fase', tipo: 'fase');
    $gettoneDi = fn (string $email) => alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto($email)['gettone']['gettone'])->json('data.gettone');

    return [$finto, $studio, $fase, $gettoneDi('anna@example.com'), $gettoneDi('luca@example.com')];
}

/** Carica un file in una scheda come multipart, col campo `file` e i campi di testo. @param  array<string, string>  $campi */
function caricaNelFinto(string $gettone, string $scheda, string $byte, string $nome, array $campi = [], array $intestazioni = []): Response
{
    $richiesta = Http::baseUrl(API)->acceptJson()->withToken($gettone)->withHeaders($intestazioni)->attach('file', $byte, $nome);

    return $richiesta->post("/v1/board/schede/{$scheda}/allegati", $campi);
}

it('un file nasce in_scansione con 201, compare nell\'elenco e in n_allegati, e si scarica solo da pulito', function () {
    [$finto, , $fase, $gettone] = conUnaSchedaDaAllegare();

    $nuovo = caricaNelFinto($gettone, $fase['id'], "%PDF-1.4\n...", 'relazione.pdf');
    $id = $nuovo->json('data.id');
    $elenco = alFinto('GET', "/v1/board/schede/{$fase['id']}/allegati", null, $gettone);
    $prima = alFinto('GET', "/v1/board/schede/{$fase['id']}/allegati/{$id}/contenuto", null, $gettone);
    $finto->scansiona($id);
    $dopo = alFinto('GET', "/v1/board/schede/{$fase['id']}/allegati/{$id}/contenuto", null, $gettone);

    expect([$nuovo->status(), $nuovo->json('data.nome'), $nuovo->json('data.mime'), $nuovo->json('data.stato'), $nuovo->json('data.visibilita'), $nuovo->json('data.autore.nome')])
        ->toBe([201, 'relazione.pdf', 'application/pdf', 'in_scansione', 'workspace', 'Anna Bianchi'])
        ->and(array_keys($nuovo->json('data')))->toBe(['id', 'nome', 'dimensione', 'mime', 'autore', 'creato_il', 'stato', 'visibilita'])
        ->and([array_column($elenco->json('data'), 'id'), $elenco->json('successivo')])->toBe([[$id], null])
        ->and(alFinto('GET', "/v1/board/schede/{$fase['id']}", null, $gettone)->json('data.n_allegati'))->toBe(1)
        ->and([$prima->status(), $prima->json('codice')])->toBe([409, 'scansione_in_corso'])
        ->and([$dopo->status(), $dopo->body(), $dopo->header('Content-Type'), $dopo->header('Content-Disposition'), $dopo->header('Cache-Control'), $dopo->header('X-Content-Type-Options')])
        ->toBe([200, "%PDF-1.4\n...", 'application/octet-stream', 'attachment; filename="relazione.pdf"', 'no-store, private', 'nosniff']);
});

it('un PNG pulito va in linea col suo tipo; un infetto non si scarica mai', function () {
    [$finto, , $fase, $gettone] = conUnaSchedaDaAllegare();

    $immagine = caricaNelFinto($gettone, $fase['id'], PNG_FINTO.'xxxx', 'logo.png')->json('data.id');
    $virus = caricaNelFinto($gettone, $fase['id'], 'un file qualsiasi', 'strano.txt')->json('data.id');
    $finto->scansiona($immagine)->scansiona($virus, 'infetto');
    $inLinea = alFinto('GET', "/v1/board/schede/{$fase['id']}/allegati/{$immagine}/contenuto", null, $gettone);
    $infetto = alFinto('GET', "/v1/board/schede/{$fase['id']}/allegati/{$virus}/contenuto", null, $gettone);

    expect([$inLinea->header('Content-Type'), $inLinea->header('Content-Disposition')])->toBe(['image/png', 'inline; filename="logo.png"'])
        ->and([$infetto->status(), $infetto->json('codice')])->toBe([409, 'infetto']);
});

it('il tipo lo dicono i byte: un eseguibile chiamato .pdf è 422 su #/file, un PDF chiamato .exe passa; senza file o con un campo in più è 422', function () {
    [, , $fase, $gettone] = conUnaSchedaDaAllegare();

    $eseguibile = caricaNelFinto($gettone, $fase['id'], "\x7fELF....", 'relazione.pdf');
    $script = caricaNelFinto($gettone, $fase['id'], "#!/bin/sh\nrm -rf /", 'nota.txt');
    $pdf = caricaNelFinto($gettone, $fase['id'], "%PDF-1.4\n", 'relazione.exe');
    $senza = alFinto('POST', "/v1/board/schede/{$fase['id']}/allegati", [], $gettone);
    $inPiu = caricaNelFinto($gettone, $fase['id'], "%PDF-1.4\n", 'a.pdf', ['colore' => 'rosso']);
    $sbagliata = caricaNelFinto($gettone, $fase['id'], "%PDF-1.4\n", 'a.pdf', ['visibilita' => 'pubblico']);

    expect([$eseguibile->status(), $eseguibile->json('codice'), $eseguibile->json('errors.0.pointer')])->toBe([422, 'dati_non_validi', '#/file'])
        ->and([$script->status(), $script->json('errors.0.pointer')])->toBe([422, '#/file'])
        ->and([$pdf->status(), $pdf->json('data.mime')])->toBe([201, 'application/pdf'])
        ->and($senza->json('errors.0.pointer'))->toBe('#/file')
        ->and([$inPiu->status(), $inPiu->json('errors.0.pointer')])->toBe([422, '#/colore'])
        ->and([$sbagliata->status(), $sbagliata->json('errors.0.pointer')])->toBe([422, '#/visibilita'])
        ->and($senza->status())->toBe(422);
});

it('più grande di 25 MB è 413 file_troppo_grande; la 51ª file di una scheda è 409 limite_raggiunto', function () {
    [, , $fase, $gettone] = conUnaSchedaDaAllegare();

    $grande = caricaNelFinto($gettone, $fase['id'], "%PDF-1.4\n".str_repeat('a', 25 * 1024 * 1024), 'grande.pdf');

    foreach (range(1, 50) as $numero) {
        expect(caricaNelFinto($gettone, $fase['id'], "%PDF-1.4\n{$numero}", "f{$numero}.pdf")->status())->toBe(201);
    }

    $oltre = caricaNelFinto($gettone, $fase['id'], "%PDF-1.4\n", 'uno-di-troppo.pdf');

    expect([$grande->status(), $grande->json('codice')])->toBe([413, 'file_troppo_grande'])
        ->and([$oltre->status(), $oltre->json('codice')])->toBe([409, 'limite_raggiunto'])->and($oltre->json('limite'))->toBe(50);
});

it('con conSpazioDegliAllegatiPieno() il caricamento che supera il tetto è 409 limite_raggiunto col limite vero della quota (1 GiB); di serie il finto non ha quota (#2793)', function () {
    [$finto, , $fase, $gettone] = conUnaSchedaDaAllegare();

    $primo = caricaNelFinto($gettone, $fase['id'], "%PDF-1.4\nuno", 'a.pdf');
    $finto->conSpazioDegliAllegatiPieno(20);
    $secondo = caricaNelFinto($gettone, $fase['id'], "%PDF-1.4\n".str_repeat('b', 30), 'b.pdf');

    expect($primo->status())->toBe(201)
        ->and([$secondo->status(), $secondo->json('codice')])->toBe([409, 'limite_raggiunto'])
        ->and($secondo->json('limite'))->toBe(1073741824);
});

it('un riservato lo vedono solo l\'autore, il proprietario e gli amministratori: per un membro è assente dall\'elenco e da n_allegati, e il contenuto è 404', function () {
    [$finto, , $fase, $anna, $luca] = conUnaSchedaDaAllegare();

    $segreto = caricaNelFinto($luca, $fase['id'], "%PDF-1.4\n", 'busta.pdf', ['visibilita' => 'riservato'])->json('data.id');
    $aperto = caricaNelFinto($anna, $fase['id'], "%PDF-1.4\n", 'piano.pdf')->json('data.id');
    $finto->scansionaTutti();

    expect(array_column(alFinto('GET', "/v1/board/schede/{$fase['id']}/allegati", null, $luca)->json('data'), 'id'))->toBe([$segreto, $aperto])
        ->and(array_column(alFinto('GET', "/v1/board/schede/{$fase['id']}/allegati", null, $anna)->json('data'), 'id'))->toBe([$segreto, $aperto]);

    $riservatoDiAnna = caricaNelFinto($anna, $fase['id'], "%PDF-1.4\n", 'conti.pdf', ['visibilita' => 'riservato'])->json('data.id');
    $finto->scansionaTutti();

    expect(array_column(alFinto('GET', "/v1/board/schede/{$fase['id']}/allegati", null, $luca)->json('data'), 'id'))->toBe([$segreto, $aperto])
        ->and(alFinto('GET', "/v1/board/schede/{$fase['id']}", null, $luca)->json('data.n_allegati'))->toBe(2)
        ->and(alFinto('GET', "/v1/board/schede/{$fase['id']}", null, $anna)->json('data.n_allegati'))->toBe(3)
        ->and(alFinto('GET', "/v1/board/schede/{$fase['id']}/allegati/{$riservatoDiAnna}/contenuto", null, $luca)->status())->toBe(404)
        ->and(alFinto('DELETE', "/v1/board/schede/{$fase['id']}/allegati/{$riservatoDiAnna}", null, $luca)->status())->toBe(404)
        ->and(alFinto('GET', "/v1/board/schede/{$fase['id']}/allegati/{$segreto}/contenuto", null, $anna)->status())->toBe(200);
});

it('elimina toglie il file (204): 404 dopo, e n_allegati scende', function () {
    [$finto, , $fase, $gettone] = conUnaSchedaDaAllegare();

    $id = caricaNelFinto($gettone, $fase['id'], "%PDF-1.4\n", 'a.pdf')->json('data.id');
    $finto->scansiona($id);

    expect(alFinto('DELETE', "/v1/board/schede/{$fase['id']}/allegati/{$id}", null, $gettone)->status())->toBe(204)
        ->and(alFinto('GET', "/v1/board/schede/{$fase['id']}/allegati", null, $gettone)->json('data'))->toBe([])
        ->and(alFinto('GET', "/v1/board/schede/{$fase['id']}", null, $gettone)->json('data.n_allegati'))->toBe(0)
        ->and(alFinto('GET', "/v1/board/schede/{$fase['id']}/allegati/{$id}/contenuto", null, $gettone)->status())->toBe(404)
        ->and(alFinto('DELETE', "/v1/board/schede/{$fase['id']}/allegati/{$id}", null, $gettone)->status())->toBe(404);
});

it('la Idempotency-Key ripetuta con lo stesso file non ne carica un secondo; con un altro file è 422 chiave_idempotenza_riusata', function () {
    [, , $fase, $gettone] = conUnaSchedaDaAllegare();
    $carico = 'carico-1';
    $ripetibile = ['Idempotency-Key' => $carico];

    $primo = caricaNelFinto($gettone, $fase['id'], "%PDF-1.4\n", 'a.pdf', [], $ripetibile);
    $ripetuto = caricaNelFinto($gettone, $fase['id'], "%PDF-1.4\n", 'a.pdf', [], $ripetibile);
    $altro = caricaNelFinto($gettone, $fase['id'], "%PDF-1.4\nb", 'a.pdf', [], $ripetibile);

    expect([$primo->status(), $ripetuto->status(), $ripetuto->json('data.id')])->toBe([201, 201, $primo->json('data.id')])
        ->and(count(alFinto('GET', "/v1/board/schede/{$fase['id']}/allegati", null, $gettone)->json('data')))->toBe(1)
        ->and([$altro->status(), $altro->json('codice')])->toBe([422, 'chiave_idempotenza_riusata']);
});

it('senza antivirus, come in produzione, un file nasce non_scansionato e si scarica subito; la scansione non si chiude su un file che nessuno scansiona', function () {
    [$finto, , $fase, $gettone] = conUnaSchedaDaAllegare(antivirus: false);

    $nuovo = caricaNelFinto($gettone, $fase['id'], "%PDF-1.4\n...", 'relazione.pdf');
    $id = $nuovo->json('data.id');
    $contenuto = alFinto('GET', "/v1/board/schede/{$fase['id']}/allegati/{$id}/contenuto", null, $gettone);
    $elenco = alFinto('GET', "/v1/board/schede/{$fase['id']}/allegati", null, $gettone);

    expect([$nuovo->status(), $nuovo->json('data.stato')])->toBe([201, 'non_scansionato'])
        ->and([$contenuto->status(), $contenuto->body()])->toBe([200, "%PDF-1.4\n..."])
        ->and(array_column($elenco->json('data'), 'stato'))->toBe(['non_scansionato'])
        ->and(fn () => $finto->scansiona($id))->toThrow(InvalidArgumentException::class, 'non si scansiona');
});
