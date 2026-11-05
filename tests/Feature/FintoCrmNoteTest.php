<?php

// Chi toglie una nota nel finto (#3073): ciò che un frontend vede dal suo lato. Che le risposte e gli errori siano quelli del backoffice lo prova
// il suo FintoTest (confronta finto e backoffice sulle stesse chiamate).

it('una nota la toglie l\'autore, il proprietario o un amministratore; un membro che non è l\'autore ha 403 e la nota resta', function () {
    [, $gettoni] = studioColCrm();
    $persona = alFinto('POST', '/v1/crm/persone', ['nome' => 'Giulia', 'cognome' => 'Verdi'], $gettoni['anna'])->json('data.id');
    $nota = fn (string $chi, string $testo): string => alFinto('POST', '/v1/crm/note', ['persona_id' => $persona, 'testo' => $testo], $gettoni[$chi])->json('data.id');
    $ancora = fn (string $id): bool => in_array($id, array_column(alFinto('GET', "/v1/crm/note?persona_id={$persona}", null, $gettoni['anna'])->json('data'), 'id'), true);

    $diAnna = $nota('anna', 'Di Anna');
    $diCarla = $nota('carla', 'Di Carla');
    $diBruno = $nota('bruno', 'Di Bruno');

    expect(alFinto('DELETE', "/v1/crm/note/{$diAnna}", null, $gettoni['carla'])->json('codice'))->toBe('permesso_negato')
        ->and($ancora($diAnna))->toBeTrue()
        ->and(alFinto('DELETE', "/v1/crm/note/{$diCarla}", null, $gettoni['bruno'])->status())->toBe(204)
        ->and($ancora($diCarla))->toBeFalse()
        ->and(alFinto('DELETE', "/v1/crm/note/{$diBruno}", null, $gettoni['anna'])->status())->toBe(204)
        ->and(alFinto('DELETE', "/v1/crm/note/{$diAnna}", null, $gettoni['anna'])->status())->toBe(204)
        ->and($ancora($diAnna))->toBeFalse();

    $sua = $nota('carla', 'Un\'altra di Carla');

    expect(alFinto('DELETE', "/v1/crm/note/{$sua}", null, $gettoni['carla'])->status())->toBe(204);
});
