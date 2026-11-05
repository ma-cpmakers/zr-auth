<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;

/**
 * I workspace dei clienti nel backoffice finto (#3255, F3.1, F3.8): `staff.workspace.elenca` e `staff.workspace.ricerca.crea`, per il frontend dello staff (zr-superadmin). Le regole sono
 * quelle di `Staff`: il client firmato, il gettone dello staff, il ruolo (superadmin e supporto), il perimetro `solo_uat` (i workspace il cui proprietario ha un'email `uat+*@castroandpartners.com`).
 * Il termine di ricerca non sta nell'URL: `q`, `nome`, `indirizzo` e `azienda` nella query sono 422 sul parametro. Leggere conta nel tetto delle pagine letture e non scrive nel registro dello staff.
 * Ogni campo che il vero manda `null` il finto lo manda `null` (il proprietario di un workspace che non ne ha uno, il cognome). Uno stato `sospeso` non c'è ancora.
 */
trait StaffWorkspace
{
    private const STATI_DEL_WORKSPACE = ['attivo', 'in_eliminazione'];

    /** @var array<string, \Carbon\CarbonImmutable> quando è nato ogni workspace, per workspace */
    private array $workspaceCreatoIl = [];

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
