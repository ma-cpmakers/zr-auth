<?php

use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as RichiestaHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Zeiras\Auth\Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/*
 * Uno zr-home finto. Le chiavi RSA nascono nel test, il segreto del client è un valore di prova: nel repo, che è
 * pubblico, nessuna chiave e nessun segreto veri (G3).
 */
const ZR_HOME = 'https://app.zeiras.com';
const MODULO = 'https://crm.zeiras.com';
const CLIENTE = 'cliente-del-modulo';
const SEGRETO = 'valore-di-prova-non-un-segreto';
const CODICE = 'codice-d-ingresso';

/** Le chiavi RSA del test, una per nome e per processo (generarle costa): `zr-home` firma, `altra` è quella sbagliata. */
function chiaveRsa(string $nome = 'zr-home'): OpenSSLAsymmetricKey
{
    static $chiavi = [];

    return $chiavi[$nome] ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
}

function base64url(string $binario): string
{
    return rtrim(strtr(base64_encode($binario), '+/', '-_'), '=');
}

/** Il JWKS di zr-home come lo pubblica (sprint 4, T2.1): una chiave RSA RS256 col suo `kid`. */
function jwks(): array
{
    $rsa = openssl_pkey_get_details(chiaveRsa())['rsa'];

    return ['keys' => [[
        'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'zr-home-1',
        'n' => base64url($rsa['n']), 'e' => base64url($rsa['e']),
    ]]];
}

/** I claim dell'id_token di zr-home (sprint 4, T2.2), con `$altri` sopra; un claim a null non c'è. */
function claims(string $nonce, array $altri = []): array
{
    return array_filter(array_replace([
        'iss' => ZR_HOME,
        'aud' => CLIENTE,
        'sub' => '42',
        'iat' => now()->getTimestamp(),
        'exp' => now()->addHour()->getTimestamp(),
        'nonce' => $nonce,
        'email' => 'giulia@esempio.it',
        'email_verified' => true,
        'name' => 'Giulia Rossi',
        'locale' => 'it',
        'workspace' => ['id' => 7, 'name' => 'Ventiquattro'],
        'role' => 'admin',
        'sid' => 'sid-della-sessione-di-zr-home',
    ], $altri), fn (mixed $valore) => $valore !== null);
}

function idToken(array $claims, string $chiave = 'zr-home'): string
{
    openssl_pkey_export(chiaveRsa($chiave), $privata);

    return JWT::encode($claims, $privata, 'RS256', 'zr-home-1');
}

/**
 * I parametri dell'ingresso a cui la risposta rimanda, che deve essere l'`authorize` di zr-home: il redirect, o il 409 di
 * Inertia.
 *
 * @return array<string, string>
 */
function ingressoChiesto(TestResponse $risposta): array
{
    $indirizzo = (string) ($risposta->headers->get('Location') ?? $risposta->headers->get('X-Inertia-Location'));
    expect($indirizzo)->toStartWith(ZR_HOME.'/oauth/authorize?');
    parse_str((string) parse_url($indirizzo, PHP_URL_QUERY), $parametri);

    return $parametri;
}

/**
 * zr-home finto: il JWKS, e lo scambio del codice che risponde con `$idToken` solo alla richiesta giusta — il client col
 * suo segreto, il ritorno registrato, il codice, il verificatore della sfida PKCE mandata all'authorize. Un secondo
 * ingresso nello stesso test cambia ingresso e token: Http::fake() si registra una volta (il primo stub vince, e ogni
 * fake() azzera le richieste registrate).
 */
function zrHomeFinto(array $chiesto, string $idToken): void
{
    if (! app()->bound('zr-home-finto')) {
        Http::preventStrayRequests();
        Http::fake([
            ZR_HOME.'/oauth/jwks' => Http::response(jwks()),
            ZR_HOME.'/oauth/token' => function (RichiestaHttp $richiesta) {
                ['chiesto' => $chiesto, 'id_token' => $idToken] = app('zr-home-finto');

                return scambioGiusto($richiesta, $chiesto)
                    ? Http::response(['token_type' => 'Bearer', 'expires_in' => 600, 'access_token' => 'accesso', 'id_token' => $idToken])
                    : Http::response(['error' => 'invalid_grant'], 400);
            },
        ]);
    }
    app()->instance('zr-home-finto', ['chiesto' => $chiesto, 'id_token' => $idToken]);
}

function scambioGiusto(RichiestaHttp $richiesta, array $chiesto): bool
{
    return $richiesta->method() === 'POST'
        && $richiesta['grant_type'] === 'authorization_code'
        && $richiesta['client_id'] === CLIENTE
        && $richiesta['client_secret'] === SEGRETO
        && $richiesta['redirect_uri'] === MODULO.'/auth/callback'
        && $richiesta['code'] === CODICE
        && base64url(hash('sha256', (string) $richiesta['code_verifier'], true)) === $chiesto['code_challenge'];
}

/** Il ritorno da zr-home al modulo, col codice e lo `state` dell'ingresso chiesto. */
function ritorno(array $chiesto): TestResponse
{
    return test()->get('/auth/callback?'.http_build_query(['code' => CODICE, 'state' => $chiesto['state']]));
}

/** Gli scambi del codice arrivati a zr-home. */
function scambi(): int
{
    return count(Http::recorded(fn (RichiestaHttp $richiesta) => $richiesta->url() === ZR_HOME.'/oauth/token'));
}

/** L'ingresso intero, da una pagina del modulo al ritorno: torna la risposta del ritorno. */
function entra(array $altri = [], string $pagina = '/pagina'): TestResponse
{
    $chiesto = ingressoChiesto(test()->get($pagina));
    zrHomeFinto($chiesto, idToken(claims($chiesto['nonce'], $altri)));

    return ritorno($chiesto);
}
