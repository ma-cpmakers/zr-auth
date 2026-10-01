<?php

namespace Zeiras\Auth;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Una revoca chiesta da un avviso di zr-home (voce #979): chiude, alla loro richiesta successiva, le sessioni del modulo
 * aperte prima che arrivasse — quelle di una sessione di zr-home (`sid`), di una persona in un workspace (`sub` e
 * `workspace`), di un workspace (solo `workspace`). L'ordine è l'id: la sessione ricorda l'ultima revoca arrivata prima
 * del suo ingresso, e per lei valgono solo quelle dopo. Si tengono quanto vale una sessione.
 *
 * @property int $id
 * @property string|null $sid
 * @property int|null $sub
 * @property int|null $workspace
 * @property string|null $motivo
 */
class Revoca extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'zr_revoche';

    protected $casts = ['sub' => 'integer', 'workspace' => 'integer'];

    /** L'ultima revoca arrivata, 0 se nessuna. */
    public static function ultima(): int
    {
        return (int) self::query()->max('id');
    }

    /**
     * Registra la revoca di un avviso, e toglie quelle più vecchie della durata di una sessione: le sessioni aperte prima
     * di loro sono scadute comunque.
     *
     * @param  array{sid: string|null, sub: int|null, workspace: int|null, motivo: string|null}  $revoca
     */
    public static function registra(array $revoca): void
    {
        self::query()->where('created_at', '<', now()->subHours((int) config('zr-auth.ore')))->delete();
        (new self)->forceFill($revoca)->save();
    }

    /**
     * Se una revoca arrivata dopo l'ingresso di questa sessione la chiude.
     *
     * @param  array{sub: int, sid: string, workspace: array{id: int, nome: string}, ruolo: string, inizio: int, revoca: int}  $sessione
     */
    public static function chiude(array $sessione): bool
    {
        $workspace = $sessione['workspace']['id'];

        return self::query()
            ->where('id', '>', $sessione['revoca'])
            ->where(fn (Builder $revoche) => $revoche
                ->where('sid', $sessione['sid'])
                ->orWhere(fn (Builder $persona) => $persona->where('sub', $sessione['sub'])->where('workspace', $workspace))
                ->orWhere(fn (Builder $tutte) => $tutte->whereNull('sid')->whereNull('sub')->where('workspace', $workspace)))
            ->exists();
    }
}
