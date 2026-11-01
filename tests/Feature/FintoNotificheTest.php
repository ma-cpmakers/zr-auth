<?php

use Carbon\CarbonImmutable;
use Zeiras\Auth\Testing\BackofficeFinto;

// #1459 T7 (T7.1, T7.2, G28): le notifiche nel finto. Qui ciò che un frontend vede dal suo lato (il seminatore, la campanella, la
// lettura una per una e in blocco, i tetti); che le risposte e gli errori siano quelli del backoffice, e che il gate 2 le passi,
// lo prova il FintoTest del backoffice (G12).

/**
 * Anna e Bruno nello stesso workspace: [finto, studio, anna, bruno, gettone di Anna nel workspace, gettone di Anna dell'accesso].
 *
 * @return array{BackofficeFinto, array<string, mixed>, array<string, mixed>, array<string, mixed>, string, string}
 */
function conLeNotifiche(): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $bruno = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $studio = $finto->workspace('Studio', $anna);
    $finto->membro($studio, $bruno, 'membro');
    $dellAccesso = entraNelFinto('anna@example.com')['gettone']['gettone'];
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $dellAccesso)->json('data.gettone');

    return [$finto, $studio, $anna, $bruno, $gettone, $dellAccesso];
}

it('il seminatore crea le notifiche della persona, e notifiche_non_lette di io.mostra le conta (T7.2)', function () {
    [$finto, $studio, $anna, , $gettone] = conLeNotifiche();

    expect(alFinto('GET', '/v1/io', null, $gettone)->json('data.notifiche_non_lette'))->toBe(0);

    $uno = $finto->notifica($studio, $anna, 'com.zeiras.board.scheda.creata', 'pm', CarbonImmutable::parse('2026-10-10 09:00:00.123'));
    $finto->notifica($studio, $anna, 'com.zeiras.altro.fatto', null, CarbonImmutable::parse('2026-10-10 10:00:00'), CarbonImmutable::parse('2026-10-10 10:05:00'));

    expect(array_keys($uno))->toBe(['id', 'tipo', 'app', 'soggetto', 'dati', 'letta_il', 'creata_il', 'quando', 'ultima_azione_il', 'azioni', 'autore', 'risorsa_nome', 'board', 'per_me'])
        ->and($uno['ultima_azione_il'])->toBe('2026-10-10T09:00:00.123Z')
        ->and($uno['azioni'])->toBe(1)
        ->and($uno['board'])->toBeNull()
        ->and($uno['app'])->toBe('pm')
        ->and($uno['creata_il'])->toBe('2026-10-10T09:00:00.123Z')
        ->and(alFinto('GET', '/v1/io', null, $gettone)->json('data.notifiche_non_lette'))->toBe(1);

    $elenco = alFinto('GET', '/v1/io/notifiche', null, $gettone);

    expect($elenco->status())->toBe(200)
        ->and(array_column($elenco->json('data'), 'app'))->toBe([null, 'pm'])
        ->and($elenco->json('successivo'))->toBeNull();
});

it("la notifica porta l'autore col nome di oggi, il nome della risorsa e per_me (#1670, deve fallire se l'ex membro è nominato)", function () {
    [$finto, $studio, $anna, $bruno, $gettone] = conLeNotifiche();
    $finto->notifica($studio, $anna, 'com.zeiras.board.cartella.creata', soggetto: '/v1/board/cartelle/01k6w2c4e6g8j0m2p4r6t8v0x2', autore: $bruno, risorsaNome: 'Clienti');
    $finto->notifica($studio, $anna, 'com.zeiras.altro.fatto');

    $elenco = alFinto('GET', '/v1/io/notifiche', null, $gettone)->json('data');
    $diBruno = array_values(array_filter($elenco, fn (array $n) => $n['risorsa_nome'] === 'Clienti'))[0];
    $diSistema = array_values(array_filter($elenco, fn (array $n) => $n['risorsa_nome'] === null))[0];

    expect($diBruno['autore'])->toBe(['nome' => 'Bruno'])
        ->and($diBruno['per_me'])->toBeNull()
        ->and($diSistema['autore'])->toBeNull()
        ->and($diSistema['per_me'])->toBeNull();
    expect(json_encode($diBruno['autore']))->not->toContain('@')->not->toContain($bruno['id']);

    // La risposta della lettura ha gli stessi campi.
    $letta = alFinto('PATCH', "/v1/io/notifiche/{$diBruno['id']}/lettura", ['letta' => true], $gettone)->json('data');
    expect($letta['autore'])->toBe(['nome' => 'Bruno'])->and($letta['risorsa_nome'])->toBe('Clienti')->and($letta['per_me'])->toBeNull();
});

it("una notifica accorpata si semina con azioni e ultima_azione_il, e l'elenco va per ultima azione (backoffice #1862, deve fallire se va per creata_il)", function () {
    [$finto, $studio, $anna, , $gettone] = conLeNotifiche();
    $board = ['id' => '01k6w2c4e6g8j0m2p4r6t8v0x2', 'nome' => 'Lancio'];
    $vecchia = $finto->notifica($studio, $anna, 'com.zeiras.board.lista.modificata', creataIl: CarbonImmutable::parse('2026-10-10 09:00:00'), board: $board, azioni: 2, ultimaAzioneIl: CarbonImmutable::parse('2026-10-10 11:00:00.250'));
    $nuova = $finto->notifica($studio, $anna, 'com.zeiras.board.scheda.creata', creataIl: CarbonImmutable::parse('2026-10-10 10:00:00'));

    $elenco = alFinto('GET', '/v1/io/notifiche', null, $gettone)->json('data');

    expect(array_column($elenco, 'id'))->toBe([$vecchia['id'], $nuova['id']])
        ->and($elenco[0]['azioni'])->toBe(2)
        ->and($elenco[0]['ultima_azione_il'])->toBe('2026-10-10T11:00:00.250Z')
        ->and($elenco[0]['creata_il'])->toBe('2026-10-10T09:00:00.000Z')
        ->and($elenco[0]['board'])->toBe($board)
        ->and($elenco[1]['ultima_azione_il'])->toBe($elenco[1]['creata_il']);
    expect(fn () => $finto->notifica($studio, $anna, 'com.zeiras.altro.fatto', azioni: 0))->toThrow(InvalidArgumentException::class);
});

it('un autore che non è del finto è un errore del test, e chi non è più membro non è nominato (#1670)', function () {
    [$finto, $studio, $anna] = conLeNotifiche();
    $estranea = $finto->persona('estranea@example.com', PASSWORD, nome: 'Estranea');

    // Una persona del finto ma non del workspace: la notifica nasce, e l'autore non è nominato (non è membra).
    $notifica = $finto->notifica($studio, $anna, 'com.zeiras.board.cartella.creata', autore: $estranea);

    expect($notifica['autore'])->toBeNull()
        ->and(fn () => $finto->notifica($studio, $anna, 'com.zeiras.board.cartella.creata', autore: ['id' => 'inesistente']))->toThrow(InvalidArgumentException::class, 'non è del finto');
});

it('segnare letta una notifica abbassa il conto di io.mostra, e segnarla non letta lo rialza (T7.2, deve fallire se il conto resta lo stesso)', function () {
    [$finto, $studio, $anna, , $gettone] = conLeNotifiche();
    $notifica = $finto->notifica($studio, $anna, 'com.zeiras.board.scheda.creata');

    $letta = alFinto('PATCH', "/v1/io/notifiche/{$notifica['id']}/lettura", ['letta' => true], $gettone);

    expect($letta->status())->toBe(200)
        ->and($letta->json('data.letta_il'))->not->toBeNull()
        ->and(alFinto('GET', '/v1/io', null, $gettone)->json('data.notifiche_non_lette'))->toBe(0);

    $istante = $letta->json('data.letta_il');
    $this->travel(5)->seconds();

    expect(alFinto('PATCH', "/v1/io/notifiche/{$notifica['id']}/lettura", ['letta' => true], $gettone)->json('data.letta_il'))->toBe($istante);

    $nonLetta = alFinto('PATCH', "/v1/io/notifiche/{$notifica['id']}/lettura", ['letta' => false], $gettone);

    expect($nonLetta->json('data.letta_il'))->toBeNull()
        ->and(alFinto('GET', '/v1/io', null, $gettone)->json('data.notifiche_non_lette'))->toBe(1);
});

it("la notifica di un'altra persona non si legge e non si segna: 404 non_trovato (T7.1, deve fallire se la segna)", function () {
    [$finto, $studio, , $bruno, $gettone] = conLeNotifiche();
    $diBruno = $finto->notifica($studio, $bruno, 'com.zeiras.board.scheda.creata');

    $risposta = alFinto('PATCH', "/v1/io/notifiche/{$diBruno['id']}/lettura", ['letta' => true], $gettone);

    expect($risposta->status())->toBe(404)
        ->and($risposta->json('codice'))->toBe('non_trovato')
        ->and(alFinto('GET', '/v1/io/notifiche', null, $gettone)->json('data'))->toBe([])
        ->and(alFinto('POST', '/v1/io/notifiche/letture', ['fino_a' => '2030-01-01T00:00:00Z'], $gettone)->json('data.segnate'))->toBe(0);
});

it('il gettone dell\'accesso non passa: 403 gettone_senza_workspace sulle tre rotte (T7.1, deve fallire se passa)', function () {
    [$finto, $studio, $anna, , , $dellAccesso] = conLeNotifiche();
    $notifica = $finto->notifica($studio, $anna, 'com.zeiras.board.scheda.creata');

    foreach ([['GET', '/v1/io/notifiche', null], ['POST', '/v1/io/notifiche/letture', ['fino_a' => '2030-01-01T00:00:00Z']], ['PATCH', "/v1/io/notifiche/{$notifica['id']}/lettura", ['letta' => true]]] as [$metodo, $percorso, $corpo]) {
        $risposta = alFinto($metodo, $percorso, $corpo, $dellAccesso);

        expect($risposta->status())->toBe(403)->and($risposta->json('codice'))->toBe('gettone_senza_workspace');
    }

    expect(alFinto('GET', '/v1/io', null, $dellAccesso)->json('data.notifiche_non_lette'))->toBeNull();
});

it('la lettura in blocco segna le non lette fino all\'istante, dice quante e se ne restano, e ripetuta non cambia niente (T7.1)', function () {
    [$finto, $studio, $anna, , $gettone] = conLeNotifiche();

    foreach (['09:00:00.100', '09:00:00.200', '10:00:00.000'] as $ora) {
        $finto->notifica($studio, $anna, 'com.zeiras.board.scheda.creata', creataIl: CarbonImmutable::parse("2026-10-10 {$ora}"));
    }

    $prima = alFinto('POST', '/v1/io/notifiche/letture', ['fino_a' => '2026-10-10T09:00:00.200Z'], $gettone);

    expect($prima->json('data'))->toBe(['fino_a' => '2026-10-10T09:00:00.200Z', 'segnate' => 2, 'altre' => false])
        ->and(alFinto('GET', '/v1/io', null, $gettone)->json('data.notifiche_non_lette'))->toBe(1)
        ->and(alFinto('POST', '/v1/io/notifiche/letture', ['fino_a' => '2026-10-10T09:00:00.200Z'], $gettone)->json('data.segnate'))->toBe(0)
        ->and(alFinto('POST', '/v1/io/notifiche/letture', ['fino_a' => '2026-10-10T12:00:00+02:00'], $gettone)->json('data'))->toBe(['fino_a' => '2026-10-10T10:00:00.000Z', 'segnate' => 1, 'altre' => false]);
});

it('la lettura in blocco ha il suo tetto: al più 5000 per chiamata, e altre dice di richiamare (T7.1)', function () {
    [$finto, $studio, $anna, , $gettone] = conLeNotifiche();

    foreach (range(1, 5001) as $n) {
        $finto->notifica($studio, $anna, 'com.zeiras.board.scheda.creata', creataIl: CarbonImmutable::parse('2026-10-10 09:00:00')->addMillis($n));
    }

    $prima = alFinto('POST', '/v1/io/notifiche/letture', ['fino_a' => '2030-01-01T00:00:00Z'], $gettone)->json('data');
    $seconda = alFinto('POST', '/v1/io/notifiche/letture', ['fino_a' => '2030-01-01T00:00:00Z'], $gettone)->json('data');

    expect($prima['segnate'])->toBe(5000)->and($prima['altre'])->toBeTrue()
        ->and($seconda['segnate'])->toBe(1)->and($seconda['altre'])->toBeFalse();
});

it('il seminatore rifiuta un\'app che il tipo non ha e una persona che non è del workspace', function () {
    [$finto, $studio, $anna] = conLeNotifiche();
    $estranea = $finto->persona('carla@example.com', PASSWORD, nome: 'Carla');

    expect(fn () => $finto->notifica($studio, $anna, 'com.zeiras.altro.fatto', 'pm'))->toThrow(InvalidArgumentException::class, 'dà il tipo')
        ->and(fn () => $finto->notifica($studio, $estranea, 'com.zeiras.board.scheda.creata'))->toThrow(InvalidArgumentException::class, 'non è del workspace');
});

// #2732 e #2733: le preferenze di notifica e «Non voglio più questa email». Che le risposte e gli errori siano quelli del backoffice lo prova il
// FintoTest del backoffice (G12); qui ciò che un frontend vede dal suo lato.
it('io.notifiche.preferenze.mostra: ogni tipo del catalogo con `app` se non si è scelto, e l\'email solo dove il tipo la ha (#2732)', function () {
    [, , , , $gettone, $dellAccesso] = conLeNotifiche();

    $risposta = alFinto('GET', '/v1/io/notifiche/preferenze', null, $gettone);
    $tipi = array_column($risposta->json('data.tipi'), null, 'tipo');

    expect($risposta->status())->toBe(200)
        ->and(array_keys($risposta->json('data')))->toBe(['tipi'])
        ->and(array_keys($risposta->json('data.tipi.0')))->toBe(['tipo', 'modulo', 'canale', 'canali'])
        ->and($risposta->json('data.tipi.0'))->toBe(['tipo' => 'com.zeiras.app.modificata', 'modulo' => 'workspace', 'canale' => 'app', 'canali' => ['app', 'spenta']])
        ->and($tipi['board.commento.menzione']['canali'])->toBe(['app', 'email', 'spenta'])
        ->and(array_unique(array_column($tipi, 'canale')))->toBe(['app'])
        // Della persona, non del workspace: vale anche il gettone dell'accesso.
        ->and(alFinto('GET', '/v1/io/notifiche/preferenze', null, $dellAccesso)->json('data'))->toBe($risposta->json('data'))
        ->and(alFinto('GET', '/v1/io/notifiche/preferenze')->status())->toBe(401);
});

it('io.notifiche.preferenze.modifica: cambia i canali dei tipi mandati, tutti o nessuno, e solo della persona del gettone (#2732)', function () {
    [, , , , $gettone] = conLeNotifiche();
    $bruno = entraNelFinto('bruno@example.com')['gettone']['gettone'];

    $risposta = alFinto('PATCH', '/v1/io/notifiche/preferenze', ['tipi' => [
        ['tipo' => 'board.scheda.assegnata', 'canale' => 'email'],
        ['tipo' => 'com.zeiras.board.scheda.creata', 'canale' => 'spenta'],
    ]], $gettone);
    $tipi = array_column($risposta->json('data.tipi'), 'canale', 'tipo');

    expect($risposta->status())->toBe(200)
        ->and($tipi['board.scheda.assegnata'])->toBe('email')
        ->and($tipi['com.zeiras.board.scheda.creata'])->toBe('spenta')
        ->and(array_count_values($tipi)['app'])->toBe(count($tipi) - 2)
        ->and(array_column(alFinto('GET', '/v1/io/notifiche/preferenze', null, $bruno)->json('data.tipi'), 'canale', 'tipo')['board.scheda.assegnata'])->toBe('app');

    // Un errore non salva niente: il tipo fuori dal catalogo, il doppione e il canale che il tipo non ha sono 422 sul pointer dell'elemento.
    $rifiutata = alFinto('PATCH', '/v1/io/notifiche/preferenze', ['tipi' => [
        ['tipo' => 'board.commento.menzione', 'canale' => 'spenta'],
        ['tipo' => 'non.esiste', 'canale' => 'app'],
        ['tipo' => 'board.commento.menzione', 'canale' => 'app'],
        ['tipo' => 'com.zeiras.board.scheda.modificata', 'canale' => 'email'],
    ]], $gettone);

    expect($rifiutata->status())->toBe(422)
        ->and(array_column($rifiutata->json('errors'), 'pointer'))->toContain('#/tipi/1/tipo', '#/tipi/3/canale')
        ->and(array_column(alFinto('GET', '/v1/io/notifiche/preferenze', null, $gettone)->json('data.tipi'), 'canale', 'tipo')['board.commento.menzione'])->toBe('app');
});

it('notifiche.disiscrizione: il token del finto spegne solo l\'email del suo tipo, senza gettone, e non si consuma (#2733)', function () {
    [$finto, , $anna, , $gettone] = conLeNotifiche();
    $tipo = 'board.commento.menzione';
    $token = $finto->tokenDiDisiscrizione($anna['id'], $tipo);
    $canale = fn () => array_column(alFinto('GET', '/v1/io/notifiche/preferenze', null, $gettone)->json('data.tipi'), 'canale', 'tipo')[$tipo];
    alFinto('PATCH', '/v1/io/notifiche/preferenze', ['tipi' => [['tipo' => $tipo, 'canale' => 'email']]], $gettone);

    $verifica = alFinto('POST', '/v1/notifiche/disiscrizione/verifica', ['token' => $token]);

    expect($verifica->status())->toBe(200)
        ->and($verifica->json('data'))->toBe(['tipo' => $tipo, 'canale' => 'email'])
        ->and($canale())->toBe('email');

    expect(alFinto('POST', '/v1/notifiche/disiscrizione', ['token' => $token])->status())->toBe(204)
        ->and($canale())->toBe('app')
        ->and(alFinto('POST', '/v1/notifiche/disiscrizione', ['token' => $token])->status())->toBe(204);

    // «spenta» non si tocca: la disiscrizione spegne l'email, non riaccende niente.
    alFinto('PATCH', '/v1/io/notifiche/preferenze', ['tipi' => [['tipo' => $tipo, 'canale' => 'spenta']]], $gettone);
    alFinto('POST', '/v1/notifiche/disiscrizione', ['token' => $token]);

    expect($canale())->toBe('spenta');

    foreach (['', 'abc', $token.'x', substr($token, 0, -3).'AAA'] as $sbagliato) {
        $rifiutato = alFinto('POST', '/v1/notifiche/disiscrizione', ['token' => $sbagliato]);

        expect($rifiutato->status())->toBe(422)
            ->and($rifiutato->json('errors.0.pointer'))->toBe('#/token');
    }
});
