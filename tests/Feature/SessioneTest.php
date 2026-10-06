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
        ->assertJsonPath('workspace', ['id' => '01k6r3a7c2e6g0j4m8p2s6v0x4', 'nome' => 'Studio Anna', 'slug' => 'studio-anna-k3x9q2'])
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
