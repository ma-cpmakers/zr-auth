#!/usr/bin/env bash
# Le prove della guardia dei segreti (T6.1-T6.3 dello sprint 4, #1173): ogni caso in un repo git temporaneo con un file
# solo, e l'uscita attesa di nessun-segreto.sh: 0 niente, 1 trovato (col nome della variabile), 2 non ha letto tutto. Le
# lancia la CI, e si lanciano anche in locale; escono 1 se un caso non torna.
#
# I nomi segreti nascono qui da variabili, mai scritti accanto a un valore: questo file sta nel repo, e la guardia lo legge.
set -uo pipefail

guardia="$(cd "$(dirname "$0")" && pwd)/nessun-segreto.sh"
base=$(mktemp -d) || exit 2
trap 'rm -rf "$base"' EXIT
export GIT_CEILING_DIRECTORIES="$base"
falliti=0
casi=0

# Il repo di un caso: vuoto, con git che non chiede chi sei.
repo() {
    local dir="$base/$((casi + 1))"
    mkdir -p "$dir" && git -C "$dir" init -q && printf '%s\n' "$dir"
}

# Lancia la guardia in $1 e confronta: $2 il nome del caso, $3 l'uscita attesa, $4 un testo che l'uscita deve contenere.
controlla() {
    local dir=$1 nome=$2 attesa=$3 detto=${4:-} uscita codice
    casi=$((casi + 1))
    uscita=$(cd "$dir" && bash "$guardia" 2>&1)
    codice=$?

    if [ "$codice" = "$attesa" ] && { [ -z "$detto" ] || grep -qF -- "$detto" <<<"$uscita"; }; then
        echo "ok  $nome"
    else
        echo "NO  $nome: uscita $codice, attesa $attesa${detto:+ con «$detto»}"
        printf '%s\n' "$uscita" | sed 's/^/    /'
        falliti=$((falliti + 1))
    fi
}

# Un caso con un file: $1 il nome del caso, $2 il file, $3 il contenuto, $4 l'uscita attesa, $5 il testo atteso.
caso() {
    local dir
    dir=$(repo) || exit 2
    mkdir -p "$(dirname "$dir/$2")"
    printf '%s\n' "$3" > "$dir/$2"
    git -C "$dir" add -A
    controlla "$dir" "$1" "$4" "${5:-}"
}

grande=CLIENT_SECRET
piccolo=client_secret

# I nomi, i gettoni, gli indirizzi e le chiavi dei casi nascono a pezzi: scritti interi, la guardia li vedrebbe qui.
nl=$'\n'
nome_g=GETTONE
nome_gp=gettone
nome_w=LOG_SLACK_WEBHOOK_URL
nome_p=DB_PASS
nome_d=DB_DSN
nome_c=GOOGLE_CREDENTIALS
nome_k=Key
nome_ch=chiave
nome_s=secret
nome_pw=password
nome_K=KEY
forma_g="zr_$(printf 'Ab1%.0s' {1..16})"
slack="hooks.slack"".com"
bearer="Bear""er"
putty="PuTTY-User""-Key-File-3"
ip_vpn="10.10.0.$((2 + 3))"
ip_hosts="127.0.0.$((1 + 1))"
server="zeiras""-server"
interno="cpmakers"".net"

# T6.1 — un segnaposto passa solo come valore intero: in testa a un valore, la guardia scatta e dice il nome.
for valore in '~k3y' '...k3y' '…k3y' 'null-k3y' '`k3y`' '#k3y' '$k3y' '{k3y}' "''k3y"; do
    caso "T6.1 segnaposto in testa: ${valore}" esempio.txt "${grande}=${valore}" 1 "$grande"
done

for valore in '' '""' "''" '<valore>' '$VAR' '${VAR}' '${{ secrets.X }}' '{{ x }}' '…' '...' 'null' '~' ' # un commento'; do
    caso "T6.1 segnaposto intero: «${valore}»" esempio.txt "${grande}=${valore}" 0
done

caso 'T6.1 la fine di un codice in linea' esempio.md "Scrivi \`${grande}=\` e poi il valore." 0
caso 'T6.1 YAML: un segnaposto in testa' prova.yml "${grande}: ~k3y" 1 "$grande"
caso 'T6.1 YAML: un segnaposto intero' prova.yml "${grande}: \${{ secrets.X }}" 0

# T6.2 — i nomi minuscoli, il JSON e gli array PHP; un file con un NUL non si legge, e il README lo dice.
caso 'T6.2 nome minuscolo' chiama.sh "curl -d ${piccolo}=abc123 https://example.com" 1 "$piccolo"
caso 'T6.2 JSON' config.json "{\"${piccolo}\": \"abc123\"}" 1 "$piccolo"
caso 'T6.2 array PHP' config.php "<?php return ['${piccolo}' => 'abc123'];" 1 "$piccolo"
caso 'T6.2 array PHP fra virgolette doppie' config.php "<?php return [\"${piccolo}\" => \"abc123\"];" 1 "$piccolo"
caso 'T6.2 JSON con un segnaposto intero' config.json "{\"${piccolo}\": \"<valore>\", \"altro\": \"\"}" 0
caso 'T6.2 array PHP col valore da una costante' config.php "<?php return ['${piccolo}' => SEGNAPOSTO];" 0

caso 'T6.2 una traduzione non si legge come array' resources/lang/it/validation.php "<?php return ['current_${piccolo#client_}' => 'Non è corretta.'];" 0
caso 'T6.2 la stessa riga fuori dalle traduzioni' src/messaggi.php "<?php return ['current_${piccolo#client_}' => 'Non è corretta.'];" 1 'current_secret'

dir=$(repo) || exit 2
printf '%s=abc123\n\0' "$grande" > "$dir/binario.dat"
git -C "$dir" add -A
controlla "$dir" 'T6.2 un file con un NUL non si legge' 0

# T6.3 — un file che non si apre, o git che non risponde: la guardia esce 2 e lo dice, mai «niente trovato».
dir=$(repo) || exit 2
printf 'niente\n' > "$dir/sparito.txt"
git -C "$dir" add -A
rm "$dir/sparito.txt"
controlla "$dir" 'T6.3 un file che non si apre' 2 'non ha letto tutto'

dir="$base/fuori"
mkdir -p "$dir"
controlla "$dir" 'T6.3 fuori da un repo git' 2 'non è in un repo git'

# Revisione della #1173 — un segnaposto vuoto, o la virgoletta che apre un valore, non coprono ciò che segue.
for valore in '":abc"' ' abc' ')x9k' 'null,k3y'; do
    caso "R1 un valore dopo un segnaposto vuoto: «${valore}»" esempio.txt "${grande}=${valore}" 1 "$grande"
done
caso 'R1 un valore fra virgolette che va a capo' esempio.txt "${grande}=\"${nl}abc\"" 1 "$grande"
caso 'R1 JSON: un valore che comincia con un segno' config.json "{\"${piccolo}\": \".Xk9\"}" 1 "$piccolo"
caso 'R1 array PHP: un valore che comincia con uno spazio' config.php "<?php return ['${piccolo}' => ' abc'];" 1 "$piccolo"
caso 'R1 YAML: un valore che comincia con uno spazio' prova.yml "${piccolo}: ' abc'" 1 "$piccolo"
caso "R1 l'assegnazione fra virgolette con un segnaposto" avvio.sh "export \"${grande}=\$VAR\"" 0
caso 'R1 un valore dopo il vuoto e una virgola' esempio.txt "${grande}=,x9" 1 "$grande"
caso 'R1 YAML: un valore che comincia con una virgola' prova.yml "${grande}: ,x9" 1 "$grande"
caso 'R1 YAML: la chiave di actions/cache come espressione sola' ci.yml "key: \${{ steps.cache.outputs.valore }}" 0
caso "R1 l'assegnazione vuota fra virgolette" avvio.php "<?php putenv('${grande}=');" 0

# I nomi del progetto e quelli che mancavano: il gettone, il webhook, PASS, DSN, le credenziali; e i gettoni, i webhook
# di Slack e i Bearer riconosciuti dalla forma, anche senza un nome accanto.
caso 'R2 GETTONE' esempio.txt "${nome_g}=abc123" 1 "$nome_g"
caso 'R2 gettone in un array PHP' config.php "<?php return ['${nome_gp}' => 'abc123'];" 1 "$nome_gp"
caso 'R2 il webhook di Slack per nome' esempio.txt "${nome_w}=https://example.com/x" 1 "$nome_w"
caso 'R2 PASS' esempio.txt "${nome_p}=abc123" 1 "$nome_p"
caso 'R2 bypass e passo non sono PASS' esempio.txt "bypass=1 passo=3" 0
caso 'R2 DSN' esempio.txt "${nome_d}=mysql://u:p@h/db" 1 "$nome_d"
caso 'R2 le credenziali' esempio.txt "${nome_c}=abc123" 1 "$nome_c"
caso 'R2 un gettone di Zeiras senza nome' esempio.sh "echo ${forma_g}" 1 'un gettone di Zeiras'
caso 'R2 un webhook di Slack senza nome' esempio.sh "curl -X POST https://${slack}/services/T000/B000/XXXXXXXX" 1 'un webhook di Slack'
caso 'R2 un Bearer con un gettone' esempio.md "curl -H \"Authorization: ${bearer} abcd1234efgh\"" 1 "un gettone dopo ${bearer}"
caso 'R2 un Bearer con un segnaposto' esempio.md "Ogni chiamata porta \`Authorization: ${bearer} <gettone>\`." 0

# Una chiave fra virgolette con un trattino o un punto è un nome come le altre.
caso 'R3 array PHP con un trattino' client.php "<?php Http::withHeaders(['X-Api-${nome_k}' => 'abc123']);" 1 "X-Api-${nome_k}"
caso 'R3 array PHP con un punto' prova.php "<?php config(['zr-auth.${nome_ch}' => 'abc123']);" 1 "zr-auth.${nome_ch}"
caso 'R3 JSON con un trattino' config.json "{\"client-${nome_s}\": \"abc123\"}" 1 "client-${nome_s}"

# Le forme che la guardia non vedeva: il valore a capo, gli argomenti con nome, gli oggetti JS, config() e define(), le
# chiavi YAML fra virgolette o con uno spazio prima dei due punti, le chiavi PuTTY, gli archivi.
caso 'R8 array PHP col valore a capo' config.php "<?php return [${nl}    '${piccolo}' =>${nl}        'abc123',${nl}];" 1 "$piccolo"
caso 'R8 un argomento con nome' client.php "<?php new Cliente(${piccolo}: 'abc123');" 1 "$piccolo"
caso 'R8 un oggetto JS' config.js "const c = { ${nome_pw}: 'abc123' };" 1 "$nome_pw"
caso 'R8 config() con un valore di riserva' config.php "<?php \$x = config('services.x.${nome_s}', 'abc123');" 1 "services.x.${nome_s}"
caso 'R8 define()' config.php "<?php define('API_${nome_K}', 'abc123');" 1 "API_${nome_K}"
caso 'R8 YAML: una chiave fra virgolette' prova.yml "\"${grande}\": abc123" 1 "$grande"
caso 'R8 YAML: uno spazio prima dei due punti' prova.yml "${grande} : abc123" 1 "$grande"
caso 'R8 una chiave PuTTY dal nome' chiave.ppk 'niente' 1 'file sensibile'
caso 'R8 una chiave PuTTY in un altro file' appunti.txt "${putty}: ssh-ed25519" 1 'chiave privata'
caso 'R8 un archivio' pacco.zip 'niente' 1 'file sensibile'
caso "R8 gettone e chiave nelle stringhe di un freno" finto.php "<?php \$k = '${nome_gp}:'.\$chi;" 0

# Nessun indirizzo interno: la VPN, il nome del server nel file hosts, i nomi ssh, i domini interni.
caso 'R9 un indirizzo della VPN' README.md "Il server è ${ip_vpn}." 1 'un indirizzo interno'
caso "R9 l'indirizzo del file hosts" README.md "Risolve in ${ip_hosts}." 1 'un indirizzo interno'
caso 'R9 il nome ssh del server' README.md "ssh ${server} uptime" 1 'un indirizzo interno'
caso 'R9 un dominio interno' README.md "La board è su agents.${interno}." 1 'un indirizzo interno'
caso 'R9 il loopback' README.md "In locale risponde 127.0.0.1." 0

# Una voce che si apre e non si legge (un sottomodulo, che sul disco è una cartella): la guardia esce 2.
dir=$(repo) || exit 2
mkdir "$dir/sotto" && git -C "$dir/sotto" init -q \
    && git -C "$dir/sotto" -c user.name=prova -c user.email=prova@example.com commit -q --allow-empty -m prova \
    && git -C "$dir" add sotto 2>/dev/null || exit 2
controlla "$dir" 'R10 un sottomodulo che si apre e non si legge' 2 'non ha letto tutto'

echo "${casi} casi, ${falliti} non tornano"
[ "$falliti" = 0 ]
