<?php

namespace Zeiras\Auth\Testing\Finto;

use Closure;

/**
 * Le avanzate di un campo «tuo» del CRM nel backoffice finto (#2155, CRM-12 C2): `aiuto`, `predefinito`, `limiti`, `in_elenco` e
 * `nel_crea` di `crm.campi.crea` e `crm.campi.modifica`, con le regole e i pointer di `CampiController::avanzate` del backoffice.
 * `aiuto` e `limiti` sono un Merge Patch (`null` toglie una lingua o un limite, o tutto); il `predefinito` si giudica come un valore di
 * `campi` e il finto lo porta senza applicarlo; i limiti valgono per le scritture nuove di `campi` (CrmValori).
 */
trait CrmAvanzate
{
    private const CRM_AVANZATE = ['aiuto', 'predefinito', 'limiti', 'in_elenco', 'nel_crea'];

    private const CRM_AIUTO_MASSIMO = 200;

    /** I caratteri del predefinito di un testo (un valore di `campi` arriva al tetto del suo tipo). */
    private const CRM_PREDEFINITO_TESTO = 1000;

    /** @var array<string, list<string>> */
    private const CRM_CHIAVI_DEI_LIMITI = [
        'testo' => ['lunghezza_max'],
        'testo_lungo' => ['lunghezza_max'],
        'numero' => ['minimo', 'massimo'],
        'valuta' => ['minimo', 'massimo'],
        'percentuale' => ['minimo', 'massimo'],
        'data' => ['minimo', 'massimo'],
    ];

    /**
     * Le regole del corpo per le avanzate, comuni a crea e modifica: la forma di ciascuna; il contenuto lo giudica `avanzateCrm`.
     *
     * @return array<string, list<mixed>>
     */
    private function regoleDelleAvanzateCrm(): array
    {
        return [
            'aiuto' => ['sometimes', 'nullable', 'array'],
            'aiuto.it' => ['sometimes', 'nullable', 'string', 'max:'.self::CRM_AIUTO_MASSIMO, new NomeScritto],
            'aiuto.en' => ['sometimes', 'nullable', 'string', 'max:'.self::CRM_AIUTO_MASSIMO, new NomeScritto],
            'aiuto.es' => ['sometimes', 'nullable', 'string', 'max:'.self::CRM_AIUTO_MASSIMO, new NomeScritto],
            'limiti' => ['sometimes', 'nullable', 'array'],
            'limiti.lunghezza_max' => ['sometimes', 'nullable'],
            'limiti.minimo' => ['sometimes', 'nullable'],
            'limiti.massimo' => ['sometimes', 'nullable'],
            'predefinito' => ['sometimes', 'nullable'],
            'in_elenco' => ['filled', 'boolean:strict'],
            'nel_crea' => ['filled', 'boolean:strict'],
        ];
    }

    /**
     * Le avanzate del corpo come si scrivono nella riga: colonna → valore nuovo, per le sole chiavi che il corpo ha.
     *
     * @param  array<string, mixed>  $campi  il corpo validato
     * @param  array<string, mixed>|null  $riga  il campo com'è (null alla creazione)
     * @param  list<array<string, mixed>>|null  $opzioni
     * @return array<string, mixed>
     */
    private function avanzateCrm(string $workspace, array $campi, string $tipo, ?array $opzioni, ?array $riga): array
    {
        $nuove = [];
        $limiti = $riga['limiti'] ?? null;

        if (array_key_exists('limiti', $campi)) {
            $limiti = $nuove['limiti'] = $this->limitiNuoviCrm($tipo, $campi['limiti'], $riga['limiti'] ?? null);
        }

        if (array_key_exists('aiuto', $campi)) {
            $nuove['aiuto'] = $this->aiutoNuovoCrm($campi['aiuto'], $riga['aiuto'] ?? null);
        }

        if (array_key_exists('predefinito', $campi)) {
            $nuove['predefinito'] = $this->predefinitoNuovoCrm($workspace, $tipo, $campi['predefinito'], $limiti, $opzioni, $riga === null);
        } elseif ($riga !== null && ($riga['predefinito'] ?? null) !== null && array_key_exists('limiti', $campi)) {
            // Cambiano i limiti e il predefinito che c'è resta: deve rientrare nei limiti nuovi (un PATCH solo li cambia insieme).
            try {
                $this->predefinitoNuovoCrm($workspace, $tipo, $this->valoreInUscitaCrm($workspace, $tipo, $riga['predefinito']), $limiti, $opzioni, false);
            } catch (Problema $problema) {
                throw $this->erroreCrm('limiti', 'regole.campo_limiti_predefinito', (string) ($problema->errori[0]['regola'] ?? Regola::FUORI_INTERVALLO));
            }
        }

        foreach (['in_elenco', 'nel_crea'] as $chiave) {
            if (array_key_exists($chiave, $campi)) {
                $nuove[$chiave] = $campi[$chiave];
            }
        }

        return $nuove;
    }

    /**
     * @param  array<string, mixed>|null  $dati
     * @param  array<string, mixed>|null  $attuali
     * @return array<string, mixed>|null
     */
    private function limitiNuoviCrm(string $tipo, ?array $dati, ?array $attuali): ?array
    {
        if ($dati === null) {
            return null;
        }

        if (! isset(self::CRM_CHIAVI_DEI_LIMITI[$tipo])) {
            throw $this->erroreCrm('limiti', 'regole.campo_limiti_non_ammessi', Regola::CAMPO_VIETATO);
        }

        $regole = ['limiti' => ['array']];

        foreach (self::CRM_CHIAVI_DEI_LIMITI[$tipo] as $chiave) {
            $regole["limiti.{$chiave}"] = match ($tipo) {
                'testo' => ['nullable', 'integer:strict', 'between:1,'.self::CRM_VALORE_TESTO],
                'testo_lungo' => ['nullable', 'integer:strict', 'between:1,'.self::CRM_VALORE_TESTO_LUNGO],
                'valuta' => ['nullable', 'integer:strict', 'between:0,'.self::CRM_VALORE_IMPORTO],
                'data' => ['nullable', 'string', 'date_format:Y-m-d'],
                default => ['nullable', $this->unNumeroCrm()],
            };
        }

        $this->testi->validaCorpo(['limiti' => $dati], $regole);
        $limiti = array_filter(array_replace($attuali ?? [], $dati), fn (mixed $valore) => $valore !== null);

        if (isset($limiti['minimo'], $limiti['massimo']) && $limiti['minimo'] > $limiti['massimo']) {
            throw $this->erroreCrm('limiti.'.(($dati['minimo'] ?? null) !== null ? 'minimo' : 'massimo'), 'regole.campo_limiti_invertiti', Regola::FUORI_INTERVALLO);
        }

        return $limiti === [] ? null : $limiti;
    }

    /**
     * @param  array<string, mixed>|null  $dati
     * @param  array<string, string>|null  $attuale
     * @return array<string, string>|null
     */
    private function aiutoNuovoCrm(?array $dati, ?array $attuale): ?array
    {
        if ($dati === null) {
            return null;
        }

        $aiuto = $attuale ?? [];

        foreach ($dati as $lingua => $testo) {
            if ($testo === null) {
                if ($lingua === 'it') {
                    throw $this->erroreCrm('aiuto.it', 'regole.campo_aiuto_it_obbligatorio', Regola::OBBLIGATORIO);
                }

                unset($aiuto[$lingua]);
            } else {
                $aiuto[$lingua] = $testo;
            }
        }

        if ($aiuto !== [] && ! isset($aiuto['it'])) {
            throw $this->erroreCrm('aiuto.it', 'regole.campo_aiuto_it_obbligatorio', Regola::OBBLIGATORIO);
        }

        return $aiuto === [] ? null : $aiuto;
    }

    /**
     * @param  array<string, mixed>|null  $limiti
     * @param  list<array<string, mixed>>|null  $opzioni
     */
    private function predefinitoNuovoCrm(string $workspace, string $tipo, mixed $valore, ?array $limiti, ?array $opzioni, bool $creazione): mixed
    {
        if ($valore === null) {
            return null;
        }

        if ($tipo === 'membro') {
            throw $this->erroreCrm('predefinito', 'regole.campo_predefinito_membro', Regola::CAMPO_VIETATO);
        }

        if ($creazione && in_array($tipo, ['elenco', 'scelta_multipla'], true)) {
            throw $this->erroreCrm('predefinito', 'regole.campo_predefinito_dopo_le_opzioni', Regola::VALORE_NON_AMMESSO);
        }

        $campo = ['tipo' => $tipo, 'limiti' => $limiti, 'opzioni' => $opzioni];
        $regole = $this->regoleDiUnValoreCrm($workspace, 'predefinito', $campo);
        $tetto = in_array($tipo, ['testo', 'testo_lungo', 'url'], true) ? ['max:'.self::CRM_PREDEFINITO_TESTO] : [];
        $regole['predefinito'] = ['bail', ...$regole['predefinito'], ...$tetto];
        $validato = $this->testi->validaCorpo(['predefinito' => $valore], $regole)['predefinito'];

        return match ($tipo) {
            'numero', 'percentuale' => is_float($validato) && floor($validato) === $validato && abs($validato) < 1e15 ? (int) $validato : $validato,
            'valuta' => $validato['importo'],
            'elenco' => strtolower((string) $validato),
            'scelta_multipla' => array_map(fn (mixed $id) => strtolower((string) $id), array_values((array) $validato)),
            default => $validato,
        };
    }

    /** Due valori di una riga sono lo stesso valore: un numero intero scritto `1.0` è `1`, e l'ordine delle chiavi non conta. */
    private static function avanzataUgualeCrm(mixed $nuovo, mixed $attuale): bool
    {
        $normalizza = function (mixed $valore) use (&$normalizza) {
            if (is_array($valore)) {
                $valore = array_map($normalizza, $valore);
                array_is_list($valore) || ksort($valore);

                return $valore;
            }

            return is_float($valore) && floor($valore) === $valore && abs($valore) < 1e15 ? (int) $valore : $valore;
        };

        return $normalizza($nuovo) === $normalizza($attuale);
    }

    /** Un numero JSON, non una stringa: come `ValoriDeiCampi::unNumero`. */
    private function unNumeroCrm(): Closure
    {
        return function (string $nome, mixed $valore, Closure $fail) {
            if (! (is_int($valore) || is_float($valore)) || ! is_finite((float) $valore) || abs($valore) > self::CRM_VALORE_MASSIMO
                || round((float) $valore, self::CRM_VALORE_DECIMALI) !== (float) $valore) {
                $this->rifiutaCrm($fail, $nome, Regola::NON_VALIDA, 'regole.campo_numero');
            }
        };
    }

    /**
     * Un valore dentro `minimo` e `massimo` del campo, se ci sono (`ValoriDeiCampi::neiLimiti`): `fuori_intervallo`.
     *
     * @param  array<string, mixed>  $campo
     */
    private function neiLimitiCrm(array $campo): Closure
    {
        return function (string $nome, mixed $valore, Closure $fail) use ($campo) {
            $limiti = $campo['limiti'] ?? [];
            $minimo = $limiti['minimo'] ?? null;
            $massimo = $limiti['massimo'] ?? null;

            if (! is_int($valore) && ! is_float($valore) && ! is_string($valore)) {
                return;
            }

            if (($minimo !== null && $valore < $minimo) || ($massimo !== null && $valore > $massimo)) {
                $this->rifiutaCrm($fail, $nome, Regola::FUORI_INTERVALLO, 'regole.campo_fuori_limiti');
            }
        };
    }
}
