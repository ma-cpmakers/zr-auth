# zr-auth

La parte comune dei moduli Zeiras (`zr-crm`, `zr-board`, …): ingresso tramite `zr-home` (OpenID Connect), verifica dei
token, sessione nel workspace, ricezione degli avvisi di `zr-home`, test che scorre le rotte del modulo, componente
React della barra comune.

**Repo pubblico di proposito**: i moduli lo installano da Composer senza credenziali sul server. Quindi qui dentro
**nessun segreto, mai** — niente `.env`, niente id o segreti di client, niente URL interni.

Lo scrive l'agente `zr-home` (è l'altra metà del contratto coi moduli); il contratto sta nella spec di `zr-home`.
