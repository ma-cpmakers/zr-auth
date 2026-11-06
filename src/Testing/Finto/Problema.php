<?php

namespace Zeiras\Auth\Testing\Finto;

use RuntimeException;

/**
 * Un errore di /v1 nel backoffice finto: un codice del catalogo, i valori rifiutati se è `dati_non_validi`, gli header
 * della risposta. Lo scrive BackofficeFinto, coi testi del backoffice (Testi).
 */
final class Problema extends RuntimeException
{
    /** Lo stato HTTP dei codici che il finto usa, come nel catalogo del contratto. */
    private const STATI = [
        'gettone_assente' => 401,
        'gettone_non_valido' => 401,
        'gettone_con_workspace' => 403,
        'gettone_senza_workspace' => 403,
        'permesso_negato' => 403,
        'cliente_non_riconosciuto' => 401,
        'secondo_fattore_non_valido' => 401,
        'invito_non_valido' => 401,
        'app_non_attiva' => 403,
        'email_non_verificata' => 403,
        'accettazione_richiesta' => 403,
        'conferma_richiesta' => 403,
        'precondizione_fallita' => 412,
        'registrazione_non_aperta' => 403,
        'non_trovato' => 404,
        'foto_assente' => 404,
        'app_in_arrivo' => 409,
        'gia_membro' => 409,
        'invito_esistente' => 409,
        'collegamento_esistente' => 409,
        'record_gia_collegato' => 409,
        'scheda_non_gate' => 409,
        'gate_gia_richiesto' => 409,
        'gate_deciso' => 409,
        'gate_in_approvazione' => 409,
        'gate_senza_approvatore' => 409,
        'gate_non_richiesto' => 409,
        'approvatore_non_disponibile' => 409,
        'approvatore_gia_indicato' => 409,
        'promemoria_gia_presente' => 409,
        'identita_gia_collegata' => 409,
        'provider_gia_collegato' => 409,
        'scheda_archiviata' => 409,
        'lista_non_trasferibile' => 409,
        'lista_gia_esistente' => 409,
        'board_chiusa' => 409,
        'board_senza_metodo' => 409,
        'metodo_waterfall_fisso' => 409,
        'sprint_attivo' => 409,
        'sprint_chiuso' => 409,
        'sprint_non_pianificato' => 409,
        'sprint_non_attivo' => 409,
        'ruolo_gia_assegnato' => 409,
        'nessuna_pianificazione' => 409,
        'sprint_non_chiuso' => 409,
        'retrospettiva_esistente' => 409,
        'voto_gia_dato' => 409,
        'scheda_non_di_retrospettiva' => 409,
        'limite_raggiunto' => 409,
        'prenotazioni_al_limite' => 409,
        'trasferimento_impossibile' => 409,
        'termine_scaduto' => 409,
        'apertura_con_prenotazioni' => 409,
        'aperture_non_possibili' => 409,
        'crediti_esauriti' => 409,
        'acquisto_scaduto' => 409,
        'acquisto_necessario' => 409,
        'struttura_presente' => 409,
        'piu_persone' => 409,
        'persona_presente' => 409,
        'rata_pagata' => 409,
        'preventivo_non_modificabile' => 409,
        'nome_gia_usato' => 409,
        'scadenza_chiusa' => 409,
        'preventivo_stato_non_ammesso' => 409,
        'preventivo_scaduto' => 409,
        'gia_accettato' => 409,
        'preventivo_sostituito' => 409,
        'rate_con_pagamenti' => 409,
        'pagamento_annullato' => 409,
        'versione_non_corrente' => 409,
        'fatturazione_non_disponibile' => 409,
        'fatturazione_gia_collegata' => 409,
        'fatturazione_non_collegata' => 409,
        'fattura_gia_emessa' => 409,
        'impronta_non_corrispondente' => 409,
        'documento_non_revocabile' => 409,
        'documento_gia_revocato' => 409,
        'esportazione_in_corso' => 409,
        'proprietario_intoccabile' => 409,
        'posizione_cambiata' => 409,
        'transizione_non_valida' => 409,
        'email_gia_presente' => 409,
        'pipeline_non_vuota' => 409,
        'fase_non_vuota' => 409,
        'ultima_fase' => 409,
        'valuta_in_uso' => 409,
        'funzione_vincolata' => 409,
        'personalizzazione_con_dati' => 409,
        'pacchetto_gia_installato' => 409,
        'persona_anonimizzata' => 409,
        'annullamento_scaduto' => 409,
        'unione_non_valida' => 409,
        'contatto_non_unibile' => 409,
        'contatto_unito' => 409,
        'gia_annullata' => 409,
        'campo_di_sistema' => 409,
        'ultima_opzione' => 409,
        'elenco_in_uso' => 409,
        'sezione_di_sistema' => 409,
        'sezione_non_vuota' => 409,
        'ultimo_workspace' => 409,
        'workspace_in_eliminazione' => 409,
        'proprieta_da_trasferire' => 409,
        'unico_amministratore' => 409,
        'staff_gia_presente' => 409,
        'ultimo_superadmin' => 409,
        'account_sospeso' => 403,
        'gia_sospeso' => 409,
        'utente_mai_acceduto' => 409,
        'impersonificazione_non_consentita' => 403,
        'azione_non_consentita_in_assistenza' => 403,
        'esportazione_non_disponibile' => 409,
        'esportazione_troppo_grande' => 413,
        'eliminazione_programmata' => 409,
        'versione_scaduta' => 410,
        'dati_non_validi' => 422,
        'dominio_non_ammesso' => 422,
        'collegamento_circolare' => 422,
        'catena_troppo_lunga' => 422,
        'credenziali_non_valide' => 422,
        'chiave_idempotenza_riusata' => 422,
        'verifica_non_riuscita' => 422,
        'email_del_provider_non_verificata' => 422,
        'turnstile_non_valido' => 422,
        'cursore_scaduto' => 410,
        'troppe_richieste' => 429,
        'programma_non_raggiungibile' => 502,
        'servizio_non_disponibile' => 503,
        'turnstile_non_disponibile' => 503,
    ];

    /**
     * @param  list<array{detail: string, regola: string, pointer?: string, parameter?: string}>  $errori  per `dati_non_validi`, e solo per lui
     * @param  array<string, string>  $header  per `troppe_richieste` Retry-After, in secondi
     * @param  array<string, mixed>  $estensioni  i membri estesi del problema: `limite` e `direzione` di `limite_raggiunto`, `mancanti` di `accettazione_richiesta`, `accordo` del 422 su `accordo_trattamento_dati_accettato`
     * @param  ?string  $verifica  per `verifica_non_riuscita`, e solo per lui: l'operazione di cui il `detail` dice il caso
     *                             (`email`, `password`, `ingresso`, `invito`, `registrazione`, `provider`)
     */
    public function __construct(
        public readonly string $codice,
        public readonly array $errori = [],
        public readonly array $header = [],
        public readonly array $estensioni = [],
        public readonly ?string $verifica = null,
    ) {
        parent::__construct($codice);
    }

    /**
     * Lo stesso problema con altri header in più (le intestazioni dei freni che il backoffice aggiunge a ogni risposta di un metodo).
     *
     * @param  array<string, string>  $header
     */
    public function conHeader(array $header): self
    {
        return new self($this->codice, $this->errori, [...$this->header, ...$header], $this->estensioni, $this->verifica);
    }

    public function stato(): int
    {
        return self::STATI[$this->codice];
    }
}
