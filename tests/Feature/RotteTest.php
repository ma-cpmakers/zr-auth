<?php

use Zeiras\Auth\Testing\Rotte;

// T1.6 (Z4, prova 11): una rotta senza la guardia manda in rosso la CI del frontend.

it('dà le rotte senza guardia: fuori dai gruppi e tolte con withoutMiddleware, salvo GET up', function () {
    $scoperte = Rotte::senzaGuardia();

    expect($scoperte)->toContain('GET fuori', 'GET pubblica', 'POST entra')
        ->not->toContain('GET up', 'GET pagina', 'GET io');
});

it('le pagine pubbliche nominate dal frontend escono dall\'elenco, le altre no', function () {
    $scoperte = Rotte::senzaGuardia(['GET pubblica', 'POST entra']);

    expect($scoperte)->toContain('GET fuori')
        ->not->toContain('GET pubblica', 'POST entra');
});
