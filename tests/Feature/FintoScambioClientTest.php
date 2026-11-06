<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// S1.1-S1.3 di #3344 (revisione di sicurezza di #3304): lo scambio di un codice di ingresso lo fa solo il client firmato dell'app per cui il codice è nato
// (o un client di sistema), nei due rami. Il finto fa lo stesso del backoffice; che le risposte siano uguali byte per byte lo prova il FintoTest del backoffice.

const VERIFICATORE_DEL_CLIENT = 'verificatore-del-client-0123456789abcdefghijklmnop';

function sfidaDelClient(string $verificatore): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verificatore, true)), '+/', '-_'), '=');
}

/** Un codice nato per `pm` (ramo del workspace) o per `bookings` (ramo del cliente). [codice, corpo dello scambio] */
function codiceDelClient(string $app): array
{
    $finto = BackofficeFinto::attiva()->ritorno($app, 'https://modulo.zeiras.com/ingresso/ritorno');
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];
    $sfida = ['app' => $app, 'sfida' => sfidaDelClient(VERIFICATORE_DEL_CLIENT)];

    if ($app === 'pm') {
        $studio = $finto->workspace('Studio', $anna);
        $finto->attivaApp($studio, 'pm');
        $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso)->json('data.gettone');
        $codice = alFinto('POST', '/v1/ingressi', $sfida, $gettone)->json('data.codice');
    } else {
        $codice = alFinto('POST', '/v1/ingressi/clienti', $sfida, $accesso)->json('data.codice');
    }

    return [$codice, ['codice' => $codice, 'verificatore' => VERIFICATORE_DEL_CLIENT]];
}

it('il codice del cliente per bookings lo scambia il client bookings, e nessun altro (S1.1)', function () {
    [, $corpo] = codiceDelClient('bookings');

    $scambio = scambioDelFinto($corpo, 'bookings');

    expect($scambio->status())->toBe(201)
        ->and($scambio->json('data.workspace'))->toBeNull();
});

it('il codice del cliente per bookings non lo scambia un altro client firmato, e il codice è consumato (S1.1, S1.3)', function (string $altro) {
    [, $corpo] = codiceDelClient('bookings');

    $respinto = scambioDelFinto($corpo, $altro);
    $poi = scambioDelFinto($corpo, 'bookings');

    expect($respinto->status())->toBe(422)
        ->and($respinto->json('codice'))->toBe('verifica_non_riuscita')
        ->and($poi->status())->toBe(422)
        ->and($poi->json('codice'))->toBe('verifica_non_riuscita');
})->with(['board', 'crm', 'home']);

it('il codice di un cliente non si scambia senza firma: la stessa 422 (S1.1)', function () {
    [, $corpo] = codiceDelClient('bookings');

    $anonimo = scambioDelFinto($corpo, null);

    expect($anonimo->status())->toBe(422)
        ->and($anonimo->json('codice'))->toBe('verifica_non_riuscita');
});

it('il codice del workspace per pm lo scambia il client board (S1.2)', function () {
    [, $corpo] = codiceDelClient('pm');

    expect(scambioDelFinto($corpo, 'board')->status())->toBe(201);
});

it('il codice del workspace per pm non lo scambia un altro client, e il codice è consumato (S1.2, S1.3)', function () {
    [, $corpo] = codiceDelClient('pm');

    $respinto = scambioDelFinto($corpo, 'bookings');

    expect($respinto->status())->toBe(422)
        ->and($respinto->json('codice'))->toBe('verifica_non_riuscita')
        ->and(scambioDelFinto($corpo, 'board')->status())->toBe(422);
});

it('il codice del workspace non si scambia senza firma (S1.2)', function () {
    [, $corpo] = codiceDelClient('pm');

    expect(scambioDelFinto($corpo, null)->json('codice'))->toBe('verifica_non_riuscita');
});

it('i client di sistema e il client di prova del finto scambiano il codice di ogni app (S1.3)', function (string $cliente, string $app) {
    [, $corpo] = codiceDelClient($app);

    expect(scambioDelFinto($corpo, $cliente)->status())->toBe(201);
})->with([
    'monitor, pm' => ['monitor', 'pm'],
    'uat, pm' => ['uat', 'pm'],
    'uat, bookings' => ['uat', 'bookings'],
    'finto, pm' => [BackofficeFinto::CLIENTE, 'pm'],
    'finto, bookings' => [BackofficeFinto::CLIENTE, 'bookings'],
]);
