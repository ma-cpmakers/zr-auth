<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;

/**
 * Gli elenchi di valori condivisi del CRM nel backoffice finto (#2753, CRM-12 F3): `crm.elenchi.elenca`, `crea`, `mostra` e `modifica`,
 * con le regole, i pointer e l'ordine di `ElenchiController` e di `App\Crm\Elenchi` del backoffice. Un elenco ha un nome nelle tre lingue
 * e dei valori (`id` che non cambia, `chiave` stabile, nome nelle tre lingue, colore); più campi «tuoi» a elenco o a scelta multipla lo
 * usano al posto delle opzioni proprie (`opzioni: {elenco_id}` in `crm.campi.crea`). `valori` di una modifica è un merge, mai una
 * sostituzione; un valore non si cancella, si archivia; un elenco usato da un campo vivo non si archivia (409 `elenco_in_uso`). Al più
 * 200 elenchi per workspace e 500 valori per elenco, archiviati compresi (409 `limite_raggiunto`).
 */
trait CrmElenchi
{
    private const CRM_ELENCHI_TETTO = 200;

    private const CRM_ELENCHI_VALORI = 500;

    private const CRM_ELENCHI_NOME = 60;

    private const CRM_ELENCHI_CHIAVE = '/^[a-z][a-z0-9_]{0,39}$/';

    private const CRM_ELENCHI_COLORE = '/^#[0-9a-fA-F]{6}$/';

    /**
     * crm.elenchi.crea (ElenchiController::crea): 201 con l'elenco, `Location` ed `ETag`. Il tetto degli elenchi, poi i valori nel loro
     * ordine, tutti nuovi, con chiave e nome `it`.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaElencoCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);

        return $this->conIdempotenza($richiesta, 'crm.elenchi.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, [
                'nome' => ['required', 'array'],
                'nome.it' => ['bail', 'required', 'string', 'max:'.self::CRM_ELENCHI_NOME, new NomeScritto],
                'nome.en' => $this->regoleDiUnNomeCrm(),
                'nome.es' => $this->regoleDiUnNomeCrm(),
                'valori' => ['sometimes', 'array', 'max:'.self::CRM_ELENCHI_VALORI],
                'valori.*' => ['array'],
                'valori.*.chiave' => ['required', 'string', 'regex:'.self::CRM_ELENCHI_CHIAVE],
                'valori.*.nome' => ['required', 'array'],
                'valori.*.nome.it' => ['bail', 'required', 'string', 'max:'.self::CRM_ELENCHI_NOME, new NomeScritto],
                'valori.*.nome.en' => $this->regoleDiUnNomeCrm(),
                'valori.*.nome.es' => $this->regoleDiUnNomeCrm(),
                'valori.*.colore' => ['sometimes', 'nullable', 'string', 'regex:'.self::CRM_ELENCHI_COLORE],
            ]);

            if (count($this->righeCrm('elenchi', $chi['workspace'])) >= self::CRM_ELENCHI_TETTO) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_ELENCHI_TETTO]);
            }

            $date = $campi['valori'] ?? [];
            $this->elencoSiaUnaListaCrm($date);
            $id = self::id();
            $ora = self::adessoCrm();
            $this->crm['elenchi'][$id] = [
                'id' => $id, 'workspace' => $chi['workspace'], 'nome' => $this->nomeDiUnElencoCrm([], $campi['nome']),
                'valori' => $this->unisciIValoriDiUnElencoCrm([], $date), 'archiviata_il' => null, 'creato_il' => $ora, 'aggiornato_il' => $ora,
            ];
            $voce = $this->voceElencoCrm($this->crm['elenchi'][$id]);

            return [201, ['data' => $voce], ['Location' => "/v1/crm/elenchi/{$id}", 'ETag' => self::etagCrm($voce)]];
        });
    }

    /**
     * crm.elenchi.modifica (ElenchiController::modifica): JSON Merge Patch di `nome` (per lingua: `null` toglie `en` ed `es`, non `it`),
     * `valori` (un merge) e `archiviato`, con `If-Match` letto prima del corpo (412 sulla versione vecchia; facoltativo). Un elenco che un
     * campo vivo usa non si archivia (409 `elenco_in_uso` coi campi). Un corpo che non cambia niente non scrive e lascia lo stesso `ETag`.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function modificaElencoCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $riga = $this->rigaCrm('elenchi', $chi['workspace'], $id);
        $this->pretendiLaVersioneCrm($richiesta, $this->voceElencoCrm($riga));
        $campi = $this->modificaCrm($corpo, [
            'nome' => ['filled', 'array'],
            'nome.it' => ['sometimes', 'nullable', 'string', 'max:'.self::CRM_ELENCHI_NOME, new NomeScritto],
            'nome.en' => $this->regoleDiUnNomeCrm(),
            'nome.es' => $this->regoleDiUnNomeCrm(),
            'archiviato' => ['filled', 'boolean:strict'],
            'valori' => ['filled', 'array', 'max:'.self::CRM_ELENCHI_VALORI],
            'valori.*' => ['array'],
            'valori.*.id' => ['filled', 'string', 'max:40'],
            'valori.*.chiave' => ['filled', 'string', 'regex:'.self::CRM_ELENCHI_CHIAVE],
            'valori.*.nome' => ['filled', 'array'],
            'valori.*.nome.it' => ['sometimes', 'nullable', 'string', 'max:'.self::CRM_ELENCHI_NOME, new NomeScritto],
            'valori.*.nome.en' => $this->regoleDiUnNomeCrm(),
            'valori.*.nome.es' => $this->regoleDiUnNomeCrm(),
            'valori.*.colore' => ['sometimes', 'nullable', 'string', 'regex:'.self::CRM_ELENCHI_COLORE],
            'valori.*.posizione' => ['filled', 'integer:strict', 'min:1'],
            'valori.*.archiviato' => ['filled', 'boolean:strict'],
        ]);
        $nuovi = [];

        if (isset($campi['nome'])) {
            if (array_key_exists('it', $campi['nome']) && $campi['nome']['it'] === null) {
                throw $this->erroreCrm('nome.it', 'regole.campo_nome_it_obbligatorio', Regola::OBBLIGATORIO);
            }

            $nome = $this->nomeDiUnElencoCrm($riga['nome'], $campi['nome']);

            if ($nome !== $riga['nome']) {
                $nuovi['nome'] = $nome;
            }
        }

        if (isset($campi['valori'])) {
            $this->elencoSiaUnaListaCrm($campi['valori']);
            $valori = $this->unisciIValoriDiUnElencoCrm($riga['valori'], $campi['valori']);

            if ($valori !== $riga['valori']) {
                $nuovi['valori'] = $valori;
            }
        }

        if (array_key_exists('archiviato', $campi) && $campi['archiviato'] !== ($riga['archiviata_il'] !== null)) {
            if ($campi['archiviato'] && ($usano = $this->campiCheUsanoUnElencoCrm($chi['workspace'], $riga['id'])) !== []) {
                throw new Problema('elenco_in_uso', estensioni: ['campi' => $usano]);
            }

            $nuovi['archiviata_il'] = $campi['archiviato'] ? self::adessoCrm() : null;
        }

        $this->salvaCrm('elenchi', $riga['id'], $nuovi);
        $voce = $this->voceElencoCrm($this->crm['elenchi'][$riga['id']]);

        return [200, ['data' => $voce], ['ETag' => self::etagCrm($voce)]];
    }

    /**
     * crm.elenchi.elenca (ElenchiController::elenca): dal più vecchio, a cursore; senza `archiviati` (o con `false`) i non archiviati, con
     * `true` gli archiviati. Un cursore preso con l'altro valore non vale.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaElenchiCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $query = $this->testi->validaQuery(self::query($richiesta), [
            'archiviati' => ['sometimes', 'string', Rule::in(['true', 'false'])],
        ]);
        $archiviati = ($query['archiviati'] ?? null) === 'true';
        $righe = array_values(array_filter($this->righeCrm('elenchi', $chi['workspace']), fn (array $riga) => ($riga['archiviata_il'] !== null) === $archiviati));

        return $this->paginaCrm($richiesta, 'crm.elenchi.elenca'.($archiviati ? ':archiviati' : ''), $righe, fn (array $una, array $altra) => $una['id'] <=> $altra['id'], fn (array $riga) => $this->voceElencoCrm($riga));
    }

    /** @return array<string, mixed> lo schema Elenco (Forme::elenco): i valori vivi nel loro ordine con `posizione` da 1, poi gli archiviati con `posizione: null` */
    private function voceElencoCrm(array $riga): array
    {
        $valori = [];
        $posto = 0;

        foreach ([false, true] as $archiviati) {
            foreach ($riga['valori'] as $valore) {
                if ((($valore['archiviato_il'] ?? null) !== null) !== $archiviati) {
                    continue;
                }

                $valori[] = [
                    'id' => $valore['id'],
                    'chiave' => $valore['chiave'],
                    'nome' => ['it' => $valore['nome']['it'], 'en' => $valore['nome']['en'] ?? null, 'es' => $valore['nome']['es'] ?? null],
                    'colore' => $valore['colore'] ?? null,
                    'posizione' => $archiviati ? null : ++$posto,
                    'archiviato_il' => $valore['archiviato_il'] ?? null,
                ];
            }
        }

        return [
            'id' => $riga['id'],
            'nome' => ['it' => $riga['nome']['it'], 'en' => $riga['nome']['en'] ?? null, 'es' => $riga['nome']['es'] ?? null],
            'valori' => $valori,
            'archiviato_il' => self::isoCrm($riga['archiviata_il']),
            'creato_il' => self::iso($riga['creato_il']),
            'aggiornato_il' => self::iso($riga['aggiornato_il']),
        ];
    }

    /**
     * L'elenco non archiviato del workspace che un campo vuole usare (`opzioni: {elenco_id}`); uno che non c'è, di un altro workspace o
     * archiviato è 422 `valore_non_ammesso` su `#/opzioni/elenco_id`.
     *
     * @return array<string, mixed>
     */
    private function elencoPerUnCampoCrm(string $workspace, string $id): array
    {
        $riga = $this->crm['elenchi'][strtolower($id)] ?? null;

        if ($riga === null || $riga['workspace'] !== $workspace || $riga['archiviata_il'] !== null) {
            throw $this->erroreCrm('opzioni.elenco_id', 'regole.elenco_non_ammesso', Regola::VALORE_NON_AMMESSO);
        }

        return $riga;
    }

    /**
     * I valori di un elenco come le opzioni di un campo che lo usa: gli stessi (vivi e archiviati), così i record si controllano contro
     * l'elenco com'è adesso. Vuoto se l'elenco non c'è.
     *
     * @return list<array<string, mixed>>
     */
    private function valoriDiUnElencoCrm(string $id): array
    {
        return $this->crm['elenchi'][strtolower($id)]['valori'] ?? [];
    }

    /**
     * Gli id dei campi vivi (non eliminati) che usano l'elenco, nell'ordine di oggetto e chiave: `<oggetto>.<chiave>`.
     *
     * @return list<string>
     */
    private function campiCheUsanoUnElencoCrm(string $workspace, string $elenco): array
    {
        $id = [];

        foreach ($this->campiCrm[$workspace] ?? [] as $oggetto => $gruppo) {
            foreach ($gruppo['campi'] as $chiave => $campo) {
                if (($campo['elenco_id'] ?? null) === $elenco && ($campo['eliminato_il'] ?? null) === null) {
                    $id[] = [(string) $oggetto, (string) $chiave];
                }
            }
        }

        usort($id, fn (array $a, array $b) => $a <=> $b);

        return array_map(fn (array $parte) => "{$parte[0]}.{$parte[1]}", $id);
    }

    /**
     * Il nome nelle tre lingue, con `$nuovi` sopra quello di prima: `null` toglie `en` o `es`. Sempre le tre chiavi.
     *
     * @param  array<string, mixed>  $prima
     * @param  array<string, mixed>  $nuovi
     * @return array{it: string, en: ?string, es: ?string}
     */
    private function nomeDiUnElencoCrm(array $prima, array $nuovi): array
    {
        $nome = $nuovi + $prima + ['en' => null, 'es' => null];

        return ['it' => (string) $nome['it'], 'en' => $nome['en'] ?? null, 'es' => $nome['es'] ?? null];
    }

    /** `valori` è una lista, non un oggetto: 422 `tipo` su `#/valori`. */
    private function elencoSiaUnaListaCrm(mixed $valori): void
    {
        if (! is_array($valori) || ! array_is_list($valori)) {
            throw $this->erroreCrm('valori', 'regole.elenco_valori_non_lista', Regola::TIPO);
        }
    }

    /**
     * Unisce i valori mandati a quelli che ci sono (App\Crm\Elenchi::unisci): un elemento con `id` (o con la `chiave` di uno che c'è) ne
     * cambia nome, colore e posto, o lo archivia o lo ripristina; uno con una chiave nuova nasce, in fondo o al `posizione`. Ciò che non è
     * citato resta com'è. La chiave non cambia. Restano prima i vivi nel loro ordine e poi gli archiviati; oltre 500 è 409.
     *
     * @param  list<array<string, mixed>>  $attuali
     * @param  list<array<string, mixed>>  $date
     * @return list<array<string, mixed>>
     */
    private function unisciIValoriDiUnElencoCrm(array $attuali, array $date): array
    {
        $tutti = [];
        $vive = [];
        $tolte = [];

        foreach ($attuali as $valore) {
            $tutti[$valore['id']] = $valore;

            if (($valore['archiviato_il'] ?? null) === null) {
                $vive[] = $valore['id'];
            } else {
                $tolte[] = $valore['id'];
            }
        }

        $perChiave = array_column($tutti, 'id', 'chiave');
        $viste = [];

        foreach ($date as $i => $dato) {
            $p = (string) $i;
            $id = isset($dato['id']) ? strtolower((string) $dato['id']) : null;
            $chiave = $dato['chiave'] ?? null;
            $esiste = $id !== null ? isset($tutti[$id]) : ($chiave !== null && isset($perChiave[$chiave]));

            if ($id !== null && ! $esiste) {
                throw $this->erroreCrm("valori.{$p}.id", 'regole.elenco_valore_non_trovato', Regola::NON_TROVATO);
            }

            $nuovo = ! $esiste;

            if ($nuovo) {
                $id = self::id();
                $tutti[$id] = ['id' => $id] + $this->valoreNuovoDiUnElencoCrm($p, $dato);
                $perChiave[$dato['chiave']] = $id;
            } else {
                $id ??= $perChiave[$chiave];

                if ($chiave !== null && $chiave !== $tutti[$id]['chiave']) {
                    throw $this->erroreCrm("valori.{$p}.chiave", 'regole.elenco_chiave_fissa', Regola::VALORE_NON_AMMESSO);
                }
            }

            if (isset($viste[$id])) {
                throw $this->erroreCrm('valori.'.$p.'.'.(isset($dato['id']) ? 'id' : 'chiave'), 'regole.elenco_valore_ripetuto', Regola::DUPLICATO);
            }

            $viste[$id] = true;

            if (! $nuovo) {
                $tutti[$id] = $this->valoreCambiatoDiUnElencoCrm($p, $tutti[$id], $dato);
            }

            $eraTolta = in_array($id, $tolte, true);
            $posizione = $dato['posizione'] ?? null;
            $archivia = $dato['archiviato'] ?? $eraTolta;

            if ($archivia && $posizione !== null) {
                throw $this->erroreCrm("valori.{$p}.posizione", 'regole.elenco_valore_archiviato_senza_posto', Regola::VALORE_NON_AMMESSO);
            }

            if ($archivia) {
                if (! $eraTolta) {
                    $vive = array_values(array_diff($vive, [$id]));
                    $tolte[] = $id;
                    $tutti[$id]['archiviato_il'] = self::iso(self::adessoCrm());
                }
            } elseif ($eraTolta) {
                $tolte = array_values(array_diff($tolte, [$id]));
                $tutti[$id]['archiviato_il'] = null;
                $vive = $this->inserisciUnValoreCrm($vive, $id, $posizione);
            } elseif ($nuovo) {
                $vive = $this->inserisciUnValoreCrm($vive, $id, $posizione);
            } elseif ($posizione !== null) {
                $vive = $this->inserisciUnValoreCrm(array_values(array_diff($vive, [$id])), $id, $posizione);
            }
        }

        if (count($vive) + count($tolte) > self::CRM_ELENCHI_VALORI) {
            throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_ELENCHI_VALORI]);
        }

        return array_map(fn (string $id) => $tutti[$id], [...$vive, ...$tolte]);
    }

    /**
     * Un valore nuovo come lo dà l'elemento: la chiave e il nome `it` ci vogliono, e non nasce archiviato.
     *
     * @param  array<string, mixed>  $dato
     * @return array<string, mixed>
     */
    private function valoreNuovoDiUnElencoCrm(string $p, array $dato): array
    {
        if (! isset($dato['chiave'])) {
            throw $this->erroreCrm("valori.{$p}.chiave", 'regole.elenco_chiave_obbligatoria', Regola::OBBLIGATORIO);
        }

        if (! isset($dato['nome']['it'])) {
            throw $this->erroreCrm("valori.{$p}.nome.it", 'regole.campo_nome_it_obbligatorio', Regola::OBBLIGATORIO);
        }

        if (($dato['archiviato'] ?? false) === true) {
            throw $this->erroreCrm("valori.{$p}.archiviato", 'regole.elenco_valore_nuovo_archiviato', Regola::VALORE_NON_AMMESSO);
        }

        return [
            'chiave' => $dato['chiave'],
            'nome' => $this->nomeDiUnElencoCrm([], $dato['nome']),
            'colore' => isset($dato['colore']) ? strtolower($dato['colore']) : null,
            'archiviato_il' => null,
        ];
    }

    /**
     * Un valore che c'è, col nome (per lingua) e il colore dell'elemento sopra quelli di prima: `nome.it` non si toglie, `en`, `es` e
     * `colore` sì, con `null`.
     *
     * @param  array<string, mixed>  $valore
     * @param  array<string, mixed>  $dato
     * @return array<string, mixed>
     */
    private function valoreCambiatoDiUnElencoCrm(string $p, array $valore, array $dato): array
    {
        if (array_key_exists('it', $dato['nome'] ?? []) && $dato['nome']['it'] === null) {
            throw $this->erroreCrm("valori.{$p}.nome.it", 'regole.campo_nome_it_obbligatorio', Regola::OBBLIGATORIO);
        }

        $valore['nome'] = $this->nomeDiUnElencoCrm($valore['nome'], $dato['nome'] ?? []);

        if (array_key_exists('colore', $dato)) {
            $valore['colore'] = $dato['colore'] === null ? null : strtolower($dato['colore']);
        }

        return $valore;
    }

    /**
     * Mette `$id` al posto `$posizione` (da 1) fra i vivi: oltre la fine, o senza posto, in fondo.
     *
     * @param  list<string>  $vive
     * @return list<string>
     */
    private function inserisciUnValoreCrm(array $vive, string $id, ?int $posizione): array
    {
        array_splice($vive, $posizione === null ? count($vive) : min($posizione - 1, count($vive)), 0, [$id]);

        return $vive;
    }
}
