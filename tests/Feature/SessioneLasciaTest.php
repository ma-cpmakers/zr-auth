<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Zeiras\Auth\Api;
use Zeiras\Auth\Sessione;

// #2238 (L.1-L.3): dopo workspace.uscita.crea la persona resta dentro Zeiras col solo accesso. `lascia()` toglie dalla sessione il
// workspace, il ruolo e il gettone del workspace; tiene l'accesso, il suo gettone, la persona e la scadenza.

it('toglie workspace, ruolo e gettone del workspace e tiene accesso, gettone dell\'accesso, utente e scadenza (L.1)', function () {
    apriSessione();
    $prima = session(Sessione::CHIAVE);
    $id = session()->getId();

    Sessione::lascia();

    $dopo = session(Sessione::CHIAVE);
    expect($dopo['workspace'])->toBeNull()
        ->and($dopo['ruolo'])->toBeNull()
        ->and($dopo['gettoni'])->toBe(['accesso' => GETTONE_ACCESSO])
        ->and($dopo['accesso'])->toBe($prima['accesso'])
        ->and($dopo['utente'])->toBe($prima['utente'])
        ->and($dopo['scade_il'])->toBe($prima['scade_il'])
        ->and(Sessione::aperta())->toBeTrue()
        ->and(Sessione::workspace())->toBeNull()
        ->and(Sessione::ruolo())->toBeNull()
        ->and(Sessione::utente()['id'])->toBe(utente()['id'])
        ->and(Sessione::accesso())->toBe($prima['accesso'])
        // L'id è nuovo e quella di prima è segnata chiusa, come per ogni cambio di sessione.
        ->and(session()->getId())->not->toBe($id)
        ->and(Sessione::eChiusa($id))->toBeTrue();
});

it('senza una sessione aperta non fa niente e non lancia (L.2)', function () {
    $id = session()->getId();

    Sessione::lascia();

    expect(session()->has(Sessione::CHIAVE))->toBeFalse()
        ->and(session()->getId())->toBe($id)
        ->and(Sessione::aperta())->toBeFalse();
});

it('con la sola sessione dell\'accesso resta com\'è, ma con un id nuovo (L.2)', function () {
    apriSessione(conWorkspace: false);
    $prima = session(Sessione::CHIAVE);
    $id = session()->getId();

    Sessione::lascia();

    expect(session(Sessione::CHIAVE))->toBe($prima)
        ->and(session()->getId())->not->toBe($id)
        ->and(Sessione::eChiusa($id))->toBeTrue();
});

it('dopo, Api::workspace() è come senza workspace: nessun gettone del workspace, e non manda quello lasciato (L.3)', function () {
    apriSessione();
    Sessione::lascia();

    Http::fake(['*' => Http::response(['data' => []])]);

    expect(fn () => Api::workspace())->toThrow(LogicException::class, 'Nessun workspace nella sessione');

    Api::persona()->get('/v1/io');

    // Chiama col gettone dell'accesso, mai con quello del workspace lasciato.
    Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Bearer '.GETTONE_ACCESSO]);
    Http::assertNotSent(fn (Request $r) => $r->header('Authorization') === ['Bearer '.GETTONE_WORKSPACE]);
});

it('una sessione nata dal ricevitore, senza gettone dell\'accesso, si chiude: non c\'è un accesso a cui tornare (L.1)', function () {
    Sessione::entra(gettoneDelWorkspace());
    $id = session()->getId();

    Sessione::lascia();

    expect(session()->has(Sessione::CHIAVE))->toBeFalse()
        ->and(Sessione::aperta())->toBeFalse()
        ->and(session()->getId())->not->toBe($id)
        ->and(Sessione::eChiusa($id))->toBeTrue();
});

it('con la sessione scaduta non fa niente (L.2)', function () {
    apriSessione(scadeIl: now()->subMinute()->toJSON());
    $prima = session(Sessione::CHIAVE);

    Sessione::lascia();

    expect(session(Sessione::CHIAVE))->toBe($prima);
});
