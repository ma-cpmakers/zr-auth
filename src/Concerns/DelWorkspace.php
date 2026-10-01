<?php

namespace Zeiras\Auth\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Zeiras\Auth\Contesto;

/**
 * I dati del modulo sono di un workspace (voce #978): un modello col tratto, e una colonna `workspace_id`, legge solo le
 * righe del workspace della sessione — anche nei binding delle rotte: l'id di un altro workspace è un 404 —, una riga
 * nuova prende quel workspace, e una riga non cambia mai workspace: un aggiornamento che porta un altro `workspace_id` (un
 * campo assegnabile riempito coi dati della richiesta) lo lascia com'era, con o senza contesto. Senza workspace nel
 * contesto (console, coda) non trova nessuna riga: un job che lavora per un workspace apre il Contesto, o toglie lo scope
 * dichiarandolo (`withoutGlobalScope(DelWorkspace::class)`). Un `update()` di massa sul builder non passa dagli eventi del
 * modello: con lo scope tocca solo il workspace della sessione, ma non va mai scritto con un `workspace_id`.
 */
trait DelWorkspace
{
    public static function bootDelWorkspace(): void
    {
        static::addGlobalScope(DelWorkspace::class, function (Builder $query): void {
            $workspace = app(Contesto::class)->workspaceId();
            if ($workspace === null) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where($query->qualifyColumn('workspace_id'), $workspace);
            }
        });

        static::creating(function (Model $modello): void {
            $workspace = app(Contesto::class)->workspaceId();
            if ($workspace !== null) {
                $modello->setAttribute('workspace_id', $workspace);
            }
        });

        static::updating(function (Model $modello): void {
            if ($modello->isDirty('workspace_id')) {
                $modello->setAttribute('workspace_id', $modello->getOriginal('workspace_id'));
            }
        });
    }
}
