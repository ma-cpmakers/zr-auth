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

it('una riga resta nel suo workspace, anche se l\'aggiornamento ne porta un altro (T3.6, review A1)', function () {
    entra()->assertRedirect('/pagina');

    $this->put('/note/1', ['testo' => 'cambiata', 'workspace_id' => 8])->assertOk()
        ->assertExactJson(['workspace_id' => 7, 'testo' => 'cambiata']);

    expect(DB::table('note')->where('id', 1)->value('workspace_id'))->toBe(7)
        ->and(DB::table('note')->where('id', 1)->value('testo'))->toBe('cambiata')
        ->and(DB::table('note')->where('workspace_id', 8)->pluck('testo')->all())->toBe(['di Ottavo']);
});

it('una riga resta nel suo workspace anche senza contesto (un job che toglie lo scope) (T3.6, review A1)', function () {
    Nota::query()->withoutGlobalScopes()->findOrFail(2)->update(['workspace_id' => 7]);

    expect(DB::table('note')->where('id', 2)->value('workspace_id'))->toBe(8);
});

it('senza workspace nel contesto (console, coda) un modello DelWorkspace non trova nessuna riga (T3.6)', function () {
    expect(Nota::query()->count())->toBe(0)
        ->and(Nota::query()->find(1))->toBeNull()
        ->and(Nota::query()->withoutGlobalScopes()->count())->toBe(2);
});
