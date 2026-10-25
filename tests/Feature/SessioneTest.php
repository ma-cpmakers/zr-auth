<?php

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;
use Zeiras\Auth\Errori\SessioneNelBrowser;
use Zeiras\Auth\Http\Middleware\ConGettone;
use Zeiras\Auth\Sessione;
use Zeiras\Auth\Testing\Gettone;

// T1.1 (Z2, prova 8): il gettone sta solo nella sessione lato server.

it('apre la sessione con un id nuovo, e una pagina della persona non porta il gettone', function () {
    $this->get('/pubblica');
    $prima = session()->getId();

    $this->post('/entra', ['accesso' => accesso(), 'gettone' => gettoneDelWorkspace()])->assertOk();
    expect(session()->getId())->not->toBe($prima);

    $risposta = $this->get('/pagina')->assertOk()
        ->assertJsonPath('utente.id', utente()['id'])
        ->assertJsonPath('workspace', ['id' => '01k6r3a7c2e6g0j4m8p2s6v0x4', 'nome' => 'Studio Anna', 'slug' => 'studio-anna-k3x9q2', 'azienda_id' => '01k6r3a7c2e6g0j4m8p2s6v0x5'])
        ->assertJsonPath('ruolo', 'proprietario')
        ->assertJsonPath('accesso', accesso()['id']);

    Gettone::assenteDa($risposta);
});

it('si rifiuta con la sessione nel cookie, e non scrive niente', function () {
    config(['session.driver' => 'cookie']);

    expect(fn () => Sessione::apri(accesso()))->toThrow(SessioneNelBrowser::class)
        ->and(fn () => Sessione::entra(gettoneDelWorkspace()))->toThrow(SessioneNelBrowser::class)
        ->and(session()->has(Sessione::CHIAVE))->toBeFalse();
});

it('apri() ed entra() danno un id nuovo e distruggono la sessione di prima: niente fixation', function () {
    session()->put('ospite', true);
    session()->save();
    $ospite = session()->getId();

    Sessione::apri(accesso());
    session()->save();
    $accesso = session()->getId();
    Sessione::entra(gettoneDelWorkspace());

    expect(session()->getHandler()->read($ospite))->toBe('')
        ->and(session()->getHandler()->read($accesso))->toBe('')
        ->and($accesso)->not->toBe($ospite)
        ->and(session()->getId())->not->toBe($accesso);
});

it('apri(), entra(), lascia() e chiudi() rinnovano il gettone CSRF, anche per la stessa persona e per un\'altra nella stessa sessione del browser (CS.1, #2044)', function () {
    $ospite = session()->token();

    Sessione::apri(accesso());
    $dopoApri = session()->token();
    Sessione::entra(gettoneDelWorkspace());
    $dopoEntra = session()->token();
    Sessione::lascia();
    $dopoLascia = session()->token();
    Sessione::entra([...gettoneDelWorkspace(), 'utente' => [...utente(), 'id' => '01k6r3a7c2e6g0j4m8p2s6v0z9']]);
    $altraPersona = session()->token();
    Sessione::chiudi();

    expect($dopoApri)->not->toBe($ospite)
        ->and($dopoEntra)->not->toBe($dopoApri)
        ->and($dopoLascia)->not->toBe($dopoEntra)
        ->and($altraPersona)->not->toBe($dopoLascia)
        ->and(session()->token())->not->toBe($altraPersona);
});

it('chiude la sessione: i gettoni escono, l\'id è nuovo e la sessione di prima è distrutta', function () {
    apriSessione();
    session()->put('del frontend', 'di Anna');
    session()->save();
    $prima = session()->getId();
    expect(session()->getHandler()->read($prima))->not->toBe('');

    Sessione::chiudi();

    // Chi ha il cookie di prima dell'uscita non riapre niente: il record del vecchio id non c'è più.
    expect(session()->getHandler()->read($prima))->toBe('')
        ->and(session()->has(Sessione::CHIAVE))->toBeFalse()
        // Niente della sessione di Anna passa a chi entra dopo dallo stesso browser.
        ->and(session()->has('del frontend'))->toBeFalse()
        ->and(session()->getId())->not->toBe($prima)
        ->and(Sessione::aperta())->toBeFalse()
        ->and(Sessione::utente())->toBeNull();
});

it('una sessione scaduta non è aperta e non dice niente della persona', function () {
    apriSessione(scadeIl: now()->subMinute()->toJSON());

    expect(Sessione::aperta())->toBeFalse()
        ->and(Sessione::utente())->toBeNull()
        ->and(Sessione::workspace())->toBeNull()
        ->and(Sessione::accesso())->toBeNull();
});

// T6.2 (ZB2): il ritorno dopo l'accesso, al posto di redirect()->intended().

it('ritorno() dà la pagina ricordata solo se ha lo schema e l\'host della richiesta, se no la predefinita, e la toglie dalla sessione', function (?string $ricordata, string $atteso) {
    Route::middleware('web')->get('dopo-l-accesso', fn () => Sessione::ritorno('/bacheca'))->withoutMiddleware(ConGettone::class);
    if ($ricordata !== null) {
        session()->put('url.intended', $ricordata);
    }

    $this->get(FRONTEND.'/dopo-l-accesso')->assertOk()->assertContent($atteso);

    expect(session()->has('url.intended'))->toBeFalse();
})->with([
    'stesso schema e host' => ['https://board.zeiras.com/pagina?vista=2', 'https://board.zeiras.com/pagina?vista=2'],
    'un altro host' => ['https://evil.example/', '/bacheca'],
    'relativo, di un altro host' => ['//evil.example/', '/bacheca'],
    'http al posto di https' => ['http://board.zeiras.com/pagina', '/bacheca'],
    'un\'altra porta' => ['https://board.zeiras.com:8443/pagina', '/bacheca'],
    'senza ritorno' => [null, '/bacheca'],
    'l\'host come inizio di un altro' => ['https://board.zeiras.com.evil.example/', '/bacheca'],
    'l\'host come utente di un altro' => ['https://board.zeiras.com@evil.example/', '/bacheca'],
    'la barra rovescia, che il browser legge come una barra' => ['https://evil.example\\@board.zeiras.com/', '/bacheca'],
]);

// Il controllo stesso, per i test dei frontend: se il gettone arriva al browser, lo dice.

it('Gettone::assenteDa vede il gettone nel corpo, in un header e in un cookie cifrato', function (string $dove) {
    Route::middleware('web')->get('perde', fn () => match ($dove) {
        'corpo' => response('<div data-page="'.GETTONE_WORKSPACE.'"></div>'),
        'header' => response('ok')->header('X-Prova', GETTONE_WORKSPACE),
        'cookie' => response('ok')->cookie('prova', GETTONE_WORKSPACE),
    })->withoutMiddleware(ConGettone::class);

    expect(fn () => Gettone::assenteDa($this->get('/perde')))->toThrow(AssertionFailedError::class);
})->with(['corpo', 'header', 'cookie']);

it('entra() con un\'altra persona scarta l\'accesso e i gettoni di prima; con la stessa li tiene', function () {
    Sessione::apri(accesso());
    Sessione::entra(gettoneDelWorkspace());

    expect(session(Sessione::CHIAVE.'.accesso'))->not->toBeNull()
        ->and(session(Sessione::CHIAVE.'.gettoni'))->toHaveKeys(['accesso', 'workspace']);

    $altra = gettoneDelWorkspace();
    $altra['utente']['id'] = '01k6r2t5b9d3f7h1k5m9n3q7r2';
    $altra['utente']['email'] = 'bruno@example.com';
    Sessione::entra($altra);

    expect(session(Sessione::CHIAVE.'.accesso'))->toBeNull()
        ->and(array_keys(session(Sessione::CHIAVE.'.gettoni')))->toBe(['workspace'])
        ->and(session(Sessione::CHIAVE.'.utente.email'))->toBe('bruno@example.com');
});

// #2681: la sessione dell'ospite senza account, dalla risposta OspiteDalLink di accessi.link.crea.

/** I `data` di accessi.link.crea per un contatto senza account (lo schema OspiteDalLink). */
function ospiteDalLink(?string $scadeIl = null): array
{
    return [
        'gettone' => GETTONE_ACCESSO,
        'scade_il' => $scadeIl ?? now()->addHours(2)->toJSON(),
        'prenotazione_id' => '01k6r2s4a8c2e6g0j4m8p2r6t0',
    ];
}

it('apriOspite: la sessione è aperta fino a scade_il, la persona e l\'accesso mancano, il gettone sta dove Api::persona() lo manda (OS.1)', function () {
    $this->get('/pubblica');
    $prima = session()->getId();

    Sessione::apriOspite(ospiteDalLink());

    expect(Sessione::aperta())->toBeTrue()
        ->and(Sessione::utente())->toBeNull()
        ->and(Sessione::accesso())->toBeNull()
        ->and(Sessione::workspace())->toBeNull()
        ->and(Sessione::ruolo())->toBeNull()
        ->and(Sessione::ospite())->toBe(['prenotazione_id' => '01k6r2s4a8c2e6g0j4m8p2r6t0'])
        ->and(session(Sessione::CHIAVE)['gettoni'])->toBe(['accesso' => GETTONE_ACCESSO])
        ->and(session()->getId())->not->toBe($prima);
});

it('apriOspite: scaduta la scadenza la sessione non è più aperta, e ospite() non la mostra (OS.1)', function () {
    Sessione::apriOspite(ospiteDalLink(now()->subSecond()->toJSON()));

    expect(Sessione::aperta())->toBeFalse()
        ->and(Sessione::ospite())->toBeNull();
});

it('apriOspite rifiuta la forma di Accesso e una forma monca, e non scrive niente: serve un errore chiaro, non un TypeError (OS.1, deve fallire se apri() con questa forma o questa con quella)', function (string $forma) {
    $dati = match ($forma) {
        'accesso' => accesso(),
        'senza scade_il' => ['gettone' => GETTONE_ACCESSO, 'prenotazione_id' => '01k6r2s4a8c2e6g0j4m8p2r6t0'],
        'senza prenotazione_id' => ['gettone' => GETTONE_ACCESSO, 'scade_il' => now()->addHours(2)->toJSON()],
        'gettone vuoto' => [...ospiteDalLink(), 'gettone' => ''],
    };

    expect(fn () => Sessione::apriOspite($dati))->toThrow(InvalidArgumentException::class)
        ->and(session()->has(Sessione::CHIAVE))->toBeFalse();
})->with(['accesso', 'senza scade_il', 'senza prenotazione_id', 'gettone vuoto']);

it('apriOspite si rifiuta con la sessione nel cookie, come apri() (OS.1)', function () {
    config(['session.driver' => 'cookie']);

    expect(fn () => Sessione::apriOspite(ospiteDalLink()))->toThrow(SessioneNelBrowser::class)
        ->and(session()->has(Sessione::CHIAVE))->toBeFalse();
});

it('apriOspite distrugge la sessione di prima: nessuna fixation (OS.1)', function () {
    session()->put('di prima', true);
    session()->save();
    $prima = session()->getId();

    Sessione::apriOspite(ospiteDalLink());

    // Come apri(): il record dell'id di prima non c'è più (regenerate(true)); i dati della richiesta restano all'id nuovo.
    expect(session()->getHandler()->read($prima))->toBe('')
        ->and(session()->getId())->not->toBe($prima);
});

it('entra() su una sessione di ospite non tiene il gettone dell\'ospite, e lascia() non la tocca (OS.2, deve fallire se entra() lo tiene)', function () {
    Sessione::apriOspite(ospiteDalLink());
    $idSessione = session()->getId();

    Sessione::lascia();

    expect(session()->getId())->toBe($idSessione)
        ->and(Sessione::ospite())->not->toBeNull();

    Sessione::entra(gettoneDelWorkspace());

    expect(session(Sessione::CHIAVE)['gettoni'])->toBe(['workspace' => GETTONE_WORKSPACE])
        ->and(Sessione::ospite())->toBeNull()
        ->and(Sessione::utente()['id'])->toBe(utente()['id']);
});

it('aggiorna() non fa niente su una sessione di ospite, e chiudi() la chiude come ogni altra (OS.2)', function () {
    Sessione::apriOspite(ospiteDalLink());

    expect(Sessione::aggiorna(['utente' => utente()]))->toBeFalse();

    Sessione::chiudi();

    expect(Sessione::aperta())->toBeFalse()
        ->and(Sessione::ospite())->toBeNull();
});
