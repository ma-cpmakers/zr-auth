<?php

namespace Zeiras\Auth;

/**
 * Di chi è la sessione, nella forma che una pagina può portare nel browser (#2136). Una scheda rimasta ferma su A, dopo
 * che B è entrato nello stesso browser, scriverebbe col gettone di B: la pagina dice di chi è con questa impronta
 * (intestazione `X-Zr-Persona`), e SoloDallaPersona la confronta con quella della sessione. Un modulo HTML nativo non porta intestazioni: la
 * stessa impronta può stare nel campo `_zr_persona` del corpo (`CAMPO`), che vale solo se l'intestazione manca.
 */
final class Persona
{
    /** Il campo del corpo che porta l'impronta quando la richiesta è un modulo nativo (senza intestazioni): solo una stringa, mai nell'indirizzo. */
    public const CAMPO = '_zr_persona';

    /** L'intestazione con cui la pagina dice di chi è. */
    public const INTESTAZIONE = 'X-Zr-Persona';

    /**
     * L'impronta della persona della sessione: 32 cifre esadecimali, un HMAC dell'id con la chiave dell'app e un prefisso suo
     * (un altro HMAC della stessa chiave non la rifà). Né l'id né l'email escono; la stessa persona ha sempre la stessa impronta,
     * quindi rientrare non fa perdere il lavoro di una scheda aperta. `null` senza una sessione aperta, o se `utente.id` non è una stringa non vuota.
     */
    public static function impronta(): ?string
    {
        $id = Sessione::utente()['id'] ?? null;

        return is_string($id) && $id !== '' ? substr(hash_hmac('sha256', 'zr-auth.persona|'.$id, (string) config('app.key')), 0, 32) : null;
    }
}
