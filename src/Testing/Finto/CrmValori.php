<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Closure;
use stdClass;

/**
 * I valori dei campi «tuoi» sui contatti del CRM del backoffice finto (sprint 37, #2125, CRM-12 B): il campo `campi` di
 * `crm.persone.crea` e `crm.persone.modifica`, e `campi` nella scheda intera (`mostra`, `crea`, `modifica`; mai nell'elenco e nella
 * ricerca). Le regole, la normalizzazione e l'ordine sono quelli di `App\Crm\ValoriDeiCampi`: i campi che valgono sono i «tuoi» del
 * workspace (uno standard, o una chiave che non c'è, è `campo_estraneo` sul suo pointer); un valore `null` lo toglie; uno uguale non
 * scrive niente; la cronologia nomina `campi.<chiave>`, col valore di prima e di dopo solo per i tipi che lo ammettono. Il finto non
 * emette l'evento e non tiene lo storico: lo dice il README. Le aziende e le trattative arrivano con #2126 e #2127.
 */
trait CrmValori
{
    /** Le lunghezze e i tetti dei valori (ValoriDeiCampi). */
    private const CRM_VALORE_TESTO = 255;

    private const CRM_VALORE_TESTO_LUNGO = 10000;

    private const CRM_VALORE_URL = 2000;

    private const CRM_VALORE_MASSIMO = 10000000000;

    private const CRM_VALORE_DECIMALI = 4;

    private const CRM_VALORE_SCELTE = 50;

    private const CRM_VALORE_IMPORTO = 1000000000000;

    /** I tipi i cui valori la cronologia dice anche di prima e di dopo (ValoriDeiCampi::CON_VALORI). */
    private const CRM_VALORI_NELLA_CRONOLOGIA = ['numero', 'valuta', 'percentuale', 'data', 'data_ora', 'si_no', 'elenco', 'scelta_multipla', 'membro'];

    /**
     * I campi «tuoi» di un oggetto, per chiave, nel loro ordine (posto, poi nascita).
     *
     * @return array<string, array<string, mixed>>
     */
    private function campiTuoiCrm(string $workspace, string $oggetto): array
    {
        $this->campiDelWorkspaceCrm($workspace);
        $campi = array_filter($this->campiCrm[$workspace][$oggetto]['campi'], fn (array $campo) => $campo['origine'] === 'tuo' && ($campo['eliminato_il'] ?? null) === null);
        uasort($campi, fn (array $a, array $b) => $a['ord'] <=> $b['ord']);

        return array_map(fn (array $campo) => $this->conOpzioniDelloElencoCrm($campo), $campi);
    }

    /**
     * Le regole di `campi` per il corpo di una creazione (un campo obbligatorio vuole il suo valore) o di una modifica (non si toglie
     * con `null`), da unire a quelle del record. La chiave `-` dà a `campi` un figlio anche senza campi «tuoi»: senza, una chiave
     * qualunque passerebbe.
     *
     * @return array<string, mixed>
     */
    private function regoleDeiValoriCrm(string $workspace, string $oggetto, bool $creazione, ?string $id = null, mixed $inviati = null): array
    {
        $regole = ['campi' => ['sometimes', 'array'], 'campi.-' => ['prohibited']];
        $campi = $this->campiTuoiCrm($workspace, $oggetto);
        $invariati = $id === null || ! is_array($inviati) ? [] : $this->valoriInvariatiCrm($oggetto, $id, $campi, $inviati);

        foreach ($campi as $chiave => $campo) {
            // Un valore rimandato uguale a quello salvato non si rigiudica coi limiti del campo (il tipo sì): solo uno nuovo o cambiato.
            if (isset($invariati[$chiave])) {
                $campo['limiti'] = null;
            }

            $apertura = $campo['obbligatorio'] ? ['bail', $creazione ? 'required' : 'filled'] : ['bail', 'nullable'];

            foreach ($this->regoleDiUnValoreCrm($workspace, "campi.{$chiave}", $campo, true) as $attributo => $regoleDelValore) {
                $regole[$attributo] = $attributo === "campi.{$chiave}" ? [...$apertura, ...$regoleDelValore] : $regoleDelValore;
            }
        }

        // Un campo eliminato (ripristinabile per 30 giorni) non si scrive più: la sua chiave è 422 `non_trovato`, non `campo_estraneo`.
        foreach ($this->campiEliminatiCrm($workspace, $oggetto) as $campo) {
            $regole["campi.{$campo['codice']}"] = [function (string $nome, mixed $valore, Closure $fail) {
                $this->rifiutaCrm($fail, $nome, Regola::NON_TROVATO, 'regole.campo_eliminato');
            }];
        }

        return $regole;
    }

    /**
     * Le chiavi dei campi con limiti il cui valore nel corpo è lo stesso che il record ha salvato (ValoriDeiCampi::invariati).
     *
     * @param  array<string, array<string, mixed>>  $campi
     * @param  array<string, mixed>  $inviati
     * @return array<string, true>
     */
    private function valoriInvariatiCrm(string $oggetto, string $id, array $campi, array $inviati): array
    {
        $invariati = [];

        foreach ($campi as $chiave => $campo) {
            if (($campo['limiti'] ?? null) === null || ! array_key_exists($chiave, $inviati) || ! array_key_exists($chiave, $this->crm['valori'][$oggetto][$id] ?? [])) {
                continue;
            }

            $inviato = $inviati[$chiave];
            $salvato = $this->crm['valori'][$oggetto][$id][$chiave];

            $uguale = match ($campo['tipo']) {
                'testo', 'testo_lungo', 'data' => is_string($inviato) && $inviato === $salvato,
                'numero', 'percentuale' => (is_int($inviato) || is_float($inviato)) && (is_int($salvato) || is_float($salvato)) && (float) $inviato === (float) $salvato,
                'valuta' => is_array($inviato) && is_int($inviato['importo'] ?? null) && $inviato['importo'] === $salvato,
                default => false,
            };

            if ($uguale) {
                $invariati[$chiave] = true;
            }
        }

        return $invariati;
    }

    /**
     * Le righe di una cronologia senza le voci dei campi «tuoi» eliminati (`campi.<chiave>`): finché il campo non c'è per l'API, la sua
     * storia non esce (e tornano col ripristino). Una riga che diceva solo di quelle esce intera.
     *
     * @param  list<array<string, mixed>>  $righe
     * @return list<array<string, mixed>>
     */
    private function senzaCampiEliminatiCrm(string $workspace, string $oggetto, array $righe): array
    {
        $this->campiDelWorkspaceCrm($workspace);
        $nascosti = [];

        foreach ($this->campiCrm[$workspace][$oggetto]['campi'] as $campo) {
            if ($campo['origine'] === 'tuo' && ($campo['eliminato_il'] ?? null) !== null) {
                $nascosti[] = "campi.{$campo['codice']}";
            }
        }

        if ($nascosti === []) {
            return $righe;
        }

        $fuori = [];

        foreach ($righe as $riga) {
            $voci = $riga['campi'] ?? [];

            if (! is_array($voci) || $voci === []) {
                $fuori[] = $riga;

                continue;
            }

            $restano = array_values(array_filter($voci, fn (mixed $voce) => ! (is_array($voce) && in_array($voce['campo'] ?? null, $nascosti, true))));

            if ($restano !== []) {
                $fuori[] = ['campi' => $restano] + $riga;
            }
        }

        return $fuori;
    }

    /**
     * Le regole di un valore del campo `$campo` (tipo, limiti, opzioni vive) sotto `$attributo`: una per il valore intero e, per una
     * valuta, una per ogni parte. Le stesse valgono per un valore di `campi` e per il `predefinito` (ValoriDeiCampi::regoleDiUnValore).
     *
     * @param  array<string, mixed>  $campo
     * @return array<string, list<mixed>>
     */
    private function regoleDiUnValoreCrm(string $workspace, string $attributo, array $campo, bool $conArchiviate = false): array
    {
        $regole = [$attributo => $this->regoleDelTipoCrm($workspace, (string) $campo['tipo'], $campo, $conArchiviate)];

        if ($campo['tipo'] === 'valuta') {
            $regole["{$attributo}.importo"] = ['bail', 'integer:strict', 'between:0,'.self::CRM_VALORE_IMPORTO, $this->neiLimitiCrm($campo)];
            $regole["{$attributo}.valuta"] = ['bail', 'string', function (string $nome, mixed $valore, Closure $fail) use ($workspace) {
                if ($valore !== $this->valutaCrm($workspace)) {
                    $this->rifiutaCrm($fail, $nome, Regola::VALORE_NON_AMMESSO, 'regole.valuta_del_workspace', ['valuta' => $this->valutaCrm($workspace)]);
                }
            }];
        }

        return $regole;
    }

    /**
     * Le regole del tipo del campo, sul valore intero.
     *
     * @param  array<string, mixed>  $campo
     * @return list<mixed>
     */
    private function regoleDelTipoCrm(string $workspace, string $tipo, array $campo, bool $conArchiviate = false): array
    {
        $limiti = $campo['limiti'] ?? [];

        return match ($tipo) {
            'testo' => ['string', 'max:'.min(self::CRM_VALORE_TESTO, (int) ($limiti['lunghezza_max'] ?? self::CRM_VALORE_TESTO)), new SenzaCarattereNullo],
            'testo_lungo' => ['string', 'max:'.min(self::CRM_VALORE_TESTO_LUNGO, (int) ($limiti['lunghezza_max'] ?? self::CRM_VALORE_TESTO_LUNGO)), new SenzaCarattereNullo],
            'url' => ['string', 'max:'.self::CRM_VALORE_URL, 'url:http,https', new SenzaCarattereNullo],
            'numero', 'percentuale' => [function (string $nome, mixed $valore, Closure $fail) {
                if (! (is_int($valore) || is_float($valore)) || ! is_finite((float) $valore) || abs($valore) > self::CRM_VALORE_MASSIMO
                    || round((float) $valore, self::CRM_VALORE_DECIMALI) !== (float) $valore) {
                    $this->rifiutaCrm($fail, $nome, Regola::NON_VALIDA, 'regole.campo_numero');
                }
            }, $this->neiLimitiCrm($campo)],
            'valuta' => ['array', function (string $nome, mixed $valore, Closure $fail) {
                if (! is_array($valore) || ! array_key_exists('importo', $valore)) {
                    $this->rifiutaCrm($fail, $nome, Regola::OBBLIGATORIO, 'regole.campo_valuta');
                }
            }],
            'data' => ['string', 'date_format:Y-m-d', $this->neiLimitiCrm($campo)],
            'data_ora' => ['string', function (string $nome, mixed $valore, Closure $fail) {
                $parti = [];
                $istante = is_string($valore) && preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(\.\d{1,6})?Z$/', $valore, $parti) === 1
                    ? CarbonImmutable::createFromFormat('!Y-m-d H:i:s', "{$parti[1]} {$parti[2]}", 'UTC')
                    : false;

                if ($istante === false || $istante->format('Y-m-d H:i:s') !== "{$parti[1]} {$parti[2]}") {
                    $this->rifiutaCrm($fail, $nome, Regola::FORMATO, 'regole.campo_data_ora');
                }
            }],
            'si_no' => ['boolean:strict'],
            'elenco' => ['string', $this->unaOpzioneCrm($campo, $conArchiviate)],
            'scelta_multipla' => ['array', 'list', 'min:1', 'max:'.self::CRM_VALORE_SCELTE, $this->scelteDelCampoCrm($campo, $conArchiviate)],
            'membro' => ['string', function (string $nome, mixed $valore, Closure $fail) use ($workspace) {
                if (! isset($this->membri[$workspace][strtolower((string) $valore)])) {
                    $this->rifiutaCrm($fail, $nome, Regola::VALORE_NON_AMMESSO, 'regole.responsabile_del_workspace');
                }
            }],
            default => [],
        };
    }

    /**
     * La lista di una scelta multipla, già valida come lista di 1-50 voci: ogni voce è un'opzione viva del campo e nessuna compare due
     * volte, senza distinguere le maiuscole. Una closure sola sulla lista (ValoriDeiCampi::scelteDelCampo): l'errore è sul pointer
     * della lista, e nessuna regola per voce.
     *
     * @param  array<string, mixed>  $campo
     */
    private function scelteDelCampoCrm(array $campo, bool $conArchiviate = false): Closure
    {
        return function (string $nome, mixed $valore, Closure $fail) use ($campo, $conArchiviate) {
            $vive = array_filter($campo['opzioni'] ?? [], fn (array $opzione) => $conArchiviate || ($opzione['archiviato_il'] ?? null) === null);
            $ammessi = array_fill_keys(array_map(fn (mixed $id) => strtolower((string) $id), array_column($vive, 'id')), true);
            $viste = [];

            foreach ((array) $valore as $id) {
                if (! is_string($id) || ! isset($ammessi[strtolower($id)])) {
                    $this->rifiutaCrm($fail, $nome, Regola::VALORE_NON_AMMESSO, 'regole.campo_opzione');

                    return;
                }

                if (isset($viste[strtolower($id)])) {
                    $this->rifiutaCrm($fail, $nome, Regola::DUPLICATO, 'regole.campo_scelte_doppie');

                    return;
                }

                $viste[strtolower($id)] = true;
            }
        };
    }

    /**
     * L'id di un'opzione viva del campo (senza distinguere maiuscole e minuscole).
     *
     * @param  array<string, mixed>  $campo
     */
    private function unaOpzioneCrm(array $campo, bool $conArchiviate = false): Closure
    {
        return function (string $nome, mixed $valore, Closure $fail) use ($campo, $conArchiviate) {
            $vive = array_filter($campo['opzioni'] ?? [], fn (array $opzione) => $conArchiviate || ($opzione['archiviato_il'] ?? null) === null);
            $ids = array_map(fn (mixed $id) => strtolower((string) $id), array_column($vive, 'id'));

            if (! is_string($valore) || ! in_array(strtolower($valore), $ids, true)) {
                $this->rifiutaCrm($fail, $nome, Regola::VALORE_NON_AMMESSO, 'regole.campo_opzione');
            }
        };
    }

    /** Il valore com'è nella riga, dal valore validato: un intero resta intero, un id sta in minuscolo, un istante in UTC coi millisecondi. */
    private static function valorePerLaRigaCrm(string $tipo, mixed $valore): mixed
    {
        return match ($tipo) {
            'numero', 'percentuale' => is_float($valore) && floor($valore) === $valore && abs($valore) < 1e15 ? (int) $valore : $valore,
            'valuta' => $valore['importo'],
            'data_ora' => self::iso(CarbonImmutable::parse((string) $valore, 'UTC')),
            'elenco', 'membro' => strtolower((string) $valore),
            'scelta_multipla' => array_map(fn (mixed $id) => strtolower((string) $id), array_values((array) $valore)),
            default => $valore,
        };
    }

    /** Il valore come lo dà l'API: una valuta è `{importo, valuta}`, gli altri sono quelli della riga. */
    private function valoreInUscitaCrm(string $workspace, string $tipo, mixed $valore): mixed
    {
        return $tipo === 'valuta' ? ['importo' => $valore, 'valuta' => $this->valutaCrm($workspace)] : $valore;
    }

    /**
     * I valori vivi di un contatto: `{<chiave>: valore}` dei soli campi «tuoi», nell'ordine dei campi. Sempre un oggetto: senza valori
     * `{}` in JSON, mai `[]`, e una chiave fatta di cifre non lo fa diventare una lista.
     */
    private function valoriDiCrm(string $workspace, string $oggetto, string $id): stdClass
    {
        $valori = [];

        foreach ($this->campiTuoiCrm($workspace, $oggetto) as $chiave => $campo) {
            if (array_key_exists($chiave, $this->crm['valori'][$oggetto][$id] ?? [])) {
                $valori[$chiave] = $this->valoreInUscitaCrm($workspace, (string) $campo['tipo'], $this->crm['valori'][$oggetto][$id][$chiave]);
            }
        }

        return (object) $valori;
    }

    /**
     * I valori dei soli campi «tuoi» vivi che stanno in elenco (`in_elenco`), per la riga di `elenca` (#2169): come valoriDiCrm(),
     * ma senza gli altri. Sempre un oggetto: senza valori `{}`.
     */
    private function valoriInElencoCrm(string $workspace, string $oggetto, string $id): stdClass
    {
        $valori = [];

        foreach ($this->campiTuoiCrm($workspace, $oggetto) as $chiave => $campo) {
            if (($campo['in_elenco'] ?? false) === true && array_key_exists($chiave, $this->crm['valori'][$oggetto][$id] ?? [])) {
                $valori[$chiave] = $this->valoreInUscitaCrm($workspace, (string) $campo['tipo'], $this->crm['valori'][$oggetto][$id][$chiave]);
            }
        }

        return (object) $valori;
    }

    /**
     * Ciò che una scrittura di `campi` cambierebbe, senza scrivere: per chiave, nell'ordine dei campi, il tipo, il valore di prima e di
     * dopo come li dà l'API e il valore della riga (`null` toglie). Un valore uguale, o una rimozione di ciò che non c'è, non cambia.
     *
     * @param  array<string, mixed>  $valori
     * @return array<string, array{tipo: string, prima: mixed, dopo: mixed, riga: mixed}>
     */
    private function pianoDeiValoriCrm(string $workspace, string $oggetto, string $id, array $valori): array
    {
        $piano = [];

        foreach ($this->campiTuoiCrm($workspace, $oggetto) as $chiave => $campo) {
            if (! array_key_exists($chiave, $valori)) {
                continue;
            }

            $tipo = (string) $campo['tipo'];
            $presente = array_key_exists($chiave, $this->crm['valori'][$oggetto][$id] ?? []);
            $prima = $presente ? $this->crm['valori'][$oggetto][$id][$chiave] : null;
            $riga = $valori[$chiave] === null ? null : self::valorePerLaRigaCrm($tipo, $valori[$chiave]);
            $this->soloLeArchiviateDiPrimaCrm($chiave, $campo, $prima, $riga);

            if ($riga === null && ! $presente || $presente && $prima === $riga) {
                continue;
            }

            $piano[$chiave] = [
                'tipo' => $tipo,
                'prima' => $presente ? $this->valoreInUscitaCrm($workspace, $tipo, $prima) : null,
                'dopo' => $riga === null ? null : $this->valoreInUscitaCrm($workspace, $tipo, $riga),
                'riga' => $riga,
            ];
        }

        return $piano;
    }

    /**
     * Un'opzione archiviata resta sul record che la ha: un valore nuovo non la può prendere (422 `valore_non_ammesso` su
     * `#/campi/<chiave>`), come ValoriDeiCampi::soloLeArchiviateDiPrima.
     *
     * @param  array<string, mixed>  $campo
     */
    private function soloLeArchiviateDiPrimaCrm(string $chiave, array $campo, mixed $prima, mixed $dopo): void
    {
        if ($dopo === null || ! in_array($campo['tipo'], ['elenco', 'scelta_multipla'], true)) {
            return;
        }

        $archiviate = array_map(fn (mixed $id) => strtolower((string) $id), array_column(array_filter($campo['opzioni'] ?? [], fn (array $opzione) => ($opzione['archiviato_il'] ?? null) !== null), 'id'));
        $erano = array_map(fn (mixed $id) => strtolower((string) $id), (array) $prima);

        foreach ((array) $dopo as $id) {
            if (in_array(strtolower((string) $id), $archiviate, true) && ! in_array(strtolower((string) $id), $erano, true)) {
                throw $this->erroreCrm("campi.{$chiave}", 'regole.campo_opzione', Regola::VALORE_NON_AMMESSO);
            }
        }
    }

    /**
     * Scrive un piano: un valore nuovo nasce, uno diverso si riscrive, `null` toglie la riga.
     *
     * @param  array<string, array{tipo: string, prima: mixed, dopo: mixed, riga: mixed}>  $piano
     */
    private function scriviIValoriCrm(string $oggetto, string $id, array $piano): void
    {
        foreach ($piano as $chiave => $voce) {
            if ($voce['riga'] === null) {
                unset($this->crm['valori'][$oggetto][$id][$chiave]);
            } else {
                $this->crm['valori'][$oggetto][$id][$chiave] = $voce['riga'];
            }
        }
    }

    /**
     * Le voci della cronologia di un piano, nell'ordine dei campi: `campi.<chiave>`, col valore di prima e di dopo solo per i tipi che
     * lo ammettono (Q2, zr-pm 13/10) e il solo nome per gli altri.
     *
     * @param  array<string, array{tipo: string, prima: mixed, dopo: mixed, riga: mixed}>  $piano
     * @return list<array<string, mixed>>
     */
    private static function vociDeiValoriCrm(array $piano): array
    {
        $voci = [];

        foreach ($piano as $chiave => $voce) {
            $voci[] = in_array($voce['tipo'], self::CRM_VALORI_NELLA_CRONOLOGIA, true)
                ? ['campo' => "campi.{$chiave}", 'prima' => $voce['prima'], 'dopo' => $voce['dopo']]
                : ['campo' => "campi.{$chiave}"];
        }

        return $voci;
    }
}
