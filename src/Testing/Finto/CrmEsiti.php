<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;

/**
 * Gli esiti delle attività del CRM nel backoffice finto (#1935): `crm.esiti.elenca`, `crea` e `modifica`, con le regole, i pointer e
 * l'ordine di `EsitiController` del backoffice. Per tipo di attività: il nome è unico nel tipo (archiviati compresi, senza maiuscole né
 * accenti), al più 30 per tipo, il tipo non cambia (422 `campo_vietato`). I predefiniti nascono la prima volta che il workspace usa gli
 * esiti, una volta sola (il backoffice li dà alla prima attivazione di `crm`; nel finto il CRM è sempre attivo). Come per i motivi, il
 * finto rinumera i vivi del tipo a passo pieno invece di collocare a metà: l'ordine è lo stesso.
 */
trait CrmEsiti
{
    private const CRM_ESITI_TETTO = 30;

    /** @var array<string, list<string>> gli esiti di partenza per tipo di attività, nell'ordine in cui la pagina li propone */
    private const CRM_ESITI_PREDEFINITI = [
        'chiamata' => ['Ha risposto', 'Nessuna risposta', 'Occupato', 'Da richiamare'],
        'riunione' => ['Svolta', 'Rinviata', 'Annullata'],
        'email' => ['Inviata', 'Ha risposto'],
        'compito' => ['Fatto', 'Non serve più'],
        'messaggio' => ['Inviato', 'Ha risposto'],
    ];

    /** @var array<string, true> i workspace che hanno già avuto i predefiniti */
    private array $crmEsitiSeminati = [];

    /**
     * crm.esiti.crea (EsitiController::crea): in fondo al suo tipo o al posto chiesto; il tetto di 30 per tipo (archiviati compresi), poi il
     * nome unico nel tipo (422 `duplicato` su `#/nome`).
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaEsitoCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);

        return $this->conIdempotenza($richiesta, 'crm.esiti.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, [
                'tipo' => ['bail', 'required', 'string', Rule::in(self::CRM_TIPI_ATTIVITA)],
                'nome' => ['bail', 'required', 'string', 'max:60', new NomeScritto],
                'posizione' => ['sometimes', 'integer:strict', 'min:1'],
            ]);
            $this->seminaEsitiCrm($chi['workspace']);

            if (count($this->esitiDelTipoCrm($chi['workspace'], $campi['tipo'])) >= self::CRM_ESITI_TETTO) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_ESITI_TETTO]);
            }

            $this->esitoUnicoCrm($chi['workspace'], $campi['tipo'], $campi['nome'], null);
            $id = self::id();
            $ora = self::adessoCrm();
            $this->crm['esiti'][$id] = [
                'id' => $id, 'workspace' => $chi['workspace'], 'tipo' => $campi['tipo'], 'nome' => $campi['nome'], 'posizione' => 0,
                'archiviata_il' => null, 'creato_il' => $ora, 'aggiornato_il' => $ora,
            ];
            $this->collocaEsitoCrm($this->crm['esiti'][$id], $campi['posizione'] ?? PHP_INT_MAX);

            return [201, ['data' => $this->voceEsitoCrm($this->crm['esiti'][$id])], ['Location' => "/v1/crm/esiti/{$id}"]];
        });
    }

    /**
     * crm.esiti.modifica (EsitiController::modifica): JSON Merge Patch di `nome`, `posizione` (il posto fra i non archiviati del tipo) e
     * `archiviato`; il `tipo` è 422 `campo_vietato`; un esito archiviato non ha posto (422 su `#/posizione`), ripristinato torna in fondo
     * al suo tipo; il nome è unico nel tipo, archiviati compresi.
     *
     * @return array{int, array<string, mixed>}
     */
    private function modificaEsitoCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $this->seminaEsitiCrm($chi['workspace']);
        $riga = $this->rigaCrm('esiti', $chi['workspace'], $id);

        if (array_key_exists('tipo', $corpo)) {
            throw $this->erroreCrm('tipo', 'regole.esito_tipo_fisso', Regola::CAMPO_VIETATO);
        }

        $campi = $this->modificaCrm($corpo, [
            'nome' => ['bail', 'filled', 'string', 'max:60', new NomeScritto],
            'posizione' => ['filled', 'integer:strict', 'min:1'],
            'archiviato' => ['filled', 'boolean:strict'],
        ]);
        $archiviato = $campi['archiviato'] ?? ($riga['archiviata_il'] !== null);

        if (array_key_exists('posizione', $campi) && $archiviato) {
            throw $this->erroreCrm('posizione', 'regole.esito_archiviato_senza_posto', Regola::VALORE_NON_AMMESSO);
        }

        $nome = $campi['nome'] ?? $riga['nome'];

        if ($nome !== $riga['nome']) {
            $this->esitoUnicoCrm($chi['workspace'], $riga['tipo'], $nome, $riga['id']);
        }

        $nuovi = ['nome' => $nome];

        if ($archiviato !== ($riga['archiviata_il'] !== null)) {
            $nuovi['archiviata_il'] = $archiviato ? self::adessoCrm() : null;
        }

        if (! $archiviato && (array_key_exists('posizione', $campi) || $riga['archiviata_il'] !== null)) {
            $altri = array_values(array_filter($this->esitiViviCrm($chi['workspace'], $riga['tipo']), fn (array $altro) => $altro['id'] !== $riga['id']));
            $posto = min($campi['posizione'] ?? PHP_INT_MAX, count($altri) + 1);

            if ($riga['archiviata_il'] !== null || $this->postoEsitoCrm($riga) !== $posto) {
                array_splice($altri, $posto - 1, 0, [$riga]);

                foreach ($altri as $i => $esito) {
                    if ($esito['id'] !== $riga['id']) {
                        $this->crm['esiti'][$esito['id']]['posizione'] = self::CRM_PASSO * ($i + 1);
                    } else {
                        $nuovi['posizione'] = self::CRM_PASSO * ($i + 1);
                    }
                }
            }
        }

        $this->salvaCrm('esiti', $riga['id'], $nuovi);

        return [200, ['data' => $this->voceEsitoCrm($this->crm['esiti'][$riga['id']])]];
    }

    /**
     * crm.esiti.elenca: per tipo (in ordine alfabetico) e posizione, a cursore; senza `archiviati` (o con `false`) i non archiviati col
     * loro posto nel tipo, con `true` gli archiviati. `tipo` tiene i soli tipi chiesti. Un cursore preso con altri filtri non vale.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaEsitiCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $this->seminaEsitiCrm($chi['workspace']);
        $query = $this->testi->validaQuery(self::query($richiesta), [
            'tipo' => $this->regolaDelleVociCrm('tipo', self::CRM_TIPI_ATTIVITA),
            'archiviati' => ['sometimes', 'string', Rule::in(['true', 'false'])],
        ]);
        $archiviati = ($query['archiviati'] ?? null) === 'true';
        $tipi = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($query['tipo'] ?? ''))), fn (string $voce) => $voce !== '')));
        sort($tipi);
        $righe = array_values(array_filter(
            $this->righeCrm('esiti', $chi['workspace']),
            fn (array $riga) => ($riga['archiviata_il'] !== null) === $archiviati && ($tipi === [] || in_array($riga['tipo'], $tipi, true)),
        ));
        $lista = 'crm.esiti.elenca'.($tipi === [] ? '' : ':'.implode(',', $tipi)).($archiviati ? ':archiviati' : '');

        return $this->paginaCrm($richiesta, $lista, $righe, fn (array $una, array $altra) => [$una['tipo'], $una['posizione'], $una['id']] <=> [$altra['tipo'], $altra['posizione'], $altra['id']], fn (array $riga) => $this->voceEsitoCrm($riga));
    }

    /** @return array<string, mixed> lo schema Esito (Forme::esito) */
    private function voceEsitoCrm(array $riga): array
    {
        return [
            'id' => $riga['id'],
            'tipo' => $riga['tipo'],
            'nome' => $riga['nome'],
            'posizione' => $riga['archiviata_il'] === null ? $this->postoEsitoCrm($riga) : null,
            'archiviato_il' => self::isoCrm($riga['archiviata_il']),
            'creato_il' => self::iso($riga['creato_il']),
            'aggiornato_il' => self::iso($riga['aggiornato_il']),
        ];
    }

    /** @return list<array<string, mixed>> gli esiti del tipo, archiviati compresi */
    private function esitiDelTipoCrm(string $workspace, string $tipo): array
    {
        return array_values(array_filter($this->righeCrm('esiti', $workspace), fn (array $riga) => $riga['tipo'] === $tipo));
    }

    /** @return list<array<string, mixed>> gli esiti non archiviati del tipo, per posizione */
    private function esitiViviCrm(string $workspace, string $tipo): array
    {
        $vivi = array_values(array_filter($this->esitiDelTipoCrm($workspace, $tipo), fn (array $riga) => $riga['archiviata_il'] === null));
        usort($vivi, fn (array $una, array $altra) => [$una['posizione'], $una['id']] <=> [$altra['posizione'], $altra['id']]);

        return $vivi;
    }

    /** Il posto di un esito vivo fra i vivi del suo tipo (1 = in cima). */
    private function postoEsitoCrm(array $riga): int
    {
        return (int) array_search($riga['id'], array_column($this->esitiViviCrm($riga['workspace'], $riga['tipo']), 'id'), true) + 1;
    }

    /** Mette l'esito al posto dato fra i vivi del suo tipo (oltre la fine, in fondo) e rinumera i vivi a passo pieno. */
    private function collocaEsitoCrm(array $riga, int $posto): void
    {
        $altri = array_values(array_filter($this->esitiViviCrm($riga['workspace'], $riga['tipo']), fn (array $altro) => $altro['id'] !== $riga['id']));
        array_splice($altri, min($posto, count($altri) + 1) - 1, 0, [$riga]);

        foreach ($altri as $i => $esito) {
            $this->crm['esiti'][$esito['id']]['posizione'] = self::CRM_PASSO * ($i + 1);
        }
    }

    /** Il nome non è di un altro esito dello stesso tipo, archiviati compresi, senza maiuscole, accenti e spazi ai bordi: altrimenti 422 `duplicato` su `#/nome`. */
    private function esitoUnicoCrm(string $workspace, string $tipo, string $nome, ?string $tranne): void
    {
        foreach ($this->esitiDelTipoCrm($workspace, $tipo) as $altro) {
            if ($altro['id'] !== $tranne && self::nomeSimileCrm($altro['nome'], null) === self::nomeSimileCrm($nome, null)) {
                throw $this->erroreCrm('nome', 'regole.esito_duplicato', Regola::DUPLICATO);
            }
        }
    }

    /** I predefiniti, una volta sola per workspace e solo se non ha già un esito. */
    private function seminaEsitiCrm(string $workspace): void
    {
        if (isset($this->crmEsitiSeminati[$workspace])) {
            return;
        }

        $this->crmEsitiSeminati[$workspace] = true;

        if ($this->righeCrm('esiti', $workspace) !== []) {
            return;
        }

        $ora = self::adessoCrm();

        foreach (self::CRM_ESITI_PREDEFINITI as $tipo => $nomi) {
            foreach ($nomi as $i => $nome) {
                $id = self::id();
                $this->crm['esiti'][$id] = [
                    'id' => $id, 'workspace' => $workspace, 'tipo' => $tipo, 'nome' => $nome, 'posizione' => self::CRM_PASSO * ($i + 1),
                    'archiviata_il' => null, 'creato_il' => $ora, 'aggiornato_il' => $ora,
                ];
            }
        }
    }
}
