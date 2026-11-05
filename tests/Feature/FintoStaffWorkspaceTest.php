<?php

use Illuminate\Support\Carbon;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Errori\GettoneRifiutato;
use Zeiras\Auth\Testing\BackofficeFinto;

// #3255 (F3.1, F3.8): `staff.workspace.elenca` e `staff.workspace.ricerca.crea` del finto, per il frontend dello staff (zr-superadmin). Che forme ed errori siano quelli del backoffice lo prova il
// FintoTest del backoffice; qui si prova che il finto tiene le regole: il ruolo, il perimetro, i null del vero (proprietario, cognome), lo stato, il cursore, il termine mai nell'URL, nessuna riga di registro.

beforeEach(function () {
    Carbon::setTestNow('2026-11-05 10:00:00');
    config(['zr-auth.cliente' => BackofficeFinto::CLIENTE, 'zr-auth.segreto' => BackofficeFinto::SEGRETO_DEL_CLIENTE]);
    request()->server->set('REMOTE_ADDR', '203.0.113.7');
    $this->finto = BackofficeFinto::attiva();
});

/** Lo stato e il codice di un errore di /v1, o ['ok', null] se riesce. */
function esitoWorkspaceStaff(Closure $chiamata): array
{
    try {
        $chiamata();
    } catch (ErroreApi $errore) {
        return [$errore->stato, $errore->codice];
    } catch (GettoneRifiutato $rifiutato) {
        return [$rifiutato->errore?->stato, $rifiutato->errore?->codice];
    }

    return ['ok', null];
}

/** Una persona dello staff già entrata: il suo gettone. */
function gettoneWorkspaceStaff(BackofficeFinto $finto, string $email, string $ruolo = 'superadmin', bool $soloUat = false): string
{
    $persona = $finto->persona($email, PASSWORD, nome: ucfirst(strstr($email, '@', true)));
    $staff = $finto->staffDi($persona, $ruolo, true, $soloUat);
    $sfida = Api::staff()->post('/v1/staff/accessi', ['email' => $staff['email'], 'password' => PASSWORD])['data']['sfida']['id'];

    return Api::staff()->post('/v1/staff/accessi/verifica', ['sfida' => $sfida, 'codice' => BackofficeFinto::CODICE_TOTP_DELLO_STAFF])['data']['gettone'];
}

it('l\'elenco: i campi dichiarati dal più recente, il proprietario, i conteggi, lo stato, e non scrive nel registro (F3.8)', function () {
    $gettone = gettoneWorkspaceStaff($this->finto, 'sara@lucianocastro.com');
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $uno = $this->finto->workspace('Studio uno', $anna);
    Carbon::setTestNow('2026-11-05 10:00:05');
    $due = $this->finto->workspace('Studio due', $anna);
    $this->finto->membro($due, $this->finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno'), 'membro');
    $registro = count($this->finto->registroDelloStaff());

    $elenco = Api::staff($gettone)->get('/v1/staff/workspace');
    $righe = array_column($elenco['data'], null, 'id');

    expect(array_column($elenco['data'], 'id'))->toBe([$due['id'], $uno['id']])
        ->and(array_keys($righe[$due['id']]))->toBe(['id', 'nome', 'indirizzo', 'azienda', 'proprietario', 'membri_n', 'app_attive_n', 'stato', 'creato_il'])
        ->and($righe[$due['id']])->toMatchArray(['nome' => 'Studio due', 'indirizzo' => $due['slug'], 'azienda' => 'Studio due', 'membri_n' => 2, 'app_attive_n' => 0, 'stato' => 'attivo', 'creato_il' => '2026-11-05T10:00:05.000Z'])
        ->and($righe[$due['id']]['proprietario'])->toBe(['id' => $anna['id'], 'nome' => 'Anna', 'cognome' => null])
        ->and($elenco['successivo'])->toBeNull()
        ->and(count($this->finto->registroDelloStaff()))->toBe($registro);
});

it('in_eliminazione è lo stato dell\'eliminazione viva, e il filtro stato lo separa; uno stato che non c\'è è 422 su stato (F3.8)', function () {
    $gettone = gettoneWorkspaceStaff($this->finto, 'sara@lucianocastro.com');
    $vera = $this->finto->persona('vera@example.com', PASSWORD, nome: 'Vera');
    $resta = $this->finto->workspace('Resta', $vera);
    $parte = $this->finto->workspace('Se ne va', $vera);
    $accesso = entraNelFinto('vera@example.com')['gettone']['gettone'];
    $gettoneVera = alFinto('POST', '/v1/gettoni', ['workspace_id' => $parte['id']], $accesso)->json('data.gettone');
    expect(alFinto('DELETE', '/v1/workspace', gettone: $gettoneVera)->status())->toBe(202);

    $stati = fn (array $query = []) => array_column(Api::staff($gettone)->get('/v1/staff/workspace', $query)['data'], 'stato', 'id');

    expect($stati())->toBe([$parte['id'] => 'in_eliminazione', $resta['id'] => 'attivo'])
        ->and($stati(['stato' => 'in_eliminazione']))->toBe([$parte['id'] => 'in_eliminazione'])
        ->and($stati(['stato' => 'attivo']))->toBe([$resta['id'] => 'attivo'])
        ->and(esitoWorkspaceStaff(fn () => Api::staff($gettone)->get('/v1/staff/workspace', ['stato' => 'sospeso'])))->toBe([422, 'dati_non_validi'])
        ->and(esitoWorkspaceStaff(fn () => Api::staff($gettone)->post('/v1/staff/workspace/ricerca', ['stato' => 'sospeso'])))->toBe([422, 'dati_non_validi']);
});

it('la ricerca cerca nel nome e nell\'indirizzo con il termine nel corpo; nell\'URL è 422; il cursore è della ricerca che l\'ha dato (F3.1)', function () {
    $gettone = gettoneWorkspaceStaff($this->finto, 'sara@lucianocastro.com');
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $ferri = $this->finto->workspace('Studio Ferri', $anna);
    $verdi = [];
    foreach ([1, 2, 3] as $n) {
        Carbon::setTestNow("2026-11-05 10:00:0{$n}");
        $verdi[] = $this->finto->workspace("Palestra Verdi {$n}", $anna)['id'];
    }

    $cerca = fn (array $corpo) => Api::staff($gettone)->post('/v1/staff/workspace/ricerca', $corpo);

    expect(array_column($cerca(['q' => 'ferri'])['data'], 'id'))->toBe([$ferri['id']])
        ->and(array_column($cerca(['q' => $ferri['slug']])['data'], 'id'))->toBe([$ferri['id']])
        ->and($cerca(['q' => 'inesistente'])['data'])->toBe([])
        ->and($cerca([])['data'])->toHaveCount(4);

    foreach (['q', 'nome', 'indirizzo', 'azienda'] as $parametro) {
        expect(esitoWorkspaceStaff(fn () => Api::staff($gettone)->get('/v1/staff/workspace', [$parametro => 'ferri'])))->toBe([422, 'dati_non_validi']);
    }

    $pagina = $cerca(['q' => 'verdi', 'limite' => 2]);
    $seguente = $cerca(['q' => 'verdi', 'limite' => 2, 'cursore' => $pagina['successivo']]);

    expect($pagina['data'])->toHaveCount(2)->and($seguente['data'])->toHaveCount(1)->and($seguente['successivo'])->toBeNull()
        ->and(array_merge(array_column($pagina['data'], 'id'), array_column($seguente['data'], 'id')))->toBe(array_reverse($verdi))
        ->and(esitoWorkspaceStaff(fn () => $cerca(['q' => 'ferri', 'limite' => 2, 'cursore' => $pagina['successivo']])))->toBe([422, 'dati_non_validi'])
        ->and(esitoWorkspaceStaff(fn () => Api::staff($gettone)->get('/v1/staff/workspace', ['cursore' => $pagina['successivo']])))->toBe([422, 'dati_non_validi']);
});

it('il superadmin e il supporto leggono, la fatturazione e un gettone qualsiasi no; senza gettone 401; solo_uat vede i soli workspace di un proprietario uat+ (F3a.4)', function () {
    $sara = gettoneWorkspaceStaff($this->finto, 'sara@lucianocastro.com');
    $paola = gettoneWorkspaceStaff($this->finto, 'paola@lucianocastro.com', 'supporto');
    $fabio = gettoneWorkspaceStaff($this->finto, 'fabio@lucianocastro.com', 'fatturazione');
    $prova = gettoneWorkspaceStaff($this->finto, 'prova@lucianocastro.com', 'superadmin', true);
    $uat = $this->finto->persona('uat+uno@castroandpartners.com', PASSWORD, nome: 'Prova');
    $vera = $this->finto->persona('vera@example.com', PASSWORD, nome: 'Vera');
    $mio = $this->finto->workspace('Prova uno', $uat);
    $this->finto->workspace('UAT di un cliente', $vera);
    $gettoneVera = entraNelFinto('vera@example.com')['gettone']['gettone'];

    foreach ([['GET', '/v1/staff/workspace'], ['POST', '/v1/staff/workspace/ricerca']] as [$metodo, $percorso]) {
        $chiama = fn (?string $gettone) => $metodo === 'GET' ? Api::staff($gettone)->get($percorso) : Api::staff($gettone)->post($percorso, []);

        expect(esitoWorkspaceStaff(fn () => $chiama($sara)))->toBe(['ok', null])
            ->and(esitoWorkspaceStaff(fn () => $chiama($paola)))->toBe(['ok', null])
            ->and(esitoWorkspaceStaff(fn () => $chiama($fabio)))->toBe([403, 'permesso_negato'])
            ->and(esitoWorkspaceStaff(fn () => $chiama($gettoneVera)))->toBe([403, 'permesso_negato'])
            ->and(esitoWorkspaceStaff(fn () => $chiama(null)))->toBe([401, 'gettone_assente'])
            ->and(array_column($chiama($prova)['data'], 'id'))->toBe([$mio['id']]);
    }
});
