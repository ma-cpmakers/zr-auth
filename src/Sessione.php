<?php

namespace Zeiras\Auth;

use Illuminate\Session\CookieSessionHandler;
use Illuminate\Support\Carbon;
use Zeiras\Auth\Errori\SessioneNelBrowser;

/**
 * La sessione del frontend col gettone del backoffice. Il gettone sta solo qui, nella sessione lato server (il Redis del
 * frontend): mai nell'HTML, nelle props di Inertia o in un cookie leggibile (spec S01, «Il gettone»; prova 8). Nessun
 * metodo pubblico lo restituisce: lo legge solo il client delle API (Api).
 *
 * La sessione tiene due gettoni: quello dell'accesso (accessi.crea: i metodi della persona, gettoni.crea, l'uscita) e
 * quello del workspace in cui la persona è entrata (gettoni.crea: i metodi del workspace).
 */
final class Sessione
{
    /** La chiave della sessione sotto cui zr-auth tiene i suoi dati. */
    public const CHIAVE = 'zr-auth';

    /**
     * Apre la sessione coi `data` della risposta di accessi.crea (lo schema Accesso): l'accesso e il suo gettone, senza
     * workspace. L'id della sessione è nuovo: chi conosceva quello di prima non entra.
     *
     * @param  array<string, mixed>  $accesso
     */
    public static function apri(array $accesso): void
    {
        self::controllaIlDriver();
        $gettone = $accesso['gettone'];

        session()->regenerate();
        session()->put(self::CHIAVE, [
            'accesso' => $accesso['id'],
            'gettoni' => ['accesso' => $gettone['gettone']],
            'scade_il' => $gettone['scade_il'],
            'utente' => $gettone['utente'],
            'workspace' => null,
            'ruolo' => null,
        ]);
    }

    /**
     * Entra in un workspace coi `data` della risposta di gettoni.crea (lo schema Gettone): da qui Api::workspace() manda
     * il gettone di quel workspace. Il gettone dell'accesso, se c'è, resta.
     *
     * @param  array<string, mixed>  $gettone
     */
    public static function entra(array $gettone): void
    {
        self::controllaIlDriver();
        $stato = self::stato() ?? ['accesso' => null, 'gettoni' => []];

        session()->regenerate();
        session()->put(self::CHIAVE, [
            'accesso' => $stato['accesso'],
            'gettoni' => [...$stato['gettoni'], 'workspace' => $gettone['gettone']],
            'scade_il' => $gettone['scade_il'],
            'utente' => $gettone['utente'],
            'workspace' => $gettone['workspace'],
            'ruolo' => $gettone['ruolo'],
        ]);
    }

    /** Se c'è un gettone che non è ancora scaduto. */
    public static function aperta(): bool
    {
        $stato = self::stato();

        return $stato !== null && $stato['gettoni'] !== [] && Carbon::parse($stato['scade_il'])->isFuture();
    }

    /** @return array<string, mixed>|null la persona (lo schema Utente), senza gettone */
    public static function utente(): ?array
    {
        return self::aperta() ? self::stato()['utente'] : null;
    }

    /** @return array<string, mixed>|null il workspace in cui la persona è entrata */
    public static function workspace(): ?array
    {
        return self::aperta() ? self::stato()['workspace'] : null;
    }

    /** Il ruolo della persona nel workspace in cui è entrata: proprietario, amministratore o membro. */
    public static function ruolo(): ?string
    {
        return self::aperta() ? self::stato()['ruolo'] : null;
    }

    /** L'id dell'accesso, per uscire con accessi.elimina (DELETE /v1/accessi/{accesso}). */
    public static function accesso(): ?string
    {
        return self::aperta() ? self::stato()['accesso'] : null;
    }

    /** Chiude la sessione: i gettoni escono, e l'id della sessione è nuovo. */
    public static function chiudi(): void
    {
        session()->forget(self::CHIAVE);
        session()->regenerate();
        session()->regenerateToken();
    }

    /** @return array{accesso: ?string, gettoni: array<string, string>, scade_il: string, utente: array<string, mixed>, workspace: ?array<string, mixed>, ruolo: ?string}|null */
    private static function stato(): ?array
    {
        $stato = session(self::CHIAVE);

        return is_array($stato) ? $stato : null;
    }

    /** Con la sessione nel cookie il gettone finirebbe nel browser, anche se cifrato: zr-auth non lo scrive. */
    private static function controllaIlDriver(): void
    {
        if (config('session.driver') === 'cookie' || session()->getHandler() instanceof CookieSessionHandler) {
            throw new SessioneNelBrowser;
        }
    }
}
