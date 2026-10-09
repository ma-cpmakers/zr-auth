<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// RV5 (#1368): il README non nomina ciò che non esiste. La sezione degli eventi si legge, e ogni simbolo che nomina (le
// chiavi di config('zr-auth.eventi'), i metodi del finto, le variabili) c'è davvero; e dice i doveri di chi riceve.

function sezioneEventi(): string
{
    $readme = file_get_contents(__DIR__.'/../../README.md');
    preg_match('/^## Gli eventi del backoffice: il ricevitore\n(.*?)(?=^## )/ms', $readme, $trovata);

    return $trovata[1] ?? '';
}

test('il README ha la sezione degli eventi', function () {
    expect(sezioneEventi())->not->toBe('');
});

test('le chiavi di zr-auth.eventi che il README nomina ci sono nella config, e quelle della config ci sono nel README', function () {
    $sezione = sezioneEventi();
    $config = array_keys(config('zr-auth.eventi'));

    // Le chiavi dell'esempio di config, meno quella che le contiene.
    preg_match_all("/^\s+'([a-z_]+)' =>/m", $sezione, $nominate);
    $nominate = array_diff($nominate[1], ['eventi']);
    expect($nominate)->not->toBeEmpty();

    foreach ($nominate as $chiave) {
        expect($config)->toContain($chiave);
    }
    foreach ($config as $chiave) {
        expect($sezione)->toContain("'{$chiave}'");
    }

    preg_match_all('/`zr-auth\.eventi\.([a-z_]+)`/', $sezione, $punteggiate);
    foreach ($punteggiate[1] as $chiave) {
        expect($config)->toContain($chiave);
    }
});

test('i metodi del finto che il README nomina esistono', function () {
    $readme = file_get_contents(__DIR__.'/../../README.md');

    preg_match_all('/`(?:BackofficeFinto::)?([a-zA-Z]+)\(/', sezioneEventi(), $nominati);
    $metodi = array_intersect($nominati[1], ['consegna', 'attiva', 'persona', 'workspace', 'membro']);
    expect($metodi)->toContain('consegna');

    foreach ($metodi as $metodo) {
        expect(method_exists(BackofficeFinto::class, $metodo))->toBeTrue("il README nomina {$metodo}()");
    }
    expect($readme)->toContain('BackofficeFinto::consegna(');
});

test('le variabili d’ambiente del README sono quelle della config', function () {
    $config = file_get_contents(__DIR__.'/../../config/zr-auth.php');

    preg_match_all('/\bZR_[A-Z_]+\b/', sezioneEventi(), $variabili);
    expect($variabili[0])->not->toBeEmpty();

    foreach (array_unique($variabili[0]) as $variabile) {
        expect($config)->toContain("env('{$variabile}')");
    }
});

test('la sezione dice i doveri di chi riceve', function () {
    $sezione = sezioneEventi();

    foreach (['La firma prima di tutto', '204', 'ShouldQueue', 'webhook-id', 'doppioni', 'sequence', 'GET /v1/eventi?dopo=', '410', 'eventi.ultimo.mostra', 'eliminata', 'CSRF', 'percorso', 'ZR_EVENTI_SEGRETO', '`api`', 'LogicException'] as $voce) {
        expect($sezione)->toContain($voce);
    }
});
