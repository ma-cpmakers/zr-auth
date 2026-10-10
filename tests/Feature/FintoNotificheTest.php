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

    expect(array_keys($uno))->toBe(['id', 'tipo', 'app', 'soggetto', 'dati', 'letta_il', 'creata_il'])
        ->and($uno['app'])->toBe('pm')
        ->and($uno['creata_il'])->toBe('2026-10-10T09:00:00.123Z')
        ->and(alFinto('GET', '/v1/io', null, $gettone)->json('data.notifiche_non_lette'))->toBe(1);

    $elenco = alFinto('GET', '/v1/io/notifiche', null, $gettone);

    expect($elenco->status())->toBe(200)
        ->and(array_column($elenco->json('data'), 'app'))->toBe([null, 'pm'])
        ->and($elenco->json('successivo'))->toBeNull();
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
