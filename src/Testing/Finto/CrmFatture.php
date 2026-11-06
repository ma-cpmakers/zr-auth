<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Http\Client\Request;

/**
 * Le fatture del CRM nel backoffice finto (#2926, #3342, #3343): `crm.fatture.crea`, `crm.rate.fattura.pdf.mostra` e `crm.acquisti.fattura.pdf.mostra`,
 * con le regole, i pointer e l'ordine di `FattureController` e `Emissione` del backoffice. Il programma di fatturazione è finto: ogni emissione riesce e dà
 * il numero `N/A` (N cresce per workspace), e il PDF è un indirizzo di prova che vale 10 minuti. L'intestatario è chi paga (`paga_per`, altrimenti il
 * contatto) col suo profilo predefinito non archiviato, e l'IVA è quella della voce di catalogo: senza profilo, con un profilo incompleto o senza aliquota
 * è 422 sul campo della richiesta. Una rata o un acquisto ha una fattura sola, e l'acquisto e le sue rate non si fatturano due volte. Il finto non emette gli
 * eventi.
 */
trait CrmFatture
{
    private const CRM_FATTURE_VALIDITA_PDF = 600;

    /**
     * crm.fatture.crea (FattureController::crea): 201 con `id`, `rata_id`, `acquisto_id`, `numero` ed `emessa_il`, in `Location` la rata o l'acquisto.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaFatturaCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);

        return $this->conIdempotenza($richiesta, 'crm.fatture.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, [
                'rata_id' => ['bail', 'required_without:acquisto_id', 'string', 'ulid'],
                'acquisto_id' => ['bail', 'prohibits:rata_id', 'string', 'ulid'],
            ]);
            $rata = isset($campi['rata_id']) ? $this->rigaCrm('rate', $chi['workspace'], $campi['rata_id']) : null;
            $acquisto = $rata !== null ? $this->crm['acquisti'][$rata['acquisto_id']] : $this->rigaCrm('acquisti', $chi['workspace'], $campi['acquisto_id']);

            $this->collegamentoDelleFattureCrm($chi['workspace']);
            $this->controllaLaPersonaDelPagamentoCrm($acquisto);
            $this->richiedeLiberaLaFatturaCrm($rata, $acquisto);
            $this->documentoDellaFatturaCrm($rata, $acquisto, $chi['workspace']);

            $id = self::id();
            $ora = self::adessoCrm();
            $numero = (count(array_filter($this->crm['fatture'], fn (array $fattura) => $fattura['workspace'] === $chi['workspace'])) + 1).'/A';
            $fattura = $this->crm['fatture'][$id] = [
                'id' => $id, 'workspace' => $chi['workspace'], 'rata_id' => $rata['id'] ?? null, 'acquisto_id' => $rata === null ? $acquisto['id'] : null,
                'numero' => $numero, 'emessa_il' => $ora, 'creato_da' => $chi['persona'], 'creato_il' => $ora, 'aggiornato_il' => $ora,
            ];

            // La fattura tocca la rata e l'acquisto (`$touches`): cambiano la forma e l'ETag.
            if ($rata !== null) {
                $this->crm['rate'][$rata['id']]['aggiornato_il'] = self::dopoCrm($this->crm['rate'][$rata['id']]['aggiornato_il']);
            }

            $this->toccaLAcquistoCrm($acquisto['id']);

            return [201, ['data' => [
                'id' => $fattura['id'], 'rata_id' => $fattura['rata_id'], 'acquisto_id' => $fattura['acquisto_id'], 'numero' => $numero, 'emessa_il' => self::isoCrm($ora),
            ]], ['Location' => $rata !== null ? "/v1/crm/rate/{$rata['id']}" : "/v1/crm/acquisti/{$acquisto['id']}"]];
        });
    }

    /**
     * crm.rate.fattura.pdf.mostra e crm.acquisti.fattura.pdf.mostra (Emissione::pdf): `{url, scade_il}`. Senza fattura 404, senza collegamento 409.
     *
     * @return array{int, array<string, mixed>}
     */
    private function pdfDellaFatturaCrm(Request $richiesta, string $tipo, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $riga = $this->rigaCrm($tipo, $chi['workspace'], $id);
        $fattura = $this->fatturaDiCrm($tipo === 'rate' ? 'rata_id' : 'acquisto_id', $riga['id']) ?? throw new Problema('non_trovato');
        $this->collegamentoDelleFattureCrm($chi['workspace']);

        return [200, ['data' => [
            'url' => 'https://programma.example/finto/fatture/'.$fattura['id'].'.pdf',
            'scade_il' => self::isoCrm(self::adessoCrm()->addSeconds(self::CRM_FATTURE_VALIDITA_PDF)),
        ]]];
    }

    /** La fattura di una rata o di un acquisto nello schema FatturaEmessa, o `null` (Forme::fattura). */
    private function voceFatturaCrm(string $campo, string $id): ?array
    {
        $fattura = $this->fatturaDiCrm($campo, $id);

        return $fattura === null ? null : ['numero' => $fattura['numero'], 'emessa_il' => self::isoCrm($fattura['emessa_il'])];
    }

    /** @return array<string, mixed>|null */
    private function fatturaDiCrm(string $campo, string $id): ?array
    {
        foreach ($this->crm['fatture'] as $fattura) {
            if ($fattura[$campo] === $id) {
                return $fattura;
            }
        }

        return null;
    }

    /** 409 per un'azienda spagnola, 409 senza un collegamento vivo (Emissione::collegamento). */
    private function collegamentoDelleFattureCrm(string $workspace): void
    {
        if (! $this->fatturazioneDisponibileCrm($workspace)) {
            throw new Problema('fatturazione_non_disponibile');
        }

        if (! isset($this->crm['fatturazione'][$workspace])) {
            throw new Problema('fatturazione_non_collegata');
        }
    }

    /**
     * Una fattura sola: fatturato l'acquisto non si fattura nessuna rata, fatturata una rata non si fattura l'acquisto (Emissione::richiedeLibera).
     *
     * @param  array<string, mixed>|null  $rata
     * @param  array<string, mixed>  $acquisto
     */
    private function richiedeLiberaLaFatturaCrm(?array $rata, array $acquisto): void
    {
        foreach ($this->crm['fatture'] as $fattura) {
            $dellAcquisto = $fattura['acquisto_id'] === $acquisto['id'];
            $daUnaRata = $rata !== null
                ? $fattura['rata_id'] === $rata['id']
                : $fattura['rata_id'] !== null && ($this->crm['rate'][$fattura['rata_id']]['acquisto_id'] ?? null) === $acquisto['id'] && ! ($this->crm['rate'][$fattura['rata_id']]['eliminata'] ?? false);

            if ($dellAcquisto || $daUnaRata) {
                throw new Problema('fattura_gia_emessa');
            }
        }
    }

    /**
     * Ciò che il backoffice controlla prima di chiamare il programma (Emissione::documento): l'importo, il profilo di chi paga, ciò che ne manca e
     * l'aliquota della voce. Ogni rifiuto è 422 sul campo della richiesta.
     *
     * @param  array<string, mixed>|null  $rata
     * @param  array<string, mixed>  $acquisto
     */
    private function documentoDellaFatturaCrm(?array $rata, array $acquisto, string $workspace): void
    {
        $campo = $rata !== null ? 'rata_id' : 'acquisto_id';
        $rifiuta = fn (string $testo, string $regola, array $estensioni = []) => new Problema('dati_non_validi', [
            ['detail' => $this->testi->testo('regole.'.$testo), 'regola' => $regola, 'pointer' => '#/'.$campo],
        ], estensioni: $estensioni);

        $importo = $rata !== null ? $rata['importo'] : $acquisto['prezzo_importo'];

        if ($importo === null || $importo <= 0) {
            throw $rifiuta('fattura_senza_importo', Regola::NON_VALIDA);
        }

        $pagante = $this->crm['persone'][$acquisto['persona_id']]['paga_per_id'] ?? $acquisto['persona_id'];
        $profilo = null;

        foreach ($this->righeCrm('profili', $workspace) as $riga) {
            if (($riga['persona_id'] === $pagante || $riga['organizzazione_id'] === $pagante) && $riga['predefinito'] && $riga['archiviata_il'] === null) {
                $profilo = $riga;

                break;
            }
        }

        if ($profilo === null) {
            throw $rifiuta('fattura_profilo_assente', Regola::NON_TROVATO);
        }

        $mancano = self::mancanoDelProfiloCrm($profilo, self::areaFiscaleCrm($profilo['paese'], $this->paeseDelWorkspaceCrm($workspace)));

        if ($mancano !== []) {
            throw $rifiuta('fattura_profilo_incompleto', Regola::NON_VALIDA, ['mancano' => $mancano]);
        }

        $voce = $acquisto['voce_id'] === null ? null : ($this->crm['catalogo'][$acquisto['voce_id']] ?? null);

        if ($voce === null || $voce['aliquota_iva'] === null) {
            throw $rifiuta('fattura_senza_aliquota', Regola::NON_VALIDA);
        }
    }
}
