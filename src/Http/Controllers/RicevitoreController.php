<?php

namespace Zeiras\Auth\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\BackofficeNonRisponde;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Errori\GettoneRifiutato;
use Zeiras\Auth\Ingresso;
use Zeiras\Auth\Sessione;

/**
 * Il ricevitore del codice (`GET zr-auth.ricevitore`, di norma `/ingresso/ritorno`): dove zr-home rimanda la persona dopo
 * l'ingresso, con `codice` e `state`. Solo da un GET e dallo stesso sito (il codice di un «Accedi come», invece, da un POST: assistenza()); lo `state` deve essere quello della partenza e
 * vale una volta; il codice si scambia col verificatore (ingressi.scambio.crea) e dà la sessione del workspace. Senza `state` è il codice di un «Accedi come» dello
 * staff, che si scambia senza verificatore (entraInAssistenza). Si torna
 * a un indirizzo pulito: mai il codice nell'indirizzo dove la persona resta, e mai un indirizzo che viene dalla richiesta.
 */
final class RicevitoreController
{
    public function __invoke(Request $richiesta): RedirectResponse
    {
        $codice = $richiesta->query('codice');
        $codice = is_string($codice) && preg_match('/^[A-Za-z0-9_-]{43}$/', $codice) === 1 ? $codice : null;

        // Prima di toccare la sessione: un ritorno da un altro sito non consuma la partenza della persona. Il codice sì
        // (brucia()): chi lo ha mandato qui ha la sfida, e il codice varrebbe 60 secondi.
        if (! self::delloStessoSito($richiesta)) {
            self::brucia($codice);

            return self::errore();
        }

        // Lo state e il verificatore escono dalla sessione in ogni caso: valgono per un ritorno solo.
        $partenza = $richiesta->session()->pull(Ingresso::CHIAVE);
        $state = $richiesta->query('state');

        // Senza `state` non è il ritorno di una partenza. Il codice di un «Accedi come» (staff.utenti.impersonificazioni.crea) non viaggia più in un indirizzo (#3386): arriva con un POST
        // dal browser della persona, e lo riceve assistenza(). Un GET col codice e senza `state` non scambia niente: la pagina d'errore. Il codice però è già in un indirizzo
        // (log di un proxy, cronologia), quindi si brucia come ogni altro ritorno rifiutato.
        if ($state === null) {
            self::brucia($codice);

            return self::errore();
        }

        if (! is_array($partenza) || ! is_string($partenza['state'] ?? null) || ! is_string($partenza['verificatore'] ?? null)
            || $codice === null
            || ! is_string($state) || strlen($state) > 512 || preg_match('/^[A-Za-z0-9._~-]+$/', $state) !== 1
            || ! hash_equals($partenza['state'], $state)) {
            self::brucia($codice);

            return self::errore();
        }

        try {
            $risposta = Api::senzaGettone()->post('/v1/ingressi/scambio', ['codice' => $codice, 'verificatore' => $partenza['verificatore']]);
        } catch (ErroreApi $errore) {
            // Un 5xx non è un codice rifiutato: è il backoffice che non risponde. Il resto (422 verifica_non_riuscita, 429) è
            // la stessa pagina per tutti i motivi.
            if ($errore->stato >= 500) {
                throw new BackofficeNonRisponde('Il backoffice non ha scambiato il codice.', previous: $errore);
            }

            return self::errore();
        }

        // Il gettone che torna dev'essere quello della partenza: un cliente (Ingresso::versoCliente) non ha workspace, e una partenza in un
        // workspace non si chiude con un gettone senza. L'altro caso non apre niente.
        $cliente = ($partenza['cliente'] ?? false) === true;
        $gettone = self::gettone($risposta, $cliente);

        if ($gettone === null) {
            return self::errore();
        }

        $cliente ? Sessione::entraCliente($gettone) : Sessione::entra($gettone);

        return redirect()->away(Sessione::ritorno(url((string) config('zr-auth.dopo'))))->withHeaders(Ingresso::INTESTAZIONI);
    }

    /**
     * Il codice di un «Accedi come» (staff.utenti.impersonificazioni.crea, #2914 F4.1) arriva con un POST auto-inviato dalla pagina di zr-superadmin, nel corpo e mai in un indirizzo (#3386):
     * non finisce nella cronologia del browser né nel log di un proxy, nemmeno per i 60 secondi in cui vale. Lo scambio vuole una richiesta del browser dello stesso sito: `Sec-Fetch-Site: same-site`
     * (un altro sito manda `cross-site`, una navigazione diretta `none`; un client fuori dal browser le intestazioni le può scrivere come vuole: contro un codice trapelato proteggono
     * solo «mai in un indirizzo», i 60 secondi e il monouso) e `Origin` quello di zr-superadmin (`zr-auth.admin`), o `null`, che il browser manda quando la
     * pagina che consegna il codice ha `Referrer-Policy: no-referrer`. Il resto della legatura non cambia: il codice è del client firmato di questo modulo, vale una volta e 60 secondi.
     * Senza CSRF: arriva da un altro sito per costruzione, e il controllo del sito prende il suo posto. Si risponde con un 303 a un indirizzo pulito.
     */
    public function assistenza(Request $richiesta): RedirectResponse
    {
        $codice = $richiesta->request->get('codice');
        $codice = is_string($codice) && preg_match('/^[A-Za-z0-9_-]{43}$/', $codice) === 1 ? $codice : null;

        if (! self::dalPaginaDellaStaff($richiesta)) {
            self::brucia($codice);

            return self::errore();
        }

        return self::entraInAssistenza($codice);
    }

    /**
     * L'ingresso di un «Accedi come»: il codice si scambia senza verificatore e dà la sessione del workspace dell'assistenza. Il gettone è di assistenza: la fascia
     * «stai assistendo…» la legge il modulo con `accessi.corrente.mostra`, e il «Termina» è `accessi.corrente.elimina`. Un codice che non ha la forma, o uno scambio
     * che non riesce (un altro client, scaduto, già usato, un codice ordinario), è la stessa pagina d'errore di ogni ritorno rifiutato.
     */
    private static function entraInAssistenza(?string $codice): RedirectResponse
    {
        if ($codice === null) {
            return self::errore();
        }

        try {
            $risposta = Api::senzaGettone()->post('/v1/ingressi/scambio', ['codice' => $codice]);
        } catch (ErroreApi $errore) {
            if ($errore->stato >= 500) {
                throw new BackofficeNonRisponde('Il backoffice non ha scambiato il codice.', previous: $errore);
            }

            return self::errore();
        }

        $gettone = self::gettone($risposta, false);

        if ($gettone === null) {
            return self::errore();
        }

        Sessione::entraInAssistenza($gettone);

        return redirect()->away(Sessione::ritorno(url((string) config('zr-auth.dopo'))), 303)->withHeaders(Ingresso::INTESTAZIONI);
    }

    /**
     * Un ritorno rifiutato brucia il suo codice: uno scambio a vuoto con un verificatore che nessuno ha, che il backoffice
     * tratta come «verificatore sbagliato» e per questo consuma il codice (ingressi.scambio.crea, nessun metodo nuovo). Chi
     * ha la sfida di quel codice è chi ha mandato la persona qui, e non deve poterlo scambiare se l'indirizzo trapela.
     * Un codice senza la forma giusta non parte. L'esito non conta: un 429, un 5xx o il trasporto che cade non cambiano la
     * risposta alla persona, che è già la pagina d'errore. La sessione non si tocca.
     */
    private static function brucia(?string $codice): void
    {
        if ($codice === null) {
            return;
        }

        try {
            Api::senzaGettone()->post('/v1/ingressi/scambio', ['codice' => $codice, 'verificatore' => bin2hex(random_bytes(32))]);
        } catch (ErroreApi|BackofficeNonRisponde|GettoneRifiutato) {
            // Il codice è bruciato o non c'è più da bruciare: la persona vede la stessa pagina in ogni caso.
        }
    }

    /** Il POST del codice di assistenza viene dal browser della persona, dalla pagina di zr-superadmin: Sec-Fetch-Site e Origin ci sono, e lo dicono. */
    private static function dalPaginaDellaStaff(Request $richiesta): bool
    {
        if (strtolower((string) $richiesta->headers->get('Sec-Fetch-Site')) !== 'same-site') {
            return false;
        }

        $origine = $richiesta->headers->get('Origin');

        return $origine !== null && ($origine === 'null' || rtrim($origine, '/') === rtrim((string) config('zr-auth.admin'), '/'));
    }

    /** Il ritorno viene dal browser della persona, da zr-home: Sec-Fetch-Site e Origin, quando ci sono, lo dicono. */
    private static function delloStessoSito(Request $richiesta): bool
    {
        $sito = $richiesta->headers->get('Sec-Fetch-Site');

        if ($sito !== null && ! in_array(strtolower($sito), ['same-site', 'same-origin', 'none'], true)) {
            return false;
        }

        $origine = $richiesta->headers->get('Origin');

        return $origine === null
            || in_array(rtrim($origine, '/'), [$richiesta->getSchemeAndHttpHost(), rtrim((string) config('zr-auth.home'), '/')], true);
    }

    /**
     * I `data` di ingressi.scambio.crea (lo schema Gettone), se hanno la forma: mai una sessione a metà. Il gettone di un cliente ha
     * `workspace` e `ruolo` null, quello di un workspace li ha tutti e due: se la partenza era dell'altro tipo, null.
     *
     * @param  array<mixed>  $risposta
     * @return array<string, mixed>|null
     */
    private static function gettone(array $risposta, bool $cliente): ?array
    {
        $data = $risposta['data'] ?? null;
        $senzaWorkspace = is_array($data) && array_key_exists('workspace', $data) && $data['workspace'] === null
            && array_key_exists('ruolo', $data) && $data['ruolo'] === null;
        $inUnWorkspace = is_array($data) && is_array($data['workspace'] ?? null) && is_string($data['ruolo'] ?? null);

        if (! is_array($data) || ! is_string($data['gettone'] ?? null) || ! is_string($data['scade_il'] ?? null)
            || ! is_array($data['utente'] ?? null) || ! ($senzaWorkspace || $inUnWorkspace)) {
            throw new BackofficeNonRisponde('La risposta di ingressi.scambio.crea non ha la forma di un gettone.');
        }

        return $senzaWorkspace === $cliente ? $data : null;
    }

    /** La pagina d'errore del modulo: la stessa per ogni motivo, che a chi arriva da fuori non si dice quale. */
    private static function errore(): RedirectResponse
    {
        $pagina = config('zr-auth.errore') ?? config('zr-auth.ingresso');

        return redirect()->away((string) $pagina)->withHeaders(Ingresso::INTESTAZIONI);
    }
}
