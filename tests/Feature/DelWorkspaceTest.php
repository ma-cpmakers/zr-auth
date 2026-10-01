<?php

use Illuminate\Support\Facades\DB;
use Zeiras\Auth\Tests\Nota;

/*
 * I dati del modulo separati per workspace (voce #978, T3.6, prova 7 della spec): un modello col tratto DelWorkspace vede
 * solo il workspace della sessione.
 */

beforeEach(function () {
    DB::table('note')->insert([
        ['id' => 1, 'workspace_id' => 7, 'testo' => 'di Ventiquattro'],
        ['id' => 2, 'workspace_id' => 8, 'testo' => 'di Ottavo'],
    ]);
});

it('un modello DelWorkspace trova solo le righe del workspace della sessione, anche cambiando l\'id nell\'indirizzo (T3.6, prova 7)', function () {
    entra()->assertRedirect('/pagina');

    $this->get('/note')->assertOk()->assertExactJson(['di Ventiquattro']);
    $this->get('/note/1')->assertOk()->assertExactJson(['testo' => 'di Ventiquattro']);
    $this->get('/note/2')->assertNotFound();
});

it('una riga nuova prende il workspace della sessione, anche se la richiesta ne porta un altro (T3.6)', function () {
    entra()->assertRedirect('/pagina');

    $this->post('/note', ['testo' => 'nuova', 'workspace_id' => 8])->assertSuccessful()->assertJsonPath('workspace_id', 7);

    expect(DB::table('note')->where('testo', 'nuova')->value('workspace_id'))->toBe(7);
});

it('senza workspace nel contesto (console, coda) un modello DelWorkspace non trova nessuna riga (T3.6)', function () {
    expect(Nota::query()->count())->toBe(0)
        ->and(Nota::query()->find(1))->toBeNull()
        ->and(Nota::query()->withoutGlobalScopes()->count())->toBe(2);
});
