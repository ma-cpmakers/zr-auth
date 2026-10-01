<?php

namespace Zeiras\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * L'ingresso del modulo da zr-home con OpenID Connect (voce #978): la richiesta all'`authorize` (PKCE S256, `state`,
 * `nonce`), lo scambio del codice, la verifica dell'id_token e la sessione del modulo. Firma, scadenze, emittente e
 * destinatario li verifica Token col JWKS di zr-home; qui si aggiungono i controlli — nonce, claim — e non si sostituiscono
 * (G18).
 */
final class Ingresso
{
    /**
     * La sessione del modulo: `sub`, `sid`, `workspace` {`id`, `nome`}, `ruolo`, `inizio`, e `revoca`, l'ultima revoca
     * arrivata prima dello scambio del codice (per la sessione valgono solo quelle dopo: Revoca).
     */
    public const SESSIONE = 'zr-auth.sessione';

    /** Gli ingressi che aspettano il ritorno, per `state`: ognuno vale una volta. */
    public const IN_ATTESA = 'zr-auth.ingressi';

    /** Quanti ingressi possono aspettare insieme: più schede rifanno l'ingresso nello stesso momento. */
    private const TETTO = 5;

    /**
     * Comincia un ingresso e torna l'indirizzo dell'`authorize` di zr-home. L'ingresso aspetta nella sessione di Laravel;
     * la sessione del modulo si toglie: è scaduta, o di un altro workspace.
     */
    public function comincia(Request $richiesta, ?int $workspace, bool $silenzioso, ?string $ritorno = null): string
    {
        $state = Str::random(40);
        $nonce = Str::random(40);
        $verificatore = Str::random(64);

        $sessione = $richiesta->session();
        $inAttesa = $sessione->get(self::IN_ATTESA);
        $inAttesa = array_slice(is_array($inAttesa) ? $inAttesa : [], 1 - self::TETTO, null, true);
        $inAttesa[$state] = [
            'nonce' => $nonce,
            'verificatore' => $verificatore,
            'ritorno' => $ritorno ?? self::ritornoDa($richiesta),
            'workspace' => $workspace,
            'silenzioso' => $silenzioso,
        ];
        $sessione->put(self::IN_ATTESA, $inAttesa);
        $sessione->forget(self::SESSIONE);

        return self::zrHome().'/oauth/authorize?'.http_build_query(array_filter([
            'client_id' => config('zr-auth.client_id'),
            'redirect_uri' => self::ritornoRegistrato(),
            'response_type' => 'code',
            'scope' => 'openid profile email workspace',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::base64url(hash('sha256', $verificatore, true)),
            'code_challenge_method' => 'S256',
            'workspace' => $workspace,
            'prompt' => $silenzioso ? 'none' : null,
        ], fn (mixed $valore) => $valore !== null), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * L'ingresso che aspetta con questo `state`, tolto dall'attesa: un ritorno vale una volta, comunque vada.
     *
     * @return array{nonce: string, verificatore: string, ritorno: string, workspace: int|null, silenzioso: bool}|null
     */
    public function riprendi(Request $richiesta, string $state): ?array
    {
        $inAttesa = $richiesta->session()->get(self::IN_ATTESA);
        if (! is_array($inAttesa) || ! is_array($inAttesa[$state] ?? null)) {
            return null;
        }
        $ingresso = $inAttesa[$state];
        unset($inAttesa[$state]);
        $richiesta->session()->put(self::IN_ATTESA, $inAttesa);

        return $ingresso;
    }

    /**
     * Scambia il codice e, se l'id_token è valido per questo modulo e per questo ingresso, apre la sessione del modulo:
     * la persona ricopiata, il workspace e il ruolo del token. Se zr-home non risponde, la sessione non si apre.
     *
     * @param  array{nonce: string, verificatore: string, ritorno: string, workspace: int|null, silenzioso: bool}  $ingresso
     */
    public function apri(Request $richiesta, #[SensitiveParameter] array $ingresso, #[SensitiveParameter] string $codice): bool
    {
        // Prima dello scambio: un avviso che arriva mentre zr-home risponde chiude anche questa sessione.
        $revoca = Revoca::ultima();
        try {
            $risposta = Http::asForm()->acceptJson()->timeout(10)->post(self::zrHome().'/oauth/token', [
                'grant_type' => 'authorization_code',
                'client_id' => config('zr-auth.client_id'),
                'client_secret' => config('zr-auth.client_secret'),
                'redirect_uri' => self::ritornoRegistrato(),
                'code' => $codice,
                'code_verifier' => $ingresso['verificatore'],
            ]);
        } catch (ConnectionException $errore) {
            Log::warning('zr-auth: zr-home non risponde (scambio del codice)', ['errore' => $errore->getMessage()]);

            return false;
        }
        $idToken = $risposta->successful() ? $risposta->json('id_token') : null;
        $claims = is_string($idToken) ? $this->verifica($idToken, $ingresso) : null;
        if ($claims === null) {
            return false;
        }

        // Due primi ingressi insieme della stessa persona: updateOrCreate passa da createOrFirst, e l'INSERT che trova la
        // riga già scritta diventa un aggiornamento.
        Persona::unguarded(fn () => Persona::query()->updateOrCreate(['id' => (int) $claims['sub']], [
            'email' => $claims['email'],
            'name' => is_string($claims['name'] ?? null) ? $claims['name'] : null,
            'locale' => is_string($claims['locale'] ?? null) ? $claims['locale'] : null,
        ]));

        $richiesta->session()->regenerate(true);
        $richiesta->session()->put(self::SESSIONE, [
            'sub' => (int) $claims['sub'],
            'sid' => $claims['sid'],
            'workspace' => ['id' => $claims['workspace']['id'], 'nome' => $claims['workspace']['name']],
            'ruolo' => $claims['role'],
            'inizio' => now()->getTimestamp(),
            'revoca' => $revoca,
        ]);

        return true;
    }

    /**
     * I claim dell'id_token se è valido: firmato da zr-home per questo client solo (Token), con la scadenza, col `nonce` di
     * questo ingresso, del workspace che l'ingresso chiedeva, se ne chiedeva uno, e coi claim che la sessione vuole — il
     * `sub` è un id di zr-home. Null altrimenti.
     *
     * @param  array{nonce: string, verificatore: string, ritorno: string, workspace: int|null, silenzioso: bool}  $ingresso
     * @return array<string, mixed>|null
     */
    private function verifica(#[SensitiveParameter] string $idToken, #[SensitiveParameter] array $ingresso): ?array
    {
        $claims = Token::claims($idToken);

        $valido = $claims !== null
            && isset($claims['exp'])
            && is_string($claims['nonce'] ?? null) && hash_equals($ingresso['nonce'], $claims['nonce'])
            && Token::id($claims['sub'] ?? null) !== null
            && is_string($claims['email'] ?? null)
            && is_int($claims['workspace']['id'] ?? null) && is_string($claims['workspace']['name'] ?? null)
            && ($ingresso['workspace'] === null || $claims['workspace']['id'] === $ingresso['workspace'])
            && is_string($claims['role'] ?? null)
            && is_string($claims['sid'] ?? null);

        return $valido ? $claims : null;
    }

    /** zr-home, senza la barra finale: è anche l'emittente dei token. */
    public static function zrHome(): string
    {
        return rtrim((string) config('zr-auth.zr_home'), '/');
    }

    /** Il ritorno registrato su zr-home per questo modulo: da `APP_URL`, mai dall'Host della richiesta. */
    public static function ritornoRegistrato(): string
    {
        return rtrim((string) config('app.url'), '/').'/auth/callback';
    }

    /** La pagina a cui tornare dopo l'ingresso: un percorso di questo modulo, mai un altro host. Fuori da una visita, la home. */
    private static function ritornoDa(Request $richiesta): string
    {
        if (! $richiesta->isMethod('GET') && ! $richiesta->isMethod('HEAD')) {
            return '/';
        }
        $query = $richiesta->getQueryString();

        return '/'.ltrim($richiesta->path(), '/').($query !== null ? '?'.$query : '');
    }

    private static function base64url(string $binario): string
    {
        return rtrim(strtr(base64_encode($binario), '+/', '-_'), '=');
    }
}
