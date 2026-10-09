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
     * workspace. L'id della sessione è nuovo e quella di prima è distrutta: chi conosceva l'id di prima non entra.
     *
     * @param  array<string, mixed>  $accesso
     */
    public static function apri(array $accesso): void
    {
        self::controllaIlDriver();
        $gettone = $accesso['gettone'];

        session()->regenerate(true);
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
     * il gettone di quel workspace. Il gettone dell'accesso, se c'è, resta, ma solo se è della stessa persona: se lo scambio
     * dà un'altra persona (il ricevitore dell'ingresso), l'accesso e i gettoni di prima si scartano.
     *
     * @param  array<string, mixed>  $gettone
     */
    public static function entra(array $gettone): void
    {
        self::controllaIlDriver();
        $stato = self::stato() ?? ['accesso' => null, 'gettoni' => []];

        if (isset($stato['utente']['id']) && $stato['utente']['id'] !== ($gettone['utente']['id'] ?? null)) {
            $stato = ['accesso' => null, 'gettoni' => []];
        }

        session()->regenerate(true);
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

    /**
     * L'id dell'accesso, se la sessione lo conosce: per chiudere un altro accesso con accessi.elimina (DELETE
     * /v1/accessi/{accesso}). Per uscire non serve: accessi.corrente.elimina (DELETE /v1/accessi/corrente) chiude quello della sessione.
     */
    public static function accesso(): ?string
    {
        return self::aperta() ? self::stato()['accesso'] : null;
    }

    /**
     * Dove tornare dopo l'accesso, al posto di redirect()->intended(): la pagina che la guardia ha ricordato (`url.intended`,
     * solo da un GET) se ha l'origine della richiesta, cioè lo stesso schema, lo stesso host e la stessa porta; se no
     * `$predefinita`, la pagina che sceglie il modulo. Il ritorno esce dalla sessione in ogni caso: vale una volta.
     */
    public static function ritorno(string $predefinita): string
    {
        $ritorno = session()->pull('url.intended');
        $origine = request()->getSchemeAndHttpHost();

        // Dopo l'origine viene il percorso, la query, il frammento o niente: così «https://sito.evil.example»,
        // «https://sito@evil.example» e «https://evil.example\@sito» (che il browser legge con l'host evil.example) sono di
        // un altro host, e un indirizzo relativo come «//evil.example/» non comincia con l'origine.
        if (is_string($ritorno) && str_starts_with($ritorno, $origine)
            && in_array(substr($ritorno, strlen($origine), 1), ['', '/', '?', '#'], true)) {
            return $ritorno;
        }

        return $predefinita;
    }

    /**
     * Chiude la sessione, come il logout di Laravel: escono i gettoni e ogni altro dato della sessione, che non passa a chi
     * entra dopo dallo stesso browser; l'id e il token CSRF sono nuovi, e la sessione di prima è distrutta: chi ha il cookie
     * di prima dell'uscita non la riapre.
     */
    public static function chiudi(): void
    {
        session()->invalidate();
        session()->regenerateToken();
    }

    /** @return array{accesso: ?string, gettoni: array<string, string>, scade_il: string, utente: array<string, mixed>, workspace: ?array<string, mixed>, ruolo: ?string}|null */
    private static function stato(): ?array
    {
        $stato = session(self::CHIAVE);

        return is_array($stato) ? $stato : null;
    }

    /** Con la sessione nel cookie il gettone finirebbe nel browser, anche se cifrato: zr-auth non lo scrive. */
    public static function controllaIlDriver(): void
    {
        if (config('session.driver') === 'cookie' || session()->getHandler() instanceof CookieSessionHandler) {
            throw new SessioneNelBrowser;
        }
    }
}
