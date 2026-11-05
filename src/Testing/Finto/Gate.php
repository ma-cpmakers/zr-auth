<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;

/**
 * Il Gate di una scheda nel backoffice finto (#2791, zr-board #2767): `board.schede.gate.richiesta.crea` e `board.schede.gate.decisione.crea`
 * (GateController). Una scheda di tipo `gate` (nata con `scheda(… tipo: 'gate')`) ha `esito`, `approvatore` e `decisione` in ogni lettura: l'approvatore
 * è il contatto del CRM collegato con `ruolo` `approvatore` (board.schede.crm.crea), il cui titolo si legge dal CRM del finto a ogni lettura. La
 * richiesta porta il Gate in `in_approvazione`; la decisione, `approvato`, `con_prescrizioni` o `respinto`, la scrive chi chiama per conto di quel
 * contatto, e le prescrizioni nascono come schede della stessa board, tutto o niente. Il finto non ha liste vere né tetti (la lista è una sola), non emette
 * eventi né la notifica `board.gate.deciso` al richiedente, e non ha board nel cestino: una board non attiva è `board_chiusa`.
 */
trait Gate
{
    private const GATE_MOTIVO = 2000;

    private const GATE_PRESCRIZIONE = 500;

    private const GATE_PRESCRIZIONI = 20;

    /**
     * L'esito di una scheda (Gate::esito): `null` se non è un Gate, `in_preparazione` per un Gate che non ha ancora una richiesta.
     *
     * @param  array<string, mixed>  $scheda
     */
    private function esitoDelGate(array $scheda): ?string
    {
        return ($scheda['tipo'] ?? null) === 'gate' ? ($scheda['esito'] ?? 'in_preparazione') : null;
    }

    /**
     * Il collegamento vivo dell'approvatore di una scheda e la sua lettura dal CRM (Gate::collegamento e Gate::disponibile): `null` se non c'è
     * nessun collegamento con quel ruolo; la lettura ha `titolo` `null` se il contatto non si può più mostrare (archiviato, anonimizzato, app `crm` spenta).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    private function approvatoreDelGate(string $scheda, string $workspace): ?array
    {
        foreach ($this->schedeCrm as $collegamento) {
            if ($collegamento['scheda'] === $scheda && ($collegamento['ruolo'] ?? null) === 'approvatore') {
                return [$collegamento, $this->voceSchedaCrm($collegamento, $workspace)];
            }
        }

        return null;
    }

    /**
     * `approvatore` e `decisione` di una scheda Gate come li dà Gate::dettagli: l'approvatore è `{persona_id, titolo}` o `null` se non c'è o non è più
     * disponibile; la decisione `null` prima di una decisione e dopo una nuova richiesta. Una scheda che non è un Gate li ha `null`.
     *
     * @param  array<string, mixed>  $scheda
     * @return array{approvatore: array<string, string>|null, decisione: array<string, mixed>|null}
     */
    private function dettagliDelGate(array $scheda): array
    {
        if (($scheda['tipo'] ?? null) !== 'gate') {
            return ['approvatore' => null, 'decisione' => null];
        }

        $approvatore = $this->approvatoreDelGate($scheda['id'], $scheda['workspace']);
        $titolo = $approvatore === null ? null : $approvatore[1]['titolo'];

        return [
            'approvatore' => $approvatore === null || $titolo === null ? null : ['persona_id' => $approvatore[0]['record'], 'titolo' => $titolo],
            'decisione' => ($scheda['decisione'] ?? null) === null ? null : [
                'decisione' => $scheda['decisione'],
                'deciso_il' => self::iso($scheda['deciso_il']),
                'deciso_da' => $this->personaDelGate($scheda['deciso_da']),
                'per_conto_di' => ['persona_id' => $scheda['per_conto_di'], 'titolo' => $this->titoloDelContattoDelGate($scheda['workspace'], $scheda['per_conto_di'])],
                'motivo' => $scheda['motivo_decisione'] ?? null,
            ],
        ];
    }

    /** Il titolo di un contatto com'è oggi nel CRM, o `null` se non si può più mostrare. */
    private function titoloDelContattoDelGate(string $workspace, string $contatto): ?string
    {
        $riga = $this->appAttiva($workspace, 'crm') && $this->esisteCrm('persone', $workspace, $contatto) ? $this->crm['persone'][$contatto] : null;

        return $this->letturaSchedaCrm('crm.persona', $riga)[0];
    }

    /** @return array{id: string, nome: string} */
    private function personaDelGate(string $persona): array
    {
        return ['id' => $persona, 'nome' => (string) ($this->persone[$persona]['nome'] ?? '')];
    }

    /**
     * Il Gate aperto alle scritture (GateController::gateAperto): una board non attiva è 409 `board_chiusa`, una scheda archiviata
     * `scheda_archiviata`, una che non è un Gate `scheda_non_gate`.
     *
     * @param  array<string, mixed>  $scheda
     */
    private function gateAperto(array $scheda): void
    {
        if (($this->boards[$scheda['board']]['stato'] ?? 'attiva') !== 'attiva') {
            throw new Problema('board_chiusa');
        }

        if ($scheda['archiviata_il'] !== null) {
            throw new Problema('scheda_archiviata');
        }

        if (($scheda['tipo'] ?? null) !== 'gate') {
            throw new Problema('scheda_non_gate');
        }
    }

    /**
     * board.schede.gate.richiesta.crea (GateController::richiestaCrea): il Gate passa a `in_approvazione`, con chi l'ha chiesta e quando; da
     * `in_preparazione`, `con_prescrizioni` e `respinto` (una nuova richiesta cancella la decisione di prima dalla scheda). Nell'ordine del backoffice: il
     * ruolo e l'app `pm` (403), la scheda (404), la Idempotency-Key, poi 409 `board_chiusa`, `scheda_archiviata`, `scheda_non_gate`, `gate_gia_richiesto`,
     * `gate_deciso` e `gate_senza_approvatore`. Senza corpo; 200.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function richiediGate(Request $richiesta, string $scheda): array
    {
        $chi = $this->conPm($richiesta);
        $mia = $this->schedaDelPercorso($chi['workspace'], $scheda);

        return $this->conIdempotenza($richiesta, 'board.schede.gate.richiesta.crea', $chi, ['corpo' => [], 'percorso' => ['scheda' => $scheda]], function () use ($chi, $mia) {
            $this->gateAperto($mia);

            $esito = $this->esitoDelGate($mia);

            if ($esito === 'in_approvazione') {
                throw new Problema('gate_gia_richiesto');
            }

            if ($esito === 'approvato') {
                throw new Problema('gate_deciso');
            }

            $approvatore = $this->approvatoreDelGate($mia['id'], $chi['workspace']);

            if ($approvatore === null || $approvatore[1]['titolo'] === null) {
                throw new Problema('gate_senza_approvatore');
            }

            $ora = now()->toImmutable()->startOfMillisecond();
            $this->schede[$mia['id']] = array_merge($this->schede[$mia['id']], [
                'esito' => 'in_approvazione', 'richiesto_il' => $ora, 'richiesto_da' => $chi['persona'],
                'decisione' => null, 'deciso_il' => null, 'deciso_da' => null, 'per_conto_di' => null, 'motivo_decisione' => null,
            ]);
            $this->tocca($mia['id']);

            return [200, ['data' => ['esito' => 'in_approvazione', 'richiesto_il' => self::iso($ora), 'richiesto_da' => $this->personaDelGate($chi['persona'])]], []];
        });
    }

    /**
     * board.schede.gate.decisione.crea (GateController::decisioneCrea): registra la decisione dell'approvatore, che diventa l'esito, per conto del contatto
     * collegato; le prescrizioni nascono come schede della board. Dopo i 409 della scheda e `gate_non_richiesto` (se non è `in_approvazione`) viene il
     * corpo (422: `motivo` obbligatorio con `con_prescrizioni` e `respinto`, `prescrizioni` da 1 a 20 con `con_prescrizioni` e vietate con le altre),
     * poi `approvatore_non_disponibile`. 200.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function decidiGate(Request $richiesta, array $corpo, string $scheda): array
    {
        $chi = $this->conPm($richiesta);
        $mia = $this->schedaDelPercorso($chi['workspace'], $scheda);

        return $this->conIdempotenza($richiesta, 'board.schede.gate.decisione.crea', $chi, ['corpo' => $corpo, 'percorso' => ['scheda' => $scheda]], function () use ($chi, $corpo, $mia) {
            $this->gateAperto($mia);

            if ($this->esitoDelGate($mia) !== 'in_approvazione') {
                throw new Problema('gate_non_richiesto');
            }

            $campi = $this->testi->validaStretta($corpo, [
                'decisione' => ['bail', 'required', 'string', Rule::in(['approvato', 'con_prescrizioni', 'respinto'])],
                'motivo' => ['bail', 'nullable', 'string', 'min:1', 'max:'.self::GATE_MOTIVO, 'required_if:decisione,con_prescrizioni,respinto'],
                'prescrizioni' => ['bail', 'array', 'list', 'max:'.self::GATE_PRESCRIZIONI, 'required_if:decisione,con_prescrizioni', 'prohibited_unless:decisione,con_prescrizioni'],
                'prescrizioni.*' => ['bail', 'required', 'string', 'min:1', 'max:'.self::GATE_PRESCRIZIONE, new NomeScritto],
            ]);

            $approvatore = $this->approvatoreDelGate($mia['id'], $chi['workspace']);

            if ($approvatore === null || $approvatore[1]['titolo'] === null) {
                throw new Problema('approvatore_non_disponibile');
            }

            $nate = [];

            foreach ($campi['prescrizioni'] ?? [] as $testo) {
                $nate[] = $this->scheda(['id' => $chi['workspace']], (string) $testo, $mia['board'])['id'];
            }

            $ora = now()->toImmutable()->startOfMillisecond();
            $this->schede[$mia['id']] = array_merge($this->schede[$mia['id']], [
                'esito' => $campi['decisione'], 'decisione' => $campi['decisione'], 'deciso_il' => $ora, 'deciso_da' => $chi['persona'],
                'per_conto_di' => $approvatore[0]['record'], 'motivo_decisione' => $campi['motivo'] ?? null,
            ]);
            $this->tocca($mia['id']);

            return [200, ['data' => [
                'esito' => $campi['decisione'],
                'deciso_il' => self::iso($ora),
                'deciso_da' => $this->personaDelGate($chi['persona']),
                'per_conto_di' => ['persona_id' => $approvatore[0]['record'], 'titolo' => $approvatore[1]['titolo']],
                'motivo' => $campi['motivo'] ?? null,
                'prescrizioni' => array_map(fn (string $id) => ['id' => $id, 'scheda_id' => $id], $nate),
            ]], []];
        });
    }
}
