<?php

use Zeiras\Auth\Sessione;

// #1473 (T2.1-T2.4): la lingua e il nome del profilo arrivano ai moduli. La sessione li prende all'ingresso e non li rilegge;
// `aggiorna()` li rimette uguali a quelli di io.mostra, e nient'altro.

/** I `data` di io.mostra per una persona con la lingua e il nome dati. */
function ioMostra(mixed $lingua, mixed $nome): array
{
    return ['utente' => ['id' => utente()['id'], 'lingua' => $lingua, 'nome' => $nome], 'workspace' => null, 'ruolo' => null];
}

it('aggiorna la lingua e il nome della sessione dai dati di io.mostra (T2.1)', function () {
    apriSessione();

    $cambiata = Sessione::aggiorna(ioMostra('en', 'Anne'));

    expect($cambiata)->toBeTrue()
        ->and(Sessione::utente()['lingua'])->toBe('en')
        ->and(Sessione::utente()['nome'])->toBe('Anne');
});

it('cambia solo lingua, nome ed email: il resto della sessione resta com\'è (T2.2, #2214)', function () {
    apriSessione();
    $prima = session(Sessione::CHIAVE);

    Sessione::aggiorna(['utente' => ['lingua' => 'es', 'nome' => 'Ana', 'email' => 'ana@example.com', 'id' => utente()['id'], 'fuso_orario' => 'Asia/Tokyo'], 'workspace' => ['id' => 'ALTRO'], 'ruolo' => 'membro']);

    $dopo = session(Sessione::CHIAVE);
    expect($dopo['utente'])->toBe([...$prima['utente'], 'lingua' => 'es', 'nome' => 'Ana', 'email' => 'ana@example.com'])
        ->and($dopo['gettoni'])->toBe($prima['gettoni'])
        ->and($dopo['workspace'])->toBe($prima['workspace'])
        ->and($dopo['ruolo'])->toBe($prima['ruolo'])
        ->and($dopo['accesso'])->toBe($prima['accesso'])
        ->and($dopo['scade_il'])->toBe($prima['scade_il']);
});

it('dati senza l\'id della persona non entrano nella sessione (T2.2, revisione)', function () {
    apriSessione();
    $prima = session(Sessione::CHIAVE);

    expect(Sessione::aggiorna(['utente' => ['lingua' => 'es', 'nome' => 'Ana']]))->toBeFalse()
        ->and(session(Sessione::CHIAVE))->toBe($prima);
});

it('i dati di un\'altra persona non entrano nella sessione (T2.2, revisione)', function () {
    apriSessione();
    $prima = session(Sessione::CHIAVE);

    expect(Sessione::aggiorna(['utente' => ['id' => 'ALTRA-PERSONA', 'lingua' => 'es', 'nome' => 'Ana']]))->toBeFalse()
        ->and(session(Sessione::CHIAVE))->toBe($prima);
});

it('chiamato con gli stessi valori non cambia la sessione, e lo dice (T2.2)', function () {
    apriSessione();
    Sessione::aggiorna(ioMostra('en', 'Anne'));
    $prima = session(Sessione::CHIAVE);

    expect(Sessione::aggiorna(ioMostra('en', 'Anne')))->toBeFalse()
        ->and(session(Sessione::CHIAVE))->toBe($prima);

    // Un campo uguale e l'altro diverso: cambia solo quello che cambia.
    expect(Sessione::aggiorna(ioMostra('en', 'Annette')))->toBeTrue()
        ->and(session(Sessione::CHIAVE)['utente'])->toBe([...$prima['utente'], 'nome' => 'Annette']);
});

it('una lingua che non è una delle tre, o un nome vuoto, non sostituisce il valore che c\'è (T2.3)', function (mixed $lingua, mixed $nome) {
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
    'lingua fuori elenco' => ['fr', ''],
    'lingua in maiuscolo' => ['IT', ''],
]);

it('una lingua che non è una delle tre di Zeiras non entra nella sessione, e il nome valido sì (#1670, deve fallire se la lingua cambia)', function () {
    apriSessione();
    $lingua = Sessione::utente()['lingua'];

    expect(Sessione::aggiorna(ioMostra('fr', 'Anne')))->toBeTrue()
        ->and(Sessione::utente()['lingua'])->toBe($lingua)
        ->and(Sessione::utente()['nome'])->toBe('Anne');

    foreach (['it', 'en', 'es'] as $codice) {
        Sessione::aggiorna(ioMostra($codice, 'Anne'));
        expect(Sessione::utente()['lingua'])->toBe($codice);
    }
});

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

// #2214 (zr-home): dopo io.email.cambio.verifica.crea la sessione resterebbe con l'email vecchia, e la cornice e i controlli
// sugli inviti confronterebbero l'indirizzo sbagliato fino al prossimo accesso.

it('l\'email nuova di io.mostra entra nella sessione, con la data di verifica che la sessione tiene (#2214)', function () {
    apriSessione();
    $io = ioMostra('it', 'Anna');
    $io['utente']['email'] = 'nuova@example.com';
    $io['utente']['email_verificata_il'] = '2026-10-16T10:00:00.000Z';

    expect(Sessione::aggiorna($io))->toBeTrue()
        ->and(Sessione::utente()['email'])->toBe('nuova@example.com')
        ->and(Sessione::utente()['email_verificata_il'])->toBe('2026-10-16T10:00:00.000Z');
});

it('un\'email vuota, assente o non stringa non sostituisce quella che c\'è (#2214, deve fallire se la svuota)', function (mixed $email) {
    apriSessione();
    $prima = session(Sessione::CHIAVE);
    $io = ioMostra('it', 'Anna');
    $io['utente']['email'] = $email;

    expect(Sessione::aggiorna($io))->toBeFalse()
        ->and(session(Sessione::CHIAVE))->toBe($prima);
})->with(['vuota' => [''], 'spazi' => ['   '], 'null' => [null], 'numero' => [12], 'lista' => [['a@example.com']]]);

it('i dati di un\'altra persona non portano la sua email nella sessione (#2214)', function () {
    apriSessione();
    $prima = session(Sessione::CHIAVE);

    expect(Sessione::aggiorna(['utente' => ['id' => 'ALTRA-PERSONA', 'email' => 'altra@example.com']]))->toBeFalse()
        ->and(session(Sessione::CHIAVE))->toBe($prima);
});
