<?php

use Illuminate\Support\Facades\Http;
use Zeiras\Auth\Persona;
use Zeiras\Auth\Sessione;

// #2136: la difesa comune «Resta poi Salva». Una scrittura partita da una scheda rimasta ferma su A, dopo che B è entrato nello
// stesso browser (la stessa sessione), non passa col nome di B. La pagina dice di chi è con `X-Zr-Persona` (Persona::impronta()).

const ALTRA_PERSONA = '01k6r2t5b9d3f7h1k5m9n3q7r2';

/** Il browser è lo stesso e B entra: la sessione di A diventa quella di B (Sessione::apri rigenera l'id e riscrive la persona). */
function entraAltraPersona(): void
{
    $accesso = accesso();
    $accesso['gettone']['utente'] = [...utente(), 'id' => ALTRA_PERSONA, 'nome' => 'Bruno', 'email' => 'bruno@example.com'];
    Sessione::apri($accesso);
}

function scritture(): int
{
    return app()->bound('persona.scritture') ? app('persona.scritture') : 0;
}

beforeEach(function () {
    config(['zr-auth.persona.controllo' => true]);
});

// T1.1
it('l\'impronta è la stessa per la stessa persona a ogni ingresso, diversa per due persone, e non è né l\'id né l\'email', function () {
    apriSessione();
    $anna = Persona::impronta();
    apriSessione(false);

    expect($anna)->toMatch('/\A[0-9a-f]{32}\z/')
        ->and(Persona::impronta())->toBe($anna)
        ->and($anna)->not->toBe(substr(hash('sha256', utente()['id']), 0, 32));

    entraAltraPersona();

    expect(Persona::impronta())->toMatch('/\A[0-9a-f]{32}\z/')->not->toBe($anna);
});

it('l\'impronta dipende dalla chiave dell\'app: chi conosce l\'id non la rifà', function () {
    apriSessione();
    $prima = Persona::impronta();

    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    expect(Persona::impronta())->not->toBe($prima);
});

it('senza sessione l\'impronta è null', function () {
    expect(Persona::impronta())->toBeNull();
});

// T1.2
it('una scrittura con l\'impronta della sessione arriva alla rotta, con ogni verbo che scrive', function (string $verbo) {
    apriSessione();

    $this->call($verbo, '/persona/scrive', [], [], [], ['HTTP_X_ZR_PERSONA' => Persona::impronta()])->assertOk();

    expect(scritture())->toBe(1);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

// T1.3
it('«Resta poi Salva»: la scheda di A scrive dopo l\'ingresso di B, 409 e la rotta non gira, nessuna chiamata al backoffice', function () {
    Http::fake(['*' => Http::response(['data' => []])]);
    apriSessione();
    $diAnna = Persona::impronta();
    entraAltraPersona();

    // `/scrive` chiama davvero il backoffice col gettone del workspace (TestCase): se girasse, la chiamata partirebbe.
    $this->postJson('/scrive', [], ['X-Zr-Persona' => $diAnna])
        ->assertStatus(409)
        ->assertExactJson(['errore' => 'persona_diversa']);
    $this->postJson('/persona/scrive', [], ['X-Zr-Persona' => $diAnna])->assertStatus(409);

    expect(scritture())->toBe(0);
    Http::assertNothingSent();
});

it('la scheda della persona della sessione scrive, e la chiamata al backoffice parte', function () {
    Http::fake(['*' => Http::response(['data' => []])]);
    apriSessione();

    $this->postJson('/scrive', [], ['X-Zr-Persona' => Persona::impronta()])->assertOk();

    Http::assertSentCount(1);
});

// T1.4
it('senza l\'intestazione, o con l\'intestazione vuota, la scrittura è rifiutata allo stesso modo', function (?string $valore) {
    apriSessione();

    $this->postJson('/persona/scrive', [], $valore === null ? [] : ['X-Zr-Persona' => $valore])
        ->assertStatus(409)
        ->assertExactJson(['errore' => 'persona_diversa']);

    expect(scritture())->toBe(0);
})->with([null, '']);

// T1.5
it('un\'intestazione ripetuta vale per tutte: una sbagliata dopo la giusta è 409', function () {
    apriSessione();

    $this->call('POST', '/persona/scrive', [], [], [], ['HTTP_X_ZR_PERSONA' => Persona::impronta().', '.str_repeat('0', 32)])->assertStatus(409);
    $this->call('POST', '/persona/scrive', [], [], [], ['HTTP_X_ZR_PERSONA' => str_repeat('0', 32).', '.Persona::impronta()])->assertStatus(409);

    expect(scritture())->toBe(0);

    $this->call('POST', '/persona/scrive', [], [], [], ['HTTP_X_ZR_PERSONA' => Persona::impronta().', '.Persona::impronta()])->assertOk();

    expect(scritture())->toBe(1);
});

// T1.6
it('il 409 non porta l\'impronta, l\'email né l\'id di nessuna delle due persone', function () {
    apriSessione();
    $diAnna = Persona::impronta();
    entraAltraPersona();

    $risposta = $this->postJson('/persona/scrive', [], ['X-Zr-Persona' => $diAnna])->assertStatus(409);

    $tutto = strtolower($risposta->baseResponse->headers->__toString().$risposta->getContent());
    foreach ([$diAnna, Persona::impronta(), utente()['id'], ALTRA_PERSONA, 'anna@example.com', 'bruno@example.com'] as $segreto) {
        expect($tutto)->not->toContain(strtolower((string) $segreto));
    }
});

// T1.7
it('GET, HEAD e OPTIONS passano senza l\'intestazione', function (string $verbo) {
    apriSessione();

    $this->call($verbo, '/persona/legge')->assertOk();
})->with(['GET', 'HEAD']);

it('OPTIONS passa senza l\'intestazione, su una rotta OPTIONS dichiarata (il middleware gira davvero)', function () {
    apriSessione();

    $this->call('OPTIONS', '/persona/opzioni')->assertOk();
});

it('un POST che si dichiara OPTIONS con _method resta una scrittura', function () {
    apriSessione();

    $this->call('POST', '/persona/mista', ['_method' => 'OPTIONS'])->assertStatus(409);
    $this->call('OPTIONS', '/persona/mista')->assertOk();
});

// T1.8
it('una rotta che si toglie il controllo («Esci») passa anche con l\'impronta di un\'altra persona', function () {
    apriSessione();
    $diAnna = Persona::impronta();
    entraAltraPersona();

    $this->postJson('/persona/esci', [], ['X-Zr-Persona' => $diAnna])->assertOk();
    $this->postJson('/persona/esci')->assertOk();
});

// T1.9
it('le impronte si confrontano a tempo costante', function () {
    $sorgente = file_get_contents(__DIR__.'/../../src/Http/Middleware/SoloDallaPersona.php');

    expect($sorgente)->toContain('hash_equals(')
        ->and($sorgente)->not->toMatch('/(===|!==|==|!=)\s*\$(impronta|detta|sua|attesa)\b/')
        ->and($sorgente)->not->toMatch('/\$(impronta|detta|sua|attesa)\b\s*(===|!==|==|!=)\s*\$/');
});

// SD.1 (#2201): la sessione è aperta ma l'impronta manca (`utente.id` assente, vuoto o non stringa): la scrittura userebbe il
// gettone di chi c'è senza il controllo, quindi è 409 come la persona diversa, e il backoffice non è chiamato.
it('con la sessione aperta e l\'impronta null (utente.id assente, vuoto o non stringa) la scrittura è 409 e non arriva al backoffice', function (mixed $id) {
    Http::fake(['*' => Http::response(['data' => []])]);
    $accesso = accesso();
    $senzaId = array_diff_key(utente(), ['id' => 1]) + ($id === 'assente' ? [] : ['id' => $id]);
    $accesso['gettone']['utente'] = $senzaId;
    Sessione::apri($accesso);
    // Col gettone del workspace (entra() riscrive la persona dal suo `utente`) la rotta chiamerebbe davvero il backoffice.
    Sessione::entra([...gettoneDelWorkspace(), 'utente' => $senzaId]);

    expect(Sessione::aperta())->toBeTrue()->and(Persona::impronta())->toBeNull();

    $this->postJson('/scrive', [], ['X-Zr-Persona' => str_repeat('0', 32)])
        ->assertStatus(409)
        ->assertExactJson(['errore' => 'persona_diversa']);
    $this->postJson('/persona/scrive')->assertStatus(409);
    $this->postJson('/persona/azione')->assertStatus(409); // anche una rotta fuori dalla guardia con :sempre

    expect(scritture())->toBe(0);
    Http::assertNothingSent();
})->with(['assente', '', 123, [['01k6']], null]);

// SD.2 (#2201): senza sessione la rotta :sempre passa come prima (il ramo «sessione aperta» non scatta); con l'impronta presente passa.
it('SD.2: senza sessione una rotta :sempre passa, e con la sessione e l\'impronta giusta la scrittura passa', function () {
    $this->postJson('/persona/azione')->assertOk();

    apriSessione();
    $this->call('POST', '/persona/scrive', [], [], [], ['HTTP_X_ZR_PERSONA' => Persona::impronta()])->assertOk();
});

// T1.10
it('senza sessione la strada è quella della guardia, non il 409', function () {
    $this->postJson('/persona/scrive', [], ['X-Zr-Persona' => str_repeat('0', 32)])->assertStatus(401);
    $this->post('/persona/scrive')->assertRedirect(INGRESSO);
});

it('una pagina pubblica (fuori dalla guardia) passa: un\'altra persona deve poter entrare', function () {
    apriSessione();

    $this->postJson('/persona/pubblica')->assertOk();
});

it('una rotta fuori dalla guardia che è un\'azione della persona vuole il controllo con :sempre', function () {
    apriSessione();
    $diAnna = Persona::impronta();

    $this->postJson('/persona/azione')->assertStatus(409);
    $this->postJson('/persona/azione', [], ['X-Zr-Persona' => $diAnna])->assertOk();
    entraAltraPersona();
    $this->postJson('/persona/azione', [], ['X-Zr-Persona' => $diAnna])->assertStatus(409);
});

it('il controllo si accende anche con 1, on e yes (un valore che non si legge non lo lascia spento)', function (mixed $valore) {
    config(['zr-auth.persona.controllo' => $valore]);
    apriSessione();

    $this->postJson('/persona/scrive')->assertStatus(409);
})->with(['1', 'on', 'yes', 1, true]);

it('col controllo spento a mano una scrittura senza intestazione passa come prima', function () {
    config(['zr-auth.persona.controllo' => false]);
    apriSessione();

    $this->postJson('/persona/scrive')->assertOk();

    expect(scritture())->toBe(1)
        ->and((new ReflectionClass(Persona::class))->hasMethod('impronta'))->toBeTrue();
});

/** La config del pacchetto come la legge un modulo con quell'`.env` (nessuna riga se `$env` è null). */
function configDelPacchetto(?string $env): array
{
    $prima = getenv('ZR_AUTH_PERSONA_CONTROLLO');
    $env === null ? putenv('ZR_AUTH_PERSONA_CONTROLLO') : putenv('ZR_AUTH_PERSONA_CONTROLLO='.$env);
    try {
        return require __DIR__.'/../../config/zr-auth.php';
    } finally {
        $prima === false ? putenv('ZR_AUTH_PERSONA_CONTROLLO') : putenv('ZR_AUTH_PERSONA_CONTROLLO='.$prima);
    }
}

// PD.1
it('senza nessuna riga nell\'.env il controllo è acceso: una scrittura senza intestazione è 409', function () {
    config(['zr-auth.persona' => configDelPacchetto(null)['persona']]);
    apriSessione();

    expect(config('zr-auth.persona.controllo'))->toBeTrue();
    $this->postJson('/persona/scrive')->assertStatus(409)->assertExactJson(['errore' => 'persona_diversa']);
    expect(scritture())->toBe(0);
});

it('con la config pubblicata di prima, senza la chiave `persona`, il controllo è acceso', function () {
    config(['zr-auth.persona' => null]);
    apriSessione();

    $this->postJson('/persona/scrive')->assertStatus(409);
    expect(scritture())->toBe(0);
});

// PD.2
it('ZR_AUTH_PERSONA_CONTROLLO=false, 0 e off lo spengono: una scrittura senza intestazione passa', function (string $env) {
    config(['zr-auth.persona' => configDelPacchetto($env)['persona']]);
    apriSessione();

    expect(config('zr-auth.persona.controllo'))->toBeFalse();
    $this->postJson('/persona/scrive')->assertOk();
    expect(scritture())->toBe(1);
})->with(['false', '0', 'off']);

it('una riga vuota o un valore che non si legge non spengono il controllo', function (string $env) {
    config(['zr-auth.persona' => configDelPacchetto($env)['persona']]);
    apriSessione();

    expect(config('zr-auth.persona.controllo'))->toBeTrue();
    $this->postJson('/persona/scrive')->assertStatus(409);
    expect(scritture())->toBe(0);
})->with(['', 'ture', 'si']);

it('un valore che non si legge nella config non lo spegne nemmeno nel middleware', function () {
    config(['zr-auth.persona.controllo' => 'ture']);
    apriSessione();

    $this->postJson('/persona/scrive')->assertStatus(409);
});

// PD.3, l'avvio: il provider chiama l'avviso da sé (il log di prova è quello dell'app di questo test, già avviata spenta).
it('all\'avvio, col controllo spento, il pacchetto scrive l\'avviso nel log', function () {
    $gestori = Illuminate\Support\Facades\Log::channel('prova')->getLogger()->getHandlers();

    expect($gestori[0]->hasWarningThatContains('ZR_AUTH_PERSONA_CONTROLLO'))->toBeTrue();
});

// PD.3
it('spento, il pacchetto lo scrive nel log una volta, col nome della variabile e senza dati', function () {
    Illuminate\Support\Facades\Cache::flush();
    Illuminate\Support\Facades\Log::spy();
    config(['zr-auth.persona.controllo' => false]);
    apriSessione();

    $provider = new Zeiras\Auth\ZrAuthServiceProvider(app());
    $provider->avvisaSeSpento();
    $provider->avvisaSeSpento();
    $this->postJson('/persona/scrive')->assertOk();

    Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->withArgs(function (string $messaggio, array $contesto = []): bool {
        return str_contains($messaggio, 'ZR_AUTH_PERSONA_CONTROLLO')
            && $contesto === []
            && ! str_contains($messaggio, 'anna@example.com')
            && ! str_contains($messaggio, Persona::impronta());
    });
});

it('acceso, il pacchetto non scrive niente nel log', function () {
    Illuminate\Support\Facades\Cache::flush();
    Illuminate\Support\Facades\Log::spy();
    config(['zr-auth.persona.controllo' => true]);

    (new Zeiras\Auth\ZrAuthServiceProvider(app()))->avvisaSeSpento();

    Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
});

// PD.4
it('il README e il commento della config dicono che è acceso, come si spegne e che spento lo scrive nel log', function () {
    $readme = file_get_contents(__DIR__.'/../../README.md');
    preg_match('/^## La stessa persona[^\n]*\n(.*?)(?=^## |\z)/ms', $readme, $trovata);
    $config = file_get_contents(__DIR__.'/../../config/zr-auth.php');

    foreach ([$trovata[1] ?? '', $config] as $testo) {
        expect($testo)->not->toBe('')
            ->toContain('ZR_AUTH_PERSONA_CONTROLLO')
            ->toContain('persona.controllo')
            ->toContain('acceso di default')
            ->toContain('ZR_AUTH_PERSONA_CONTROLLO=false')
            ->toContain('lo scrive nel log');
    }
    expect($trovata[1])->not->toContain('spento di default');
    expect($config)->not->toContain('Spento di default');
});

// T1.11
it('il README dice come passa ciascun modulo alla comune e che «Esci» è esente', function () {
    $readme = file_get_contents(__DIR__.'/../../README.md');
    preg_match('/^## La stessa persona[^\n]*\n(.*?)(?=^## |\z)/ms', $readme, $trovata);
    $sezione = $trovata[1] ?? '';

    expect($sezione)->not->toBe('')
        ->toContain('X-Zr-Persona')
        ->toContain('persona.controllo')
        ->toContain('persona_diversa')
        ->toContain('Persona::impronta()')
        ->toContain('SoloDallaPersona')
        ->toContain('zr-home')
        ->toContain('zr-board')
        ->toContain('zr-crm')
        ->toContain('Esci');
});
