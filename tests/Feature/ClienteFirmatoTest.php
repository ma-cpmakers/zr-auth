<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Errori\GettoneRifiutato;
use Zeiras\Auth\Testing\BackofficeFinto;

// #1447 (T1.8): il client firma le rotte senza gettone con quattro header (Api::firmata), e il finto li verifica come il
// backoffice. Il client non manda mai un X-Forwarded-For; senza nome, segreto o richiesta del browser non manda niente.

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 22:30:00');
    config(['zr-auth.cliente' => BackofficeFinto::CLIENTE, 'zr-auth.segreto' => BackofficeFinto::SEGRETO_DEL_CLIENTE]);
    request()->server->set('REMOTE_ADDR', '203.0.113.5');
});

/** Gli header che il client ha spedito per ultimi. */
function intestazioniSpedite(): array
{
    return Http::recorded()->last()[0]->headers();
}

it('firma le rotte senza gettone con l\'IP della richiesta del browser, e il finto lo riconosce (T1.8)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);

    $dati = Api::senzaGettone()->post('/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD]);

    expect($dati['data']['gettone']['gettone'])->toBeString()
        ->and($finto->ipVisti())->toBe([['operazione' => 'accessi.crea', 'cliente' => 'finto', 'ip' => '203.0.113.5']])
        ->and(intestazioniSpedite())->toHaveKeys(['Zr-Cliente', 'Zr-Ip', 'Zr-Istante', 'Zr-Firma'])
        ->and(intestazioniSpedite())->not->toHaveKey('X-Forwarded-For');
});

it('la firma è dell\'IP, del percorso e dell\'istante: ricalcolata a mano torna (T1.8)', function () {
    BackofficeFinto::attiva();

    Api::senzaGettone()->post('/v1/password/recupero', ['email' => 'anna@example.com']);
    $intestazioni = intestazioniSpedite();
    $istante = Carbon::now()->timestamp;

    expect($intestazioni['Zr-Istante'][0])->toBe((string) $istante)
        ->and($intestazioni['Zr-Firma'][0])->toBe(hash_hmac('sha256', "zr1\nfinto\n{$istante}\nPOST\n/v1/password/recupero\n203.0.113.5", BackofficeFinto::SEGRETO_DEL_CLIENTE));
});

it('non firma le rotte senza nome o senza segreto, né quelle col gettone (T1.8)', function (array $config, bool $conGettone) {
    config($config);
    BackofficeFinto::attiva();
    apriSessione();

    ($conGettone ? Api::persona() : Api::senzaGettone())->post('/v1/password/recupero', ['email' => 'anna@example.com']);

    expect(intestazioniSpedite())->not->toHaveKey('Zr-Firma')->not->toHaveKey('Zr-Cliente');
})->with([
    'senza nome' => [['zr-auth.cliente' => null], false],
    'senza segreto' => [['zr-auth.segreto' => ''], false],
    'col gettone' => [[], true],
]);

it('il finto rifiuta con 401 cliente_non_riconosciuto una firma che non torna, sempre con lo stesso corpo (T1.8, deve fallire se il finto accetta una firma sbagliata)', function () {
    BackofficeFinto::attiva();
    $istante = Carbon::now()->timestamp;
    $firma = fn (string $segreto, int $quando, string $ip = '203.0.113.5') => hash_hmac('sha256', "zr1\nfinto\n{$quando}\nPOST\n/v1/accessi\n{$ip}", $segreto);
    $casi = [
        'segreto sbagliato' => ['Zr-Cliente' => 'finto', 'Zr-Ip' => '203.0.113.5', 'Zr-Istante' => (string) $istante, 'Zr-Firma' => $firma('altro-segreto', $istante)],
        'vecchia di 31 secondi' => ['Zr-Cliente' => 'finto', 'Zr-Ip' => '203.0.113.5', 'Zr-Istante' => (string) ($istante - 31), 'Zr-Firma' => $firma(BackofficeFinto::SEGRETO_DEL_CLIENTE, $istante - 31)],
        'IP cambiato' => ['Zr-Cliente' => 'finto', 'Zr-Ip' => '203.0.113.6', 'Zr-Istante' => (string) $istante, 'Zr-Firma' => $firma(BackofficeFinto::SEGRETO_DEL_CLIENTE, $istante)],
        'client sconosciuto' => ['Zr-Cliente' => 'altro', 'Zr-Ip' => '203.0.113.5', 'Zr-Istante' => (string) $istante, 'Zr-Firma' => $firma(BackofficeFinto::SEGRETO_DEL_CLIENTE, $istante)],
        'solo il nome' => ['Zr-Cliente' => 'finto'],
    ];
    $corpi = [];

    foreach ($casi as $nome => $intestazioni) {
        // Senza il client di zr-auth in mezzo: la richiesta la scrive il test, con gli header che vuole.
        $risposta = alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD], intestazioni: $intestazioni);
        expect($risposta->status())->toBe(401, $nome)->and($risposta->json('codice'))->toBe('cliente_non_riconosciuto');
        $corpi[$nome] = $risposta->body();
    }

    expect(array_unique(array_map(fn (string $corpo) => json_decode($corpo, true)['detail'], $corpi)))->toHaveCount(1);
});

it('un client configurato male (segreto sbagliato) è un ErroreApi 401 cliente_non_riconosciuto, che va nel log, non un GettoneRifiutato muto (T8.2, deve fallire se rimanda in silenzio all\'ingresso)', function () {
    BackofficeFinto::attiva()->persona('anna@example.com', PASSWORD);
    config(['zr-auth.segreto' => 'un-altro-segreto-0123456789']);

    $errore = null;

    try {
        Api::senzaGettone()->post('/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD]);
    } catch (Throwable $e) {
        $errore = $e;
    }

    expect($errore)->toBeInstanceOf(ErroreApi::class)->not->toBeInstanceOf(GettoneRifiutato::class)
        ->and($errore->stato)->toBe(401)
        ->and($errore->codice)->toBe('cliente_non_riconosciuto')
        ->and($errore->getMessage())->not->toContain('un-altro-segreto');
});

it('da un comando artisan o da un job (fuori dai test) il client non firma: la richiesta legata da Laravel è finta, 127.0.0.1 (T8.3, deve fallire se firma con 127.0.0.1)', function () {
    BackofficeFinto::attiva();
    app()['env'] = 'production';

    Api::senzaGettone()->post('/v1/password/recupero', ['email' => 'anna@example.com']);

    expect(intestazioniSpedite())->not->toHaveKey('Zr-Firma')->not->toHaveKey('Zr-Cliente');
});

it('le tre rotte dei provider sono senza gettone come nel backoffice: il finto verifica la firma e registra l\'IP (T8.4, deve fallire se una firma sbagliata passa)', function () {
    $finto = BackofficeFinto::attiva()->provider('google');

    Api::senzaGettone()->get('/v1/accessi/provider');
    Api::senzaGettone()->post('/v1/accessi/provider/google/autorizzazioni');

    expect(array_column($finto->ipVisti(), 'operazione'))->toBe(['accessi.provider.elenca', 'accessi.provider.autorizzazioni.crea']);

    $sbagliata = ['Zr-Cliente' => 'finto', 'Zr-Ip' => '203.0.113.5', 'Zr-Istante' => (string) Carbon::now()->timestamp, 'Zr-Firma' => str_repeat('0', 64)];

    foreach ([['GET', '/v1/accessi/provider'], ['POST', '/v1/accessi/provider/google/autorizzazioni'], ['POST', '/v1/accessi/provider/google']] as [$metodo, $percorso]) {
        expect(alFinto($metodo, $percorso, $metodo === 'POST' ? [] : null, intestazioni: $sbagliata)->json('codice'))->toBe('cliente_non_riconosciuto', $percorso);
    }
});

/** Gli header che un client firmerebbe per accessi.crea da quell'IP. */
function firmaDa(string $ip): array
{
    $istante = Carbon::now()->timestamp;

    return [
        'Zr-Cliente' => 'finto',
        'Zr-Ip' => $ip,
        'Zr-Istante' => (string) $istante,
        'Zr-Firma' => hash_hmac('sha256', "zr1\nfinto\n{$istante}\nPOST\n/v1/accessi\n{$ip}", BackofficeFinto::SEGRETO_DEL_CLIENTE),
    ];
}

it('accessi.crea nel finto: dal sesto tentativo sull\'email serve il widget, la coppia (email, IP) è 429 al sesto, oltre 30 è 429 (T1.8)', function () {
    BackofficeFinto::attiva()->accendiTurnstile()->accendiGradinoAccessi()->persona('anna@example.com', PASSWORD);
    $accedi = fn (string $ip, string $password, ?string $widget = null) => alFinto('POST', '/v1/accessi', array_filter(['email' => 'anna@example.com', 'password' => $password, 'turnstile' => $widget]), intestazioni: firmaDa($ip));

    foreach (range(1, 5) as $n) {
        expect($accedi('203.0.113.5', PASSWORD_SBAGLIATA)->json('codice'))->toBe('credenziali_non_valide');
    }

    // Un'altra persona sullo stesso indirizzo di chi sbaglia non c'è: la persona vera sta su un altro.
    expect($accedi('198.51.100.7', PASSWORD)->json('codice'))->toBe('turnstile_non_valido')
        ->and($accedi('198.51.100.7', PASSWORD, BackofficeFinto::TURNSTILE_VALIDO)->status())->toBe(201)
        ->and($accedi('203.0.113.5', PASSWORD, BackofficeFinto::TURNSTILE_VALIDO)->json('codice'))->toBe('troppe_richieste');
});

it('accessi.crea nel finto, col gradino spento (di default): il sesto tentativo di un\'email da un altro IP è 429, mai il widget (T8.1, deve fallire se è 422 turnstile_non_valido o 201)', function () {
    BackofficeFinto::attiva()->accendiTurnstile()->persona('anna@example.com', PASSWORD);
    $accedi = fn (string $ip, string $password, ?string $widget = null) => alFinto('POST', '/v1/accessi', array_filter(['email' => 'anna@example.com', 'password' => $password, 'turnstile' => $widget]), intestazioni: firmaDa($ip));

    foreach (range(1, 5) as $n) {
        expect($accedi("198.51.100.{$n}", PASSWORD_SBAGLIATA)->json('codice'))->toBe('credenziali_non_valide');
    }

    expect($accedi('198.51.100.99', PASSWORD)->json('codice'))->toBe('troppe_richieste')
        ->and($accedi('198.51.100.99', PASSWORD, BackofficeFinto::TURNSTILE_VALIDO)->json('codice'))->toBe('troppe_richieste');
});

it('accessi.crea nel finto: la coppia (email, IP) al sesto tentativo è 429 anche col widget (T1.8)', function () {
    BackofficeFinto::attiva()->accendiTurnstile()->accendiGradinoAccessi()->persona('anna@example.com', PASSWORD);
    $accedi = fn (string $password, ?string $widget = null) => alFinto('POST', '/v1/accessi', array_filter(['email' => 'anna@example.com', 'password' => $password, 'turnstile' => $widget]), intestazioni: firmaDa('203.0.113.5'));

    foreach (range(1, 5) as $n) {
        $accedi(PASSWORD_SBAGLIATA);
    }

    expect($accedi(PASSWORD, BackofficeFinto::TURNSTILE_VALIDO)->json('codice'))->toBe('troppe_richieste');
});
