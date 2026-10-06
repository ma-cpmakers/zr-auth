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

echo "${casi} casi, ${falliti} non tornano"
[ "$falliti" = 0 ]
