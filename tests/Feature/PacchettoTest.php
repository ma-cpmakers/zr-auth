<?php

// T1.7 (Z6, Z7): niente tabelle né migration, e OpenID Connect se n'è andato.

function fileDelRepo(): array
{
    $radice = dirname(__DIR__, 2);

    return array_values(array_filter(explode("\n", (string) shell_exec('git -C '.escapeshellarg($radice).' ls-files'))));
}

it('non ha migration né una cartella database', function () {
    $file = fileDelRepo();

    expect($file)->not->toBeEmpty()
        ->and(array_values(array_filter($file, fn (string $f) => preg_match('#(^|/)(database|migrations)/#', $f) === 1)))->toBe([]);
});

it('nei sorgenti nessuno Schema, nessun modello e niente OpenID Connect', function () {
    $radice = dirname(__DIR__, 2);
    $sorgenti = array_filter(fileDelRepo(), fn (string $f) => str_starts_with($f, 'src/') || str_starts_with($f, 'config/'));
    $testo = implode("\n", array_map(fn (string $f) => (string) file_get_contents("{$radice}/{$f}"), $sorgenti));

    // Il nome dove comincia una parola, non un pezzo di un'altra: `invalid_token` (RFC 6750, nel WWW-Authenticate di un
    // 401 del backoffice finto) non è un id_token di OpenID Connect.
    foreach (['Schema::', 'Eloquent\\Model', 'id_token', 'jwks', 'zr_persone', 'zr_revoche', 'Firebase\\JWT'] as $vietato) {
        expect(preg_match('/(?<![A-Za-z0-9_])'.preg_quote($vietato, '/').'/', $testo))->toBe(0, "Nei sorgenti c'è {$vietato}.");
    }
});

it('non dipende più da firebase/php-jwt', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect($composer['require'])->not->toHaveKey('firebase/php-jwt');
});
