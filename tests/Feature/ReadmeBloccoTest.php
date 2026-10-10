<?php

use Illuminate\Routing\Route as Rotta;
use Zeiras\Auth\Sessione;

// #1477 (T1.5): il README dice cosa fa il blocco della sessione, chi lo mette e a che prezzo; e ciò che nomina c'è.

function sezioneBlocco(): string
{
    $readme = file_get_contents(__DIR__.'/../../README.md');
    preg_match('/^## Il blocco della sessione[^\n]*\n(.*?)(?=^## )/ms', $readme, $trovata);

    return $trovata[1] ?? '';
}

test('il README ha la sezione del blocco della sessione', function () {
    expect(sezioneBlocco())->not->toBe('');
});

test('il README nomina la macro, il tetto, il 503 e dove sta il lock, e la macro c\'è', function () {
    $sezione = sezioneBlocco();

    expect($sezione)->toContain('->bloccaSessione()')
        ->toContain('block(10, 3)')
        ->toContain('3 secondi')
        ->toContain('503')
        ->toContain('Retry-After')
        ->toContain('session.block_store')
        ->toContain('Sessione::entra()')
        ->toContain('Sessione::chiudi()')
        ->toContain('rotte lente')
        ->and(Rotta::hasMacro('bloccaSessione'))->toBeTrue()
        ->and(Sessione::BLOCCO_TENUTA)->toBe(10)
        ->and(Sessione::BLOCCO_ATTESA)->toBe(3);
});
