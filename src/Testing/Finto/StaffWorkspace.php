<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * I workspace dei clienti nel backoffice finto (#3255, F3.1, F3.8): `staff.workspace.elenca` e `staff.workspace.ricerca.crea`, per il frontend dello staff (zr-superadmin). Le regole sono
 * quelle di `Staff`: il client firmato, il gettone dello staff, il ruolo (superadmin e supporto), il perimetro `solo_uat` (i workspace il cui proprietario ha un'email `uat+*@castroandpartners.com`).
 * Il termine di ricerca non sta nell'URL: `q`, `nome`, `indirizzo` e `azienda` nella query sono 422 sul parametro. Leggere conta nel tetto delle pagine letture e non scrive nel registro dello staff.
 * La scheda (#3256, F3b) aggiunge `consumi`, `eliminazione` e `fatturazione` e, a differenza dell'elenco, lascia una riga nel registro dello staff; la apre anche la fatturazione. Il finto non ha prenotazioni di Bookings: `prenotazioni_n` è 0.
 * Ogni campo che il vero manda `null` il finto lo manda `null` (il proprietario di un workspace che non ne ha uno, il cognome). Uno stato `sospeso` non c'è ancora.
 */
trait StaffWorkspace
{
    private const STATI_DEL_WORKSPACE = ['attivo', 'in_eliminazione'];

    /** @var array<string, \Carbon\CarbonImmutable> quando è nato ogni workspace, per workspace */
    private array $workspaceCreatoIl = [];

    /** @var array<string, string> il workspace che ha lasciato ogni indirizzo (#3257), per indirizzo: non si riassegna a nessun altro */
    private array $indirizziRiservati = [];

    // ── I workspace ────────────────────────────────────────────────────────────────────

    /** @return array{int, array<string, mixed>} */
    private function elencaIWorkspaceDelloStaff(Request $richiesta): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin', 'supporto']);
        $this->contaLaLettura($chi);
        $query = $this->testi->validaQuery(self::query($richiesta), [
            'limite' => ['sometimes', 'integer', 'between:1,'.self::LIMITE_MASSIMO_DELLO_STAFF],
            'cursore' => ['sometimes', 'string', $this->regolaDelCursoreDelloStaff('staff.workspace')],
            'stato' => ['sometimes', 'string', 'in:'.implode(',', self::STATI_DEL_WORKSPACE)],
            'q' => ['prohibited'],
            'nome' => ['prohibited'],
            'indirizzo' => ['prohibited'],
            'azienda' => ['prohibited'],
        ]);

        return $this->impaginaIWorkspace($query, 'staff.workspace', $this->righeDeiWorkspace($chi, array_intersect_key($query, ['stato' => 1])));
    }

    /**
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function cercaIWorkspaceDelloStaff(Request $richiesta, array $corpo): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin', 'supporto']);
        $this->contaLaLettura($chi);
        $campi = array_filter($this->testi->validaStretta($corpo, [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'stato' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::STATI_DEL_WORKSPACE)],
            'limite' => ['sometimes', 'nullable', 'integer:strict', 'between:1,'.self::LIMITE_MASSIMO_DELLO_STAFF],
            'cursore' => ['sometimes', 'nullable', 'string'],
        ]), fn (mixed $valore) => $valore !== null);
        $filtri = array_diff_key($campi, ['limite' => 1, 'cursore' => 1]);
        // Il cursore è di questa ricerca e di questi filtri: con altri non vale (422 su #/cursore).
        $lista = 'staff.workspace.ricerca:'.sha1(json_encode($filtri, JSON_THROW_ON_ERROR));

        $this->parametriDelCorpo = true;

        try {
            return $this->impaginaIWorkspace($campi, $lista, $this->righeDeiWorkspace($chi, $filtri));
        } finally {
            $this->parametriDelCorpo = false;
        }
    }

    /**
     * La scheda di un workspace: la riga dell'elenco più i consumi, l'eliminazione e la fatturazione dell'azienda. Un id che non c'è, o fuori dal perimetro di chi è solo_uat, è 404 e non scrive nel registro.
     *
     * @return array{int, array<string, mixed>}
     */
    private function mostraUnWorkspaceDelloStaff(Request $richiesta, string $id): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin', 'supporto', 'fatturazione']);
        $id = strtolower($id);

        if (! isset($this->workspace[$id]) || ($chi['solo_uat'] && ! $this->proprietarioDiProva($id))) {
            throw new Problema('non_trovato');
        }

        $this->scriviNelRegistroDelloStaff($chi, 'staff.workspace.mostra', 'workspace', $id);

        return [200, ['data' => $this->schedaDelWorkspace($id)]];
    }

    /**
     * staff.workspace.modifica (#3257, StaffWorkspaceController::modifica): il `nome` e/o l'`indirizzo` (lo slug) di un workspace, come JSON Merge Patch. Solo il superadmin. Nell'ordine del backoffice: il
     * ruolo, il workspace (404; fuori dal perimetro di chi è solo_uat è lo stesso 404), il corpo (422: almeno un campo, un estraneo, un valore vuoto o `null`), l'indirizzo di un altro workspace o lasciato da un
     * altro (409 `indirizzo_gia_preso`). Un valore uguale a quello di prima risponde 200 e non scrive niente; un cambio vero lascia una riga nel registro dello staff (nei campi i nomi cambiati) e riserva
     * l'indirizzo lasciato: chi lo ha lasciato lo può riprendere, nessun altro. Risponde con la scheda. Nessun avviso a nessuno.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function modificaUnWorkspaceDelloStaff(Request $richiesta, array $corpo, string $workspace): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin']);
        $id = $this->workspaceDelloStaff($chi, $workspace);
        $regole = [
            'nome' => ['filled', 'string', 'max:255', new NomeScritto],
            'indirizzo' => ['filled', 'string', 'min:3', 'max:47', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
        ];
        $campi = $this->testi->validaStretta($corpo, $regole);

        if ($campi === []) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.almeno_un_campo', ['campi' => implode(', ', array_keys($regole))]), 'regola' => 'obbligatorio', 'pointer' => '#']]);
        }

        $cambiati = [];

        if (isset($campi['nome']) && $campi['nome'] !== $this->workspace[$id]['nome']) {
            $cambiati[] = 'nome';
        }

        if (isset($campi['indirizzo']) && $campi['indirizzo'] !== $this->workspace[$id]['slug']) {
            $preso = array_filter($this->workspace, fn (array $altro, string $altroId) => $altroId !== $id && $altro['slug'] === $campi['indirizzo'], ARRAY_FILTER_USE_BOTH) !== []
                || (isset($this->indirizziRiservati[$campi['indirizzo']]) && $this->indirizziRiservati[$campi['indirizzo']] !== $id);

            if ($preso) {
                throw new Problema('indirizzo_gia_preso');
            }

            $cambiati[] = 'indirizzo';
        }

        if ($cambiati !== []) {
            if (in_array('nome', $cambiati, true)) {
                $this->workspace[$id]['nome'] = $campi['nome'];
            }

            if (in_array('indirizzo', $cambiati, true)) {
                $this->indirizziRiservati[$this->workspace[$id]['slug']] ??= $id;
                $this->workspace[$id]['slug'] = $campi['indirizzo'];
            }

            $this->scriviNelRegistroDelloStaff($chi, 'staff.workspace.modifica', 'workspace', $id, $cambiati);
        }

        return [200, ['data' => $this->schedaDelWorkspace($id)]];
    }

    /**
     * La scheda di un workspace: la riga dell'elenco più i consumi, l'eliminazione e la fatturazione dell'azienda.
     *
     * @return array<string, mixed>
     */
    private function schedaDelWorkspace(string $id): array
    {
        $riga = $this->rigaDelWorkspace($id);
        $azienda = $this->workspace[$id]['azienda_id'] ?? null;
        $dati = $azienda === null ? [] : ($this->datiAziende[$azienda] ?? []);

        return $riga + [
            'consumi' => [
                'membri_n' => $riga['membri_n'],
                'board_n' => count(array_filter($this->boards, fn (array $board) => $board['workspace'] === $id)),
                'schede_n' => count(array_filter($this->schede, fn (array $scheda) => $scheda['workspace'] === $id)),
                'contatti_n' => count(array_filter($this->crm['persone'], fn (array $persona) => $persona['workspace'] === $id && ! ($persona['eliminata'] ?? false))),
                'prenotazioni_n' => 0,
            ],
            'eliminazione' => isset($this->eliminazioni[$id]) && $this->eliminazioni[$id]->isFuture() ? ['prevista_il' => self::iso($this->eliminazioni[$id])] : null,
            'fatturazione' => $azienda === null || ! isset($this->aziende[$azienda]) ? null : ['partita_iva' => $dati['partita_iva'] ?? null, 'paese' => $dati['paese'] ?? null, 'valuta' => $dati['valuta'] ?? null],
        ];
    }

    /**
     * staff.workspace.app.elenca (#3259, StaffWorkspaceAppController::elenca): le app che il workspace può avere (quelle `disponibili` del catalogo), in ordine di codice, ognuna con `codice` e
     * `attiva`. Superadmin e supporto; un workspace che non c'è o fuori dal perimetro di chi è solo_uat è 404. Una lettura: conta nel tetto e non scrive nel registro dello staff.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaLeAppDelWorkspaceDelloStaff(Request $richiesta, string $workspace): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin', 'supporto']);
        $id = $this->workspaceDelloStaff($chi, $workspace);
        $this->contaLaLettura($chi);
        $lista = 'staff.workspace.app.elenca';
        $query = $this->testi->validaQuery(self::query($richiesta), [
            'limite' => ['sometimes', 'integer', 'between:1,'.self::LIMITE_MASSIMO_DELLO_STAFF],
            'cursore' => ['sometimes', 'string', function (string $attributo, mixed $valore, \Closure $fail) use ($lista) {
                if ($this->leggiPosizione($lista, ['codice'], $valore) === null) {
                    $this->testi->dichiara($attributo, 'formato');
                    $fail('regole.cursore')->translate();
                }
            }],
        ]);
        $voci = [];

        foreach (self::CATALOGO as $codice => $stato) {
            if ($stato === 'disponibile') {
                $voci[] = ['codice' => $codice, 'attiva' => $this->appAttiva($id, $codice)];
            }
        }

        $query['limite'] ??= self::LIMITE_DELLO_STAFF;

        return $this->impagina($query, $lista, 'codice', $voci, fn (array $voce) => [$voce['codice']], fn (string $codice) => [$codice]);
    }

    /**
     * staff.workspace.app.modifica (#3259, StaffWorkspaceAppController::modifica): accende o spegne un'app (`attiva`, un booleano vero). Solo il superadmin. Nell'ordine del backoffice: il ruolo, il
     * workspace (404), l'app del catalogo (404), il corpo (422), un'app in arrivo (409 `app_in_arrivo`). Lo stato che ha già risponde 200 e non scrive niente; un cambio vero lascia una riga nel
     * registro dello staff (bersaglio `workspace`, nei campi il codice dell'app). Nessun avviso a nessuno.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function modificaLeAppDelWorkspaceDelloStaff(Request $richiesta, array $corpo, string $workspace, string $app): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin']);
        $id = $this->workspaceDelloStaff($chi, $workspace);

        if (! isset(self::CATALOGO[$app])) {
            throw new Problema('non_trovato');
        }

        $attiva = (bool) $this->testi->validaStretta($corpo, ['attiva' => ['required', 'boolean:strict']])['attiva'];

        if (self::CATALOGO[$app] !== 'disponibile') {
            throw new Problema('app_in_arrivo');
        }

        if ($attiva !== isset($this->attive[$id][$app])) {
            $attiva ? $this->attive[$id][$app] = true : $this->attive[$id] = array_diff_key($this->attive[$id] ?? [], [$app => 1]);
            $this->scriviNelRegistroDelloStaff($chi, 'staff.workspace.app.modifica', 'workspace', $id, [$app]);
        }

        return [200, ['data' => ['codice' => $app, 'attiva' => $attiva]]];
    }

    /**
     * staff.workspace.membri.elenca (#3258, StaffWorkspaceMembriController::elenca): i membri del workspace dal primo entrato, ognuno con la persona (`utente`: id, nome, cognome, email), il
     * `ruolo` e `creato_il`. Superadmin e supporto. Nell'ordine del backoffice: il ruolo, la query (422), il workspace (404; fuori dal perimetro di chi è solo_uat è lo stesso 404). Aprire l'elenco
     * è un'azione registrata, come la scheda: una riga nel registro dello staff, e un 404 non ne lascia.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaIMembriDelWorkspaceDelloStaff(Request $richiesta, string $workspace): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin', 'supporto']);
        $lista = 'staff.workspace.membri.elenca:'.strtolower($workspace);
        $query = $this->testi->validaQuery(self::query($richiesta), [
            'limite' => ['sometimes', 'integer', 'between:1,'.self::LIMITE_MASSIMO_DELLO_STAFF],
            'cursore' => ['sometimes', 'string', function (string $attributo, mixed $valore, \Closure $fail) use ($lista) {
                if ($this->leggiPosizione($lista, ['id'], $valore) === null) {
                    $this->testi->dichiara($attributo, 'formato');
                    $fail('regole.cursore')->translate();
                }
            }],
        ]);
        $id = $this->workspaceDelloStaff($chi, $workspace);
        $this->scriviNelRegistroDelloStaff($chi, 'staff.workspace.membri.elenca', 'workspace', $id);
        $voci = [];

        foreach ($this->membri[$id] ?? [] as $persona => $ruolo) {
            $voci[] = ['id' => $persona] + $this->voceDelMembroPerLoStaff($id, $persona);
        }

        $query['limite'] ??= self::LIMITE_DELLO_STAFF;
        [$stato, $corpo] = $this->impagina(
            $query, $lista, 'id', $voci,
            fn (array $voce) => [$voce['creato_il'], $voce['id']],
            fn (string $persona) => isset($this->membri[$id][$persona]) ? [self::iso($this->entrati[$id][$persona]), $persona] : null,
        );
        $corpo['data'] = array_map(fn (array $voce) => array_diff_key($voce, ['id' => 1]), $corpo['data']);

        return [$stato, $corpo];
    }

    /**
     * staff.workspace.membri.modifica (#3258, StaffWorkspaceMembriController::modifica): il ruolo di un membro, `amministratore` o `membro`. Solo il superadmin. Nell'ordine del backoffice: il ruolo, il
     * workspace (404), il membro (404), il corpo (422: `proprietario` non è ammesso, nessuno lo diventa da qui), il proprietario (409 `proprieta_da_trasferire`). Lo stesso ruolo di prima risponde 200 e
     * non scrive niente; un cambio vero lascia una riga nel registro dello staff (nei campi `ruolo`). Nessun avviso a nessuno.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function modificaIlRuoloDiUnMembroDelloStaff(Request $richiesta, array $corpo, string $workspace, string $utente): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin']);
        $id = $this->workspaceDelloStaff($chi, $workspace);
        $persona = $this->membroDelloStaff($id, $utente);
        $ruolo = (string) $this->testi->validaStretta($corpo, ['ruolo' => ['required', 'string', Rule::in(['amministratore', 'membro'])]])['ruolo'];

        if ($this->membri[$id][$persona] === 'proprietario') {
            throw new Problema('proprieta_da_trasferire');
        }

        if ($this->membri[$id][$persona] !== $ruolo) {
            $this->membri[$id][$persona] = $ruolo;
            $this->scriviNelRegistroDelloStaff($chi, 'staff.workspace.membri.modifica', 'workspace', $id, ['ruolo']);
        }

        return [200, ['data' => $this->voceDelMembroPerLoStaff($id, $persona)]];
    }

    /**
     * staff.workspace.membri.elimina (#3258, StaffWorkspaceMembriController::elimina): toglie un membro (204). Solo il superadmin. Il workspace (404), il membro (404, anche la seconda volta), il
     * proprietario (409 `proprieta_da_trasferire`). Una riga nel registro dello staff; nessun avviso a nessuno.
     *
     * @return array{int, null}
     */
    private function eliminaUnMembroDelloStaff(Request $richiesta, string $workspace, string $utente): array
    {
        [, $chi] = $this->autenticaLoStaff($richiesta, ['superadmin']);
        $id = $this->workspaceDelloStaff($chi, $workspace);
        $persona = $this->membroDelloStaff($id, $utente);

        if ($this->membri[$id][$persona] === 'proprietario') {
            throw new Problema('proprieta_da_trasferire');
        }

        $this->togliIlMembro($id, $persona);
        $this->scriviNelRegistroDelloStaff($chi, 'staff.workspace.membri.elimina', 'workspace', $id);

        return [204, null];
    }

    /** L'id di una persona che è membro di quel workspace, o 404: di un altro workspace, tolta o inventata è lo stesso 404. */
    private function membroDelloStaff(string $workspace, string $utente): string
    {
        $utente = strtolower($utente);

        if (! isset($this->membri[$workspace][$utente])) {
            throw new Problema('non_trovato');
        }

        return $utente;
    }

    /**
     * La riga di un membro per lo staff: `utente` (id, nome, cognome, email), `ruolo` e `creato_il`. Mai altro.
     *
     * @return array{utente: array<string, string|null>, ruolo: string, creato_il: string}
     */
    private function voceDelMembroPerLoStaff(string $workspace, string $persona): array
    {
        $dati = $this->persone[$persona] ?? [];

        return [
            'utente' => ['id' => $persona, 'nome' => $dati['nome'] ?? null, 'cognome' => $dati['cognome'] ?? null, 'email' => $dati['email'] ?? null],
            'ruolo' => $this->membri[$workspace][$persona],
            'creato_il' => self::iso($this->entrati[$workspace][$persona]),
        ];
    }

    /** L'id di un workspace che c'è e che chi chiama vede (solo_uat: il suo perimetro); se no 404. */
    private function workspaceDelloStaff(array $chi, string $id): string
    {
        $id = strtolower($id);

        if (! isset($this->workspace[$id]) || ($chi['solo_uat'] && ! $this->proprietarioDiProva($id))) {
            throw new Problema('non_trovato');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $query  `limite` e `cursore`, già validati
     * @param  list<array<string, mixed>>  $righe
     * @return array{int, array<string, mixed>}
     */
    private function impaginaIWorkspace(array $query, string $lista, array $righe): array
    {
        $query['limite'] ??= self::LIMITE_DELLO_STAFF;

        return $this->impagina(
            $query, $lista, 'id', $righe,
            fn (array $voce) => [$voce['creato_il'], $voce['id']],
            fn (string $id) => isset($this->workspace[$id]) ? [self::iso($this->creatoIlDelWorkspace($id)), $id] : null,
            true,
        );
    }

    /**
     * I workspace che chi chiama vede, con i filtri: tutti quelli vivi, o per chi è solo_uat quelli del suo perimetro.
     *
     * @param  array<string, mixed>  $chi
     * @param  array<string, mixed>  $filtri
     * @return list<array<string, mixed>>
     */
    private function righeDeiWorkspace(array $chi, array $filtri): array
    {
        $righe = [];
        $parole = isset($filtri['q']) ? array_values(array_filter(preg_split('/\s+/u', Str::lower(trim((string) $filtri['q']))) ?: [])) : [];

        foreach ($this->workspace as $id => $workspace) {
            if ($chi['solo_uat'] && ! $this->proprietarioDiProva($id)) {
                continue;
            }

            $testo = Str::lower(Str::ascii($workspace['nome'].' '.$workspace['slug']));

            if (array_filter($parole, fn (string $parola) => ! str_contains($testo, Str::ascii($parola))) !== []) {
                continue;
            }

            $riga = $this->rigaDelWorkspace($id);

            if (isset($filtri['stato']) && $riga['stato'] !== $filtri['stato']) {
                continue;
            }

            $righe[] = $riga;
        }

        return $righe;
    }

    /** @return array<string, mixed> la riga dell'elenco */
    private function rigaDelWorkspace(string $id): array
    {
        $workspace = $this->workspace[$id];
        $proprietario = null;

        foreach ($this->membri[$id] ?? [] as $persona => $ruolo) {
            if ($ruolo === 'proprietario' && isset($this->persone[$persona])) {
                $proprietario = ['id' => $persona, 'nome' => $this->persone[$persona]['nome'], 'cognome' => $this->persone[$persona]['cognome']];
                break;
            }
        }

        return [
            'id' => $id,
            'nome' => $workspace['nome'],
            'indirizzo' => $workspace['slug'],
            'azienda' => $this->aziende[$workspace['azienda_id']] ?? null,
            'proprietario' => $proprietario,
            'membri_n' => count($this->membri[$id] ?? []),
            'app_attive_n' => count($this->attive[$id] ?? []),
            'stato' => isset($this->eliminazioni[$id]) && $this->eliminazioni[$id]->isFuture() ? 'in_eliminazione' : 'attivo',
            'creato_il' => self::iso($this->creatoIlDelWorkspace($id)),
        ];
    }

    private function creatoIlDelWorkspace(string $id): \Carbon\CarbonImmutable
    {
        return $this->workspaceCreatoIl[$id] ?? \Carbon\CarbonImmutable::parse('2026-01-01T00:00:00.000Z');
    }
}
