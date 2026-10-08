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
 * l'ingresso, con `codice` e `state`. Solo da un GET e dallo stesso sito; lo `state` deve essere quello della partenza e
 * vale una volta; il codice si scambia col verificatore (ingressi.scambio.crea) e dà la sessione del workspace. Si torna
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

        Sessione::entra(self::gettone($risposta));

        return redirect()->away(Sessione::ritorno(url((string) config('zr-auth.dopo'))))->withHeaders(Ingresso::INTESTAZIONI);
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
     * I `data` di ingressi.scambio.crea (lo schema Gettone), se hanno la forma: mai una sessione a metà.
     *
     * @param  array<mixed>  $risposta
     * @return array<string, mixed>
     */
    private static function gettone(array $risposta): array
    {
        $data = $risposta['data'] ?? null;

        if (! is_array($data) || ! is_string($data['gettone'] ?? null) || ! is_string($data['scade_il'] ?? null)
            || ! is_array($data['utente'] ?? null) || ! is_array($data['workspace'] ?? null) || ! is_string($data['ruolo'] ?? null)) {
            throw new BackofficeNonRisponde('La risposta di ingressi.scambio.crea non ha la forma di un gettone.');
        }

        return $data;
    }

    /** La pagina d'errore del modulo: la stessa per ogni motivo, che a chi arriva da fuori non si dice quale. */
    private static function errore(): RedirectResponse
    {
        $pagina = config('zr-auth.errore') ?? config('zr-auth.ingresso');

        return redirect()->away((string) $pagina)->withHeaders(Ingresso::INTESTAZIONI);
    }
}
