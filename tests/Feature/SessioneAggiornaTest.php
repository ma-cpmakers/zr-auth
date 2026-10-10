<?php

use Zeiras\Auth\Sessione;

// #1473 (T2.1-T2.4): la lingua e il nome del profilo arrivano ai moduli. La sessione li prende all'ingresso e non li rilegge;
// `aggiorna()` li rimette uguali a quelli di io.mostra, e nient'altro.

/** I `data` di io.mostra per una persona con la lingua e il nome dati. */
function ioMostra(mixed $lingua, mixed $nome): array
{
    return ['utente' => ['lingua' => $lingua, 'nome' => $nome], 'workspace' => null, 'ruolo' => null];
}

it('aggiorna la lingua e il nome della sessione dai dati di io.mostra (T2.1)', function () {
    apriSessione();

    $cambiata = Sessione::aggiorna(ioMostra('en', 'Anne'));

    expect($cambiata)->toBeTrue()
        ->and(Sessione::utente()['lingua'])->toBe('en')
        ->and(Sessione::utente()['nome'])->toBe('Anne');
});

it('cambia solo lingua e nome: il resto della sessione resta com\'è (T2.2)', function () {
    apriSessione();
    $prima = session(Sessione::CHIAVE);

    Sessione::aggiorna(['utente' => ['lingua' => 'es', 'nome' => 'Ana', 'email' => 'altra@example.com', 'id' => utente()['id'], 'fuso_orario' => 'Asia/Tokyo'], 'workspace' => ['id' => 'ALTRO'], 'ruolo' => 'membro']);

    $dopo = session(Sessione::CHIAVE);
    expect($dopo['utente'])->toBe([...$prima['utente'], 'lingua' => 'es', 'nome' => 'Ana'])
        ->and($dopo['gettoni'])->toBe($prima['gettoni'])
        ->and($dopo['workspace'])->toBe($prima['workspace'])
        ->and($dopo['ruolo'])->toBe($prima['ruolo'])
        ->and($dopo['accesso'])->toBe($prima['accesso'])
        ->and($dopo['scade_il'])->toBe($prima['scade_il']);
});

it('i dati di un\'altra persona non entrano nella sessione (T2.2, revisione)', function () {
    apriSessione();
    $prima = session(Sessione::CHIAVE);

    expect(Sessione::aggiorna(['utente' => ['id' => 'ALTRA-PERSONA', 'lingua' => 'es', 'nome' => 'Ana']]))->toBeFalse()
        ->and(session(Sessione::CHIAVE))->toBe($prima);
});

it('chiamato con gli stessi valori non scrive la sessione, e lo dice (T2.2)', function () {
    apriSessione();
    Sessione::aggiorna(ioMostra('en', 'Anne'));
    $prima = session(Sessione::CHIAVE);

    expect(Sessione::aggiorna(ioMostra('en', 'Anne')))->toBeFalse()
        ->and(session(Sessione::CHIAVE))->toBe($prima);

    // Un campo uguale e l'altro diverso: cambia solo quello che cambia.
    expect(Sessione::aggiorna(ioMostra('en', 'Annette')))->toBeTrue()
        ->and(session(Sessione::CHIAVE)['utente'])->toBe([...$prima['utente'], 'nome' => 'Annette']);
});

it('una lingua che non è una stringa non vuota, o un nome vuoto, non sostituisce il valore che c\'è (T2.3)', function (mixed $lingua, mixed $nome) {
    apriSessione();
    $prima = session(Sessione::CHIAVE);

    expect(Sessione::aggiorna(ioMostra($lingua, $nome)))->toBeFalse()
        ->and(session(Sessione::CHIAVE))->toBe($prima);
})->with([
    'null' => [null, null],
    'vuoti' => ['', ''],
    'spazi' => ['   ', '   '],
    'numeri' => [12, 34],
    'array' => [['en'], ['Anne']],
    'bool' => [true, false],
]);

it('una lingua valida con un nome vuoto cambia la lingua e lascia il nome (T2.3)', function () {
    apriSessione();
    $nome = Sessione::utente()['nome'];

    expect(Sessione::aggiorna(ioMostra('es', '')))->toBeTrue()
        ->and(Sessione::utente()['lingua'])->toBe('es')
        ->and(Sessione::utente()['nome'])->toBe($nome);
});

it('i dati di io.mostra senza la forma attesa non cambiano niente e non lanciano (T2.3)', function (array $io) {
    apriSessione();
    $prima = session(Sessione::CHIAVE);

    expect(Sessione::aggiorna($io))->toBeFalse()
        ->and(session(Sessione::CHIAVE))->toBe($prima);
})->with([[[]], [['utente' => null]], [['utente' => 'Anne']], [['utente' => []]]]);

it('senza sessione non fa niente e non lancia (T2.4)', function () {
    expect(Sessione::aggiorna(ioMostra('en', 'Anne')))->toBeFalse()
        ->and(session()->has(Sessione::CHIAVE))->toBeFalse();
});

it('con la sessione scaduta non fa niente e non lancia (T2.4)', function () {
    apriSessione(scadeIl: now()->subMinute()->toJSON());
    $prima = session(Sessione::CHIAVE);

    expect(Sessione::aggiorna(ioMostra('en', 'Anne')))->toBeFalse()
        ->and(session(Sessione::CHIAVE))->toBe($prima);
});
