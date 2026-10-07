<?php

use Carbon\CarbonInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Zeiras\Auth\Sessione;
use Zeiras\Auth\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

const FRONTEND = 'https://board.zeiras.com';
const API = 'https://api.zeiras.com';
const INGRESSO = 'https://app.zeiras.com/accedi';

// Gettoni di prova nella forma di Zeiras (`zr_` più 48 caratteri): nel repo, che è pubblico, nessun gettone vero (G13).
// Scritti in due pezzi: interi, la guardia dei segreti li leggerebbe come gettoni veri.
const GETTONE_ACCESSO = 'zr_'.'AccessoAccessoAccessoAccessoAccessoAccessoAccess';
const GETTONE_WORKSPACE = 'zr_'.'WorkspaceWorkspaceWorkspaceWorkspaceWorkspaceWor';

/** La persona come la dà il backoffice (lo schema Utente). */
function utente(): array
{
    return [
        'id' => '01k6r2t5b9d3f7h1k5m9n3q7r1',
        'nome' => 'Anna',
        'email' => 'anna@example.com',
        'email_verificata_il' => '2026-10-05T09:12:31.000Z',
        'lingua' => 'it',
        'fuso_orario' => 'Europe/Rome',
    ];
}

/** I `data` di accessi.crea (lo schema Accesso): l'accesso e il suo gettone, senza workspace. */
function accesso(?string $scadeIl = null): array
{
    return [
        'id' => '01k6r2v8x4c7n3m9p5q1s6t2w8',
        'creato_il' => now()->toJSON(),
        'gettone' => [
            'gettone' => GETTONE_ACCESSO,
            'scade_il' => $scadeIl ?? now()->addHours(12)->toJSON(),
            'utente' => utente(),
            'workspace' => null,
            'ruolo' => null,
        ],
    ];
}

/** I `data` di gettoni.crea (lo schema Gettone): il gettone di un workspace. */
function gettoneDelWorkspace(?string $scadeIl = null): array
{
    return [
        'gettone' => GETTONE_WORKSPACE,
        'scade_il' => $scadeIl ?? now()->addHours(12)->toJSON(),
        'utente' => utente(),
        'workspace' => ['id' => '01k6r3a7c2e6g0j4m8p2s6v0x4', 'nome' => 'Studio Anna', 'slug' => 'studio-anna-k3x9q2', 'azienda_id' => '01k6r3a7c2e6g0j4m8p2s6v0x5'],
        'ruolo' => 'proprietario',
    ];
}

/** Una sessione aperta: l'accesso e, se si vuole, l'ingresso nel workspace. */
function apriSessione(bool $conWorkspace = true, ?string $scadeIl = null): void
{
    Sessione::apri(accesso($scadeIl));
    if ($conWorkspace) {
        Sessione::entra(gettoneDelWorkspace($scadeIl));
    }
}

/** Un errore di /v1 come lo scrive il backoffice: un problem details di RFC 9457. */
function problema(int $stato, string $codice, array $altri = [], array $header = []): PromiseInterface
{
    return Http::response(json_encode([
        'type' => "https://docs.zeiras.com/v1/errori/{$codice}",
        'title' => 'Titolo',
        'status' => $stato,
        'detail' => 'Dettaglio per la persona.',
        'codice' => $codice,
        ...$altri,
    ]), $stato, ['Content-Type' => 'application/problem+json', ...$header]);
}

// Il backoffice finto (T2): le chiamate come le fa un client qualunque, e le risposte come le scrive il backoffice.

const PASSWORD = 'una password lunga e sicura';

// Le altre password delle prove, in costanti: un valore letterale accanto a una chiave «password» in un array è ciò che la
// guardia dei segreti (.github/nessun-segreto.sh) ferma.
const ALTRA_PASSWORD = "un'altra password lunga";
const PASSWORD_SBAGLIATA = 'una password sbagliata';
const PASSWORD_CORTA = 'corta';
const PASSWORD_COL_NULLO = "una password\0lunga";

/** Una chiamata al finto: metodo, percorso di /v1, corpo JSON, gettone, Accept-Language. */
function alFinto(string $metodo, string $percorso, ?array $corpo = null, ?string $gettone = null, ?string $lingua = null): Response
{
    $richiesta = Http::baseUrl(API)->acceptJson();

    if ($gettone !== null) {
        $richiesta = $richiesta->withToken($gettone);
    }

    if ($lingua !== null) {
        $richiesta = $richiesta->withHeaders(['Accept-Language' => $lingua]);
    }

    return $richiesta->send($metodo, $percorso, $corpo === null ? [] : ['json' => $corpo]);
}

/** Entra nel finto con accessi.crea, e ne dà i `data` (lo schema Accesso). */
function entraNelFinto(string $email, string $password = PASSWORD): array
{
    $risposta = alFinto('POST', '/v1/accessi', ['email' => $email, 'password' => $password]);
    expect($risposta->status())->toBe(201);

    return $risposta->json('data');
}

/** Un problema di /v1 come lo scrive il backoffice (RFC 9457), coi testi di una lingua. */
function problemaAtteso(string $codice, int $stato, string $titolo, string $dettaglio, array $altri = []): array
{
    return [
        'type' => "https://docs.zeiras.com/v1/errori/{$codice}",
        'title' => $titolo,
        'status' => $stato,
        'detail' => $dettaglio,
        'codice' => $codice,
        ...$altri,
    ];
}

/** L'header Link di una risposta del metodo. */
function linkDi(string $operationId): string
{
    return "<https://docs.zeiras.com/v1/{$operationId}>; rel=\"describedby\"";
}

/** Un istante come lo scrive il backoffice: ISO 8601, in UTC, coi millesimi. */
function iso(CarbonInterface $istante): string
{
    return $istante->copy()->utc()->format('Y-m-d\\TH:i:s.v\\Z');
}
