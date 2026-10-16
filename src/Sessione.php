<?php

namespace Zeiras\Auth;

use Illuminate\Session\CookieSessionHandler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
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
     * Il blocco della sessione (`Route::block`, `->bloccaSessione()`): per quanti secondi una richiesta tiene il lock della
     * sessione, e per quanti al più ne aspetta un altro. L'attesa è corta apposta: il pool PHP-FPM è uno solo e condiviso, e
     * un processo che aspetta manca a tutti i siti.
     */
    public const BLOCCO_TENUTA = 10;

    public const BLOCCO_ATTESA = 3;

    /** Il prefisso dei segni di una sessione chiusa (nello store di cache del blocco): vedi segnaLaVecchia(). */
    private const CHIUSA = 'zr-auth:sessione-chiusa:';

    /** Gli attributi della richiesta: è tardiva (la sua sessione era già chiusa), e gli id che ha chiuso lei (SessioneChiusa li legge). */
    public const TARDIVA = 'zr-auth.tardiva';

    public const MIE = 'zr-auth.chiuse-da-me';

    /** Le lingue di Zeiras (config/lingue.php del backoffice): la lingua del profilo che la sessione prende è una di queste. */
    public const LINGUE = ['it', 'en', 'es'];

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
        // Il segno prima del cambio: se la cache non risponde, l'ingresso fallisce e la sessione resta com'era.
        self::segnaLaVecchia();

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

        // Il segno prima del cambio: se la cache non risponde, l'ingresso fallisce e la sessione resta com'era.
        self::segnaLaVecchia();

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

    /**
     * Lascia il workspace: toglie dalla sessione il workspace, il ruolo e il gettone del workspace, e tiene l'accesso, il suo
     * gettone, la persona e la scadenza, come subito dopo `apri()`. Serve dopo `workspace.uscita.crea` (o quando il workspace è
     * sparito) a chi non ha un altro workspace in cui far entrare la persona: da qui `Api::persona()` chiama col gettone
     * dell'accesso e `Api::workspace()` è come senza workspace. L'id della sessione è nuovo e quella di prima è distrutta, come
     * per `entra()`. Senza una sessione aperta non fa niente e non lancia; se la sessione non ha un gettone dell'accesso (è nata dal
     * ricevitore dell'ingresso) non c'è dove tornare, e si chiude come `chiudi()`.
     */
    public static function lascia(): void
    {
        $stato = self::stato();

        if ($stato === null || ! self::aperta()) {
            return;
        }

        // Entrata solo dal ricevitore dell'ingresso (`entra()` senza `apri()`): non c'è un gettone dell'accesso da tenere, e togliere
        // quello del workspace lascerebbe una sessione senza gettoni. Allora si chiude, come `chiudi()`.
        if (! isset($stato['gettoni']['accesso'])) {
            self::chiudi();

            return;
        }

        self::controllaIlDriver();
        // Il segno prima del cambio: se la cache non risponde, la sessione resta com'era.
        self::segnaLaVecchia();

        session()->regenerate(true);
        session()->put(self::CHIAVE, [
            ...$stato,
            'gettoni' => array_intersect_key($stato['gettoni'], ['accesso' => true]),
            'workspace' => null,
            'ruolo' => null,
        ]);
    }

    /**
     * Rimette la lingua, il nome e l'email della sessione uguali a quelli di io.mostra (#1473, #2214): la lingua scelta nel
     * profilo e l'email cambiata con io.email.cambio.verifica.crea non arrivano ai moduli da soli, perché la sessione li prende
     * all'ingresso e non li rilegge. Una cornice che legge io.mostra a ogni pagina la chiama con i suoi `data` quando sono
     * diversi da `Sessione::utente()`. Cambia solo `lingua`, `nome` ed `email` (e `email_verificata_il`, se la sessione lo tiene),
     * e solo se la lingua è una di `LINGUE` e il resto una stringa non vuota, diversi da quelli che ci sono: l'id della persona,
     * il gettone, il workspace, il ruolo e ogni altro campo restano, e a valori uguali non cambia niente. Se i dati non portano l'`utente.id` della sessione
     * (mancante o di un'altra persona) non li prende. Senza una sessione aperta non fa niente e non lancia.
     * Torna se ha cambiato qualcosa.
     *
     * @param  array<string, mixed>  $io  i `data` di io.mostra
     */
    public static function aggiorna(array $io): bool
    {
        if (! self::aperta()) {
            return false;
        }

        $persona = is_array($io['utente'] ?? null) ? $io['utente'] : [];

        // io.mostra porta sempre l'id: dati senza id, o con quello di un'altra persona, non sono della persona della sessione.
        if (! is_string($persona['id'] ?? null) || $persona['id'] !== (self::stato()['utente']['id'] ?? null)) {
            return false;
        }

        $cambiato = false;

        $campi = ['lingua', 'nome', 'email'];

        if (array_key_exists('email_verificata_il', self::stato()['utente'] ?? [])) {
            $campi[] = 'email_verificata_il';
        }

        foreach ($campi as $campo) {
            $valore = $persona[$campo] ?? null;
            $valido = $campo === 'lingua' ? in_array($valore, self::LINGUE, true) : is_string($valore) && trim($valore) !== '';

            if ($valido && $valore !== (self::stato()['utente'][$campo] ?? null)) {
                session()->put(self::CHIAVE.'.utente.'.$campo, $valore);
                $cambiato = true;
            }
        }

        return $cambiato;
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
     * da un GET, o dal Referer di una scrittura, Ritorno::dalReferer) se ha l'origine della richiesta, cioè lo stesso schema, lo stesso host e la stessa porta; se no
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
        self::segnaLaVecchia();

        session()->invalidate();
        session()->regenerateToken();
    }

    /**
     * Segna chiusa la sessione di questa richiesta, che un ingresso, un cambio di workspace o un'uscita stanno per distruggere
     * (#2044), se è la sessione di una persona: una sessione anonima non ha niente da far rivivere.
     * Laravel riscrive la sessione, col suo cookie, a ogni risposta: una risposta lenta partita prima del cambio, finendo dopo,
     * la ricreerebbe e rimanderebbe al browser il cookie di prima, mentre nel browser c'è ormai la sessione di un'altra persona.
     * Il segno sta nello store di cache del blocco (`session.block_store`, quello del modulo se non lo cambi: lo vedono tutti i
     * processi), dura `zr-auth.sessione_chiusa_secondi` e l'id non ci sta in chiaro. Lo legge SessioneChiusa. Se la cache non
     * risponde, o rifiuta la scrittura, lancia: il cambio non avviene. Una cache svuotata perde i segni: per i secondi di una
     * risposta ancora in volo torna il comportamento di prima.
     */
    private static function segnaLaVecchia(): void
    {
        if (! session()->has(self::CHIAVE)) {
            return;
        }

        $id = session()->getId();
        $chiave = self::chiave($id);
        $cache = Cache::store(config('session.block_store'));
        $richiesta = request();

        if (! $cache->add($chiave, true, max(1, (int) config('zr-auth.sessione_chiusa_secondi', 300)))) {
            // `add` non scrive se il segno c'è già (chiusa da un'altra richiesta: questa è tardiva, e SessioneChiusa non lascerà
            // uscire il suo cookie, qualunque cosa faccia) e torna falso anche se la cache rifiuta la scrittura (il cambio non avviene).
            if (! $cache->has($chiave)) {
                throw new RuntimeException('La cache non ha scritto il segno della sessione chiusa: il cambio non avviene.');
            }

            $richiesta->attributes->set(self::TARDIVA, true);

            return;
        }

        $richiesta->attributes->set(self::MIE, [...(array) $richiesta->attributes->get(self::MIE, []), $id]);
    }

    private static function chiave(string $id): string
    {
        return self::CHIUSA.hash('sha256', $id);
    }

    /** Vero se la sessione con questo id è stata chiusa da un ingresso, un cambio di workspace o un'uscita (segnaLaVecchia). */
    public static function eChiusa(string $id): bool
    {
        return Cache::store(config('session.block_store'))->has(self::chiave($id));
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
