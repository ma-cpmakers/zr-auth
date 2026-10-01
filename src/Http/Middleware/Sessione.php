<?php

namespace Zeiras\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Zeiras\Auth\Contesto;
use Zeiras\Auth\Ingresso;
use Zeiras\Auth\Revoca;

/**
 * La sessione del modulo (voce #978), nei gruppi `web` e `api`. Senza, una pagina rimanda all'ingresso di zr-home (una
 * visita di Inertia col 409), una richiesta JSON o un'API rispondono 401. Vale al massimo `zr-auth.ore` ore ed è di un
 * workspace solo: scaduta, con un altro `workspace` nell'indirizzo, o chiusa da un avviso di zr-home (Revoca), l'ingresso si
 * rifà in silenzio (`prompt=none`).
 */
final class Sessione
{
    public function __construct(private readonly Contesto $contesto, private readonly Ingresso $ingresso) {}

    public function handle(Request $richiesta, Closure $next): Response
    {
        $this->contesto->chiudi();
        $sessione = $richiesta->hasSession() ? $richiesta->session()->get(Ingresso::SESSIONE) : null;
        $sessione = is_array($sessione) && self::completa($sessione) ? $sessione : null;
        $chiesto = self::workspaceChiesto($richiesta);

        if ($sessione !== null && self::vale($sessione, $chiesto) && ! Revoca::chiude($sessione)) {
            $this->contesto->apri($sessione['sub'], $sessione['workspace']['id'], $sessione['workspace']['nome'], $sessione['ruolo']);

            return $next($richiesta);
        }

        if (! $richiesta->hasSession() || $richiesta->expectsJson()) {
            abort(401);
        }

        $indirizzo = $this->ingresso->comincia(
            $richiesta,
            $chiesto ?? $sessione['workspace']['id'] ?? null,
            silenzioso: $sessione !== null,
        );

        return $richiesta->hasHeader('X-Inertia')
            ? response('', 409, ['X-Inertia-Location' => $indirizzo])
            : redirect()->away($indirizzo);
    }

    /**
     * @param  array<mixed>  $sessione
     *
     * @phpstan-assert-if-true array{sub: int, sid: string, workspace: array{id: int, nome: string}, ruolo: string, inizio: int, revoca: int} $sessione
     */
    private static function completa(array $sessione): bool
    {
        return is_int($sessione['sub'] ?? null)
            && is_string($sessione['sid'] ?? null)
            && is_int($sessione['workspace']['id'] ?? null)
            && is_string($sessione['workspace']['nome'] ?? null)
            && is_string($sessione['ruolo'] ?? null)
            && is_int($sessione['inizio'] ?? null)
            && is_int($sessione['revoca'] ?? null);
    }

    /**
     * Aperta da meno di `zr-auth.ore` ore, e del workspace che l'indirizzo chiede, se ne chiede uno.
     *
     * @param  array{sub: int, sid: string, workspace: array{id: int, nome: string}, ruolo: string, inizio: int, revoca: int}  $sessione
     */
    private static function vale(array $sessione, ?int $chiesto): bool
    {
        return now()->getTimestamp() < $sessione['inizio'] + (int) config('zr-auth.ore') * 3600
            && ($chiesto === null || $chiesto === $sessione['workspace']['id']);
    }

    /** Il workspace che l'indirizzo chiede (`?workspace=<id>`), se è un id. */
    private static function workspaceChiesto(Request $richiesta): ?int
    {
        $workspace = $richiesta->query('workspace');

        return is_string($workspace) && preg_match('/^[1-9][0-9]{0,17}$/', $workspace) === 1 ? (int) $workspace : null;
    }
}
