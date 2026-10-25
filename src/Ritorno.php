<?php

namespace Zeiras\Auth;

use Illuminate\Http\Request;

/**
 * Dove tornare dopo l'accesso quando a scadere è stata una scrittura (#2043): la pagina da cui è partita, dal Referer. La guardia
 * ricorda da sé l'indirizzo di un GET; di una scrittura ricorda questo, o niente. Mai il Referer così com'è: è una cosa che il
 * browser manda e che un altro sito può scegliere. Si tiene il solo percorso con la query, rimesso sull'origine della richiesta, e
 * solo se il Referer ha la stessa origine (schema, host e porta, senza utente) e il percorso sta sotto `/w/<slug>/` del workspace
 * della richiesta. Altrimenti null, e dopo l'accesso si va alla pagina predefinita del modulo. Si torna con un GET: la scrittura
 * non si ripete, e ciò che era scritto non si tiene.
 */
final class Ritorno
{
    /** Il Referer più lungo che si guarda: oltre, non è una pagina del frontend. */
    public const TETTO = 2000;

    /** Il percorso più lungo che la guardia ricorda da un GET: oltre, non si ricorda niente e si va alla pagina predefinita. */
    public const TETTO_PERCORSO = 300;

    /** Percorso e query di un GET insieme (senza l'origine): oltre, si ricorda il solo percorso. Sopra ogni query legittima di Zeiras (`?state=&sfida=` sono ~100 byte). */
    public const TETTO_PERCORSO_E_QUERY = 512;

    /**
     * Dove tornare dopo l'accesso quando a chiedere era un GET: l'indirizzo della richiesta (come `fullUrl()`), con la query solo se
     * percorso e query insieme stanno in TETTO_PERCORSO_E_QUERY byte; oltre, il solo percorso. Un GET anonimo con una query lunga la
     * scriveva intera nella sessione (e Redis la tiene due ore), ma la query corta serve: `/ingresso?state=&sfida=` dei moduli e il
     * collegamento di un'email devono riprendere dopo l'accesso. `$conQuery` false: mai la query (il ricevitore dell'ingresso, il
     * cui `?codice=` è già consumato). Null se il percorso è lungo più di TETTO_PERCORSO, o ha byte di controllo, spazi, barre
     * rovesciate, un segmento vuoto (`//`) o `.`/`..`: dopo l'accesso si va alla pagina predefinita del modulo.
     */
    public static function dallaRichiesta(Request $richiesta, bool $conQuery = true): ?string
    {
        $percorso = $richiesta->getPathInfo();
        $chiaro = rawurldecode($percorso);
        if ($percorso === '' || $percorso[0] !== '/' || strlen($percorso) > self::TETTO_PERCORSO || str_contains($chiaro, '//')
            || preg_match('/[\x00-\x20\x7F\\\\]/', $chiaro) === 1 || preg_match('#(^|/)\.\.?(/|$)#', $chiaro) === 1) {
            return null;
        }

        $query = (string) $richiesta->getQueryString();
        if (! $conQuery || $query === '' || strlen($percorso) + 1 + strlen($query) > self::TETTO_PERCORSO_E_QUERY) {
            return $richiesta->url();
        }

        return $richiesta->fullUrl();
    }

    public static function dalReferer(Request $richiesta, mixed $slug): ?string
    {
        $referer = $richiesta->headers->get('referer');
        if (! is_string($slug) || $slug === '' || ! is_string($referer) || $referer === '' || strlen($referer) > self::TETTO
            || preg_match('/[\x00-\x20\x7F\\\\]/', $referer) === 1) {
            return null;
        }

        $parti = parse_url($referer);
        if ($parti === false || ! isset($parti['scheme'], $parti['host'], $parti['path']) || isset($parti['user']) || isset($parti['pass'])) {
            return null;
        }

        $origine = $richiesta->getSchemeAndHttpHost();
        $suaOrigine = strtolower($parti['scheme']).'://'.strtolower($parti['host']).(isset($parti['port']) ? ':'.$parti['port'] : '');
        if ($suaOrigine !== strtolower($origine)) {
            return null;
        }

        $percorso = $parti['path'];
        $chiaro = rawurldecode($percorso);
        if (! str_starts_with($percorso, '/w/'.rawurlencode($slug).'/') || str_contains($chiaro, '//')
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $chiaro) === 1 || preg_match('#(^|/)\.\.?(/|$)#', $chiaro) === 1) {
            return null;
        }

        // Il testo cercato non entra nel ritorno: starebbe nella sessione e nel Location (config `zr-auth.ritorno.senza_query`). Le
        // altre coppie restano com'erano (chiavi, ordine, doppioni, parentesi): rileggerle e riscriverle cambierebbe il filtro.
        $senza = (array) config('zr-auth.ritorno.senza_query', ['q', 'scelta']);
        $coppie = array_filter(
            explode('&', $parti['query'] ?? ''),
            function (string $coppia) use ($senza): bool {
                $chiave = urldecode(strstr($coppia, '=', true) ?: $coppia);

                return $coppia !== '' && ! in_array(strstr($chiave, '[', true) ?: $chiave, $senza, true);
            },
        );
        // Solo i byte di una query (RFC 3986, più le parentesi quadre che i browser lasciano): il resto va percent-codificato.
        $query = (string) preg_replace_callback('/[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/?%\[\]]/', fn (array $b): string => rawurlencode($b[0]), implode('&', $coppie));

        return $origine.$percorso.($query !== '' ? '?'.$query : '');
    }
}
