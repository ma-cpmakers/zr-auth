<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;

/**
 * I collegamenti di una scheda a un record del CRM nel backoffice finto (#2419, zr-board #2418): `board.schede.crm.elenca`, `crea` e
 * `elimina`, con le regole, i pointer e l'ordine di `SchedeCrmController`. Sono metodi dell'app `pm`, distinti da
 * `board.schede.collegamenti.*` (le attese fra schede). La board tiene solo il tipo e l'id del record: titolo, contesto e motivo si
 * leggono dal CRM del finto a ogni elenco, mai da una copia. Un record archiviato, anonimizzato o che chi chiama non legge resta
 * nell'elenco con `titolo` e `contesto` a `null` e il `motivo`; un collegamento eliminato non compare e lo stesso record si può
 * ricollegare. Il finto non ha i gettoni nati da un client della base: con l'app `crm` spenta nessun record si legge né si collega
 * (il livello «persone» di `app:crm,base` non c'è), e non emette gli eventi.
 */
trait SchedeCrm
{
    /** I collegamenti vivi di una scheda (`Tetti::COLLEGAMENTI_CRM_PER_SCHEDA`). */
    private const SCHEDE_CRM_TETTO = 50;

    /** I tipi che un collegamento ammette (`CollegamentoCrm::TIPI`) e la lista del CRM del finto da cui si legge ciascuno. */
    private const SCHEDE_CRM_TIPI = ['crm.persona' => 'persone', 'crm.organizzazione' => 'organizzazioni', 'crm.trattativa' => 'trattative'];

    /** @var array<string, array<string, mixed>> i collegamenti vivi, per id (quelli eliminati escono dall'API e dal finto) */
    private array $schedeCrm = [];

    /**
     * board.schede.crm.elenca (SchedeCrmController::elenca): i collegamenti della scheda, per id (l'ordine di nascita), a cursore.
     * Una scheda archiviata si legge.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaSchedeCrm(Request $richiesta, string $scheda): array
    {
        $chi = $this->conPm($richiesta);
        $this->schedaDelPercorso($chi['workspace'], $scheda);
        $voci = array_values(array_map(
            fn (array $collegamento) => $this->voceSchedaCrm($collegamento, $chi['workspace']),
            array_filter($this->schedeCrm, fn (array $collegamento) => $collegamento['scheda'] === $scheda),
        ));

        return $this->pagina($richiesta, 'board.schede.crm.elenca', 'id', $voci, fn (array $voce) => [$voce['id']], fn (string $id) => [$id]);
    }

    /**
     * board.schede.crm.crea (SchedeCrmController::crea): 201 con il collegamento e la `Location`. Nell'ordine del backoffice: il ruolo
     * e l'app `pm` (403), la scheda del percorso (404), la Idempotency-Key, il freno per persona (429), la scheda archiviata (409), il
     * corpo (422), l'app `crm` spenta (403), il record che non c'è nel workspace (422 su `#/id`, un testo solo per l'id di un altro
     * workspace e per uno inventato), il doppione (409 `record_gia_collegato`), il tetto di 50 (409 `limite_raggiunto` con `limite`).
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaSchedaCrm(Request $richiesta, array $corpo, string $scheda): array
    {
        $chi = $this->conPm($richiesta);
        $mia = $this->schedaDelPercorso($chi['workspace'], $scheda);

        return $this->conIdempotenza($richiesta, 'board.schede.crm.crea', $chi, ['corpo' => $corpo, 'percorso' => ['scheda' => $scheda]], function () use ($chi, $corpo, $mia) {
            $this->frena('schede.crm.crea:persona:'.$chi['persona'], self::FRENI['board_crm_persona'], self::MINUTO);

            if ($mia['archiviata_il'] !== null) {
                throw new Problema('scheda_archiviata');
            }

            $campi = $this->testi->validaStretta($corpo, [
                'tipo' => ['bail', 'required', 'string', Rule::in(array_keys(self::SCHEDE_CRM_TIPI))],
                'id' => ['bail', 'required', 'string', 'ulid'],
            ]);
            $tipo = (string) $campi['tipo'];

            if (! $this->appAttiva($chi['workspace'], 'crm')) {
                throw new Problema('app_non_attiva');
            }

            $record = strtolower((string) $campi['id']);

            if (! $this->esisteCrm(self::SCHEDE_CRM_TIPI[$tipo], $chi['workspace'], $record)) {
                throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.record_del_crm'), 'regola' => 'non_trovato', 'pointer' => '#/id']]);
            }

            $vivi = array_filter($this->schedeCrm, fn (array $collegamento) => $collegamento['scheda'] === $mia['id']);

            if (array_filter($vivi, fn (array $collegamento) => $collegamento['tipo'] === $tipo && $collegamento['record'] === $record) !== []) {
                throw new Problema('record_gia_collegato');
            }

            if (count($vivi) >= self::SCHEDE_CRM_TETTO) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::SCHEDE_CRM_TETTO]);
            }

            $id = self::id();
            $this->schedeCrm[$id] = ['id' => $id, 'workspace' => $chi['workspace'], 'scheda' => $mia['id'], 'tipo' => $tipo, 'record' => $record, 'creato_il' => now()->toImmutable()->startOfMillisecond(), 'creato_da' => $chi['persona']];

            $this->tocca($mia['id']);

            return [201, ['data' => $this->voceSchedaCrm($this->schedeCrm[$id], $chi['workspace'])], ['Location' => "/v1/board/schede/{$mia['id']}/crm/{$id}"]];
        });
    }

    /**
     * board.schede.crm.elimina (SchedeCrmController::elimina): toglie il collegamento, 204; da lì non è più in lista e non si elimina
     * di nuovo (404), e lo stesso record si può ricollegare. Non legge il CRM. Un collegamento che non è della scheda del percorso
     * è 404; la scheda archiviata è 409.
     *
     * @return array{int, null}
     */
    private function eliminaSchedaCrm(Request $richiesta, string $scheda, string $collegamento): array
    {
        $chi = $this->conPm($richiesta);
        $mia = $this->schedaDelPercorso($chi['workspace'], $scheda);
        $voce = $this->schedeCrm[$collegamento] ?? null;

        if ($voce === null || $voce['scheda'] !== $mia['id']) {
            throw new Problema('non_trovato');
        }

        if ($mia['archiviata_il'] !== null) {
            throw new Problema('scheda_archiviata');
        }

        unset($this->schedeCrm[$collegamento]);
        $this->tocca($mia['id']);

        return [204, null];
    }

    /**
     * Un collegamento come lo dà il backoffice (Forme::collegamentoCrm): `titolo` e `contesto` sono quelli di oggi nel CRM, o `null`
     * col `motivo`: `anonimizzato` o `archiviato` del record, `non_leggibile` se l'app `crm` è spenta o il record non c'è più.
     *
     * @param  array<string, mixed>  $collegamento
     * @return array<string, mixed>
     */
    private function voceSchedaCrm(array $collegamento, string $workspace): array
    {
        $lista = self::SCHEDE_CRM_TIPI[$collegamento['tipo']];
        $riga = $this->appAttiva($workspace, 'crm') && $this->esisteCrm($lista, $workspace, $collegamento['record']) ? $this->crm[$lista][$collegamento['record']] : null;
        [$titolo, $contesto, $motivo] = $this->letturaSchedaCrm($collegamento['tipo'], $riga);

        return [
            'id' => $collegamento['id'],
            'tipo' => $collegamento['tipo'],
            'record_id' => $collegamento['record'],
            'titolo' => $titolo,
            'contesto' => $contesto,
            'motivo' => $motivo,
            'indirizzo' => '/v1/crm/'.$lista.'/'.$collegamento['record'],
            'creato_il' => self::iso($collegamento['creato_il']),
            'creato_da' => $collegamento['creato_da'],
        ];
    }

    /**
     * RecordDelCrm::leggi: il titolo e il contesto del record, o il motivo per cui non si dicono. Un contatto anonimizzato non ha più
     * un nome da mostrare, e uno archiviato esce dallo stesso titolo; di un'azienda `contesto` è sempre `null`.
     *
     * @param  array<string, mixed>|null  $riga
     * @return array{string|null, array<string, string|null>|null, string|null}
     */
    private function letturaSchedaCrm(string $tipo, ?array $riga): array
    {
        if ($riga === null) {
            return [null, null, 'non_leggibile'];
        }

        if (($riga['anonimizzata_il'] ?? null) !== null) {
            return [null, null, 'anonimizzato'];
        }

        if ($riga['archiviata_il'] !== null) {
            return [null, null, 'archiviato'];
        }

        return match ($tipo) {
            'crm.persona' => [
                trim($riga['nome'].' '.$riga['cognome']),
                ['organizzazione' => $this->nomeDellAziendaCrm($riga['organizzazione_id'] ?? null), 'ruolo' => $riga['ruolo'] ?? null],
                null,
            ],
            'crm.organizzazione' => [$riga['nome'], null, null],
            default => [
                $riga['titolo'],
                ['pipeline' => $this->crm['pipeline'][$riga['pipeline_id']]['nome'], 'fase' => $this->crm['fasi'][$riga['fase_id']]['nome'], 'esito' => $riga['stato']],
                null,
            ],
        };
    }

    private function nomeDellAziendaCrm(?string $id): ?string
    {
        $azienda = $id === null ? null : ($this->crm['organizzazioni'][$id] ?? null);

        return $azienda !== null && ! ($azienda['eliminata'] ?? false) ? $azienda['nome'] : null;
    }
}
