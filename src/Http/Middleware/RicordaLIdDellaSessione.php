<?php

namespace Zeiras\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Zeiras\Auth\Sessione;

/**
 * Ricorda l'id con cui la sessione è partita (#2044): SessioneChiusa lo confronta con i segni delle sessioni chiuse a fine corsa,
 * anche se la richiesta ha cambiato id da sé (`Sessione::entra`, o un `session()->regenerate()` del modulo). Sta nel gruppo `web`
 * dopo StartSession (ZrAuthServiceProvider lo mette da sé). Ricorda anche, a inizio richiesta, se la persona della sessione è
 * un'identità di prova (#2157): a fine corsa la richiesta può aver chiuso la sessione o cambiato persona, e la riga di log di
 * SessioneChiusa parla della persona della sessione che si chiude, o di quella che la richiesta ha fatto entrare (#2241).
 */
final class RicordaLIdDellaSessione
{
    public const ATTRIBUTO = 'zr-auth.id-iniziale';

    public const PROVA = 'zr-auth.persona-di-prova';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $richiesta, Closure $next): Response
    {
        if ($richiesta->hasSession()) {
            $richiesta->attributes->set(self::ATTRIBUTO, $richiesta->session()->getId());
            $richiesta->attributes->set(self::PROVA, self::personaDiProva());
        }

        return $next($richiesta);
    }

    /**
     * Vero se la persona della sessione di adesso è un'identità di prova: l'email comincia per uno dei prefissi
     * (`zr-auth.prova.email_prefissi`) oppure è, intera, una di quelle di `zr-auth.prova.email` (gli account condivisi senza «+»), senza
     * distinguere le maiuscole né gli spazi ai bordi. Lo chiede a inizio richiesta questo middleware e a fine corsa SessioneChiusa: la
     * persona che la richiesta ha fatto entrare conta quanto quella di partenza (#2241).
     */
    public static function personaDiProva(): bool
    {
        try {
            $email = Sessione::utente()['email'] ?? null;
        } catch (Throwable) {
            return false;
        }

        if (! is_string($email)) {
            return false;
        }

        $email = strtolower(trim($email));
        foreach ((array) config('zr-auth.prova.email_prefissi', ['uat+', 'uat-']) as $prefisso) {
            if (is_string($prefisso) && $prefisso !== '' && str_starts_with($email, strtolower($prefisso))) {
                return true;
            }
        }

        foreach ((array) config('zr-auth.prova.email', []) as $condiviso) {
            if (is_string($condiviso) && trim($condiviso) !== '' && $email === strtolower(trim($condiviso))) {
                return true;
            }
        }

        return false;
    }
}
