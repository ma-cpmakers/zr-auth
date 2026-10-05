<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Zeiras\Auth\Sessione;
use Zeiras\Auth\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

const FRONTEND = 'https://board.zeiras.com';
const API = 'https://api.zeiras.com';
const INGRESSO = 'https://app.zeiras.com/accedi';

// Gettoni di prova nella forma di Zeiras (`zr_` più 48 caratteri): nel repo, che è pubblico, nessun gettone vero (G13).
const GETTONE_ACCESSO = 'zr_AccessoAccessoAccessoAccessoAccessoAccessoAccess';
const GETTONE_WORKSPACE = 'zr_WorkspaceWorkspaceWorkspaceWorkspaceWorkspaceWor';

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
        'workspace' => ['id' => '01k6r3a7c2e6g0j4m8p2s6v0x4', 'nome' => 'Studio Anna', 'slug' => 'studio-anna-k3x9q2'],
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
