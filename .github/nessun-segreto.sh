#!/usr/bin/env bash
# Nessun segreto nel repo: il pacchetto è pubblico, e un segreto qui dentro è pubblicato. Gira nella radice del repo, sui
# file che git conosce, e lo lancia la CI; esce 1 e dice dove (il nome della variabile, mai il valore), e 2 se non ha
# potuto leggere tutto. Grezzo apposta: un falso allarme si corregge scrivendo diverso, un segreto pubblicato si revoca.
# Cosa vede e cosa no: il README, «Cosa vede la guardia, e cosa no». Le sue prove: .github/prova-nessun-segreto.sh.
set -uo pipefail

trovato=0
rotto=0

# L'uscita di un controllo: 0 = niente; 1 = trovato, e lo si dice; ogni altra vuol dire che il controllo non ha letto
# tutto (un file che non si apre, perl o git che falliscono): la guardia è rossa anche così, e lo dice.
esito() {
    case "$1" in
        0) ;;
        1) echo "$2"; trovato=1 ;;
        *) echo "$2: il controllo non ha letto tutto (uscita $1)"; rotto=1 ;;
    esac
}

# L'uscita di grep e di git grep è al contrario: 0 = trovato, 1 = niente, ogni altra = non ha letto tutto.
esito_grep() {
    case "$1" in
        0) esito 1 "$2" ;;
        1) esito 0 "$2" ;;
        *) esito "$1" "$2" ;;
    esac
}

# I file che git conosce, chiesti una volta sola e dalla radice: fuori da un repo, o con git che fallisce, la guardia non
# ha niente da leggere, e non dice «niente trovato».
radice=$(git rev-parse --show-toplevel) || { echo "la guardia non è in un repo git: non ha letto niente"; exit 2; }
cd "$radice" || { echo "la radice del repo non si apre: non ha letto niente"; exit 2; }
lista=$(mktemp) || { echo "il file temporaneo non si crea: non ha letto niente"; exit 2; }
trap 'rm -f "$lista"' EXIT
git ls-files -z > "$lista" || { echo "git non elenca i file del repo: non ha letto niente"; exit 2; }

# I file che non ci devono essere: un .env (anche .env.example), una chiave, un certificato, un archivio di chiavi (.p12,
# .pfx), le credenziali di Composer (auth.json).
grep -zE '(^|/)\.env($|\.)|\.key$|\.pem$|\.p12$|\.pfx$|(^|/)auth\.json$' "$lista" | tr '\0' '\n'
esito_grep $? "file sensibile nel repo"

# Una chiave privata, in qualunque file.
git grep -lE 'BEGIN [A-Z ]*PRIVATE KEY' -- .
esito_grep $? "chiave privata nel repo"

# Il nome di una variabile segreta, in inglese o in italiano, in maiuscolo o in minuscolo.
export NOMI='(?i:[A-Z0-9_]*(?:SECRET|KEY|TOKEN|PASSWORD|PASSWD|PWD|SEGRETO|SEGRETI|CHIAVE|CHIAVI)[A-Z0-9_]*)'

# Un segnaposto, che passa solo come valore intero: vuoto, due virgolette vuote, <…>, $VAR, ${VAR}, ${{ secrets.… }},
# {{ … }}, …, ..., null, ~, anche fra virgolette. Dopo il valore la riga finisce, o viene uno spazio (e un commento), una
# virgola, un punto e virgola o una parentesi che chiude; o le virgolette, o il backtick di un codice in linea, che
# chiudono (e dopo di loro la riga finisce, o viene uno spazio o un segno). In testa a un valore (~k3y, null-k3y, ''k3y,
# `k3y`, #k3y, $k3y, {k3y}) non è un segnaposto: è il valore.
export SEGNAPOSTO='
    (?:
        (["\x27])
        (?: <[^<>\n]*> | \$[A-Z_][A-Z0-9_]* | \$\{[A-Z_][A-Z0-9_]*\} | \$\{\{[^{}\n]*\}\} | \{\{[^{}\n]*\}\}
          | … | \.\.\. | (?i:null) | ~ )?
        \g{-1}
      | <[^<>\n]*> | \$[A-Z_][A-Z0-9_]* | \$\{[A-Z_][A-Z0-9_]*\} | \$\{\{[^{}\n]*\}\} | \{\{[^{}\n]*\}\}
      | … | \.\.\. | (?i:null) | ~
      |
    )
    (?= $ | [\s,;)\]}] | ["\x27`] (?: $ | [\s,;:.)\]}] ) )'

# I controlli in perl ricevono i nomi dei file separati da NUL, e li aprono con l'open a tre argomenti: un nome con spazi
# ai lati o con < > | resta il nome di un file (con l'open a due argomenti, un nome che finisce con | è un comando). Un file
# che non si apre fa uscire 2.

# Un valore di riserva per una variabile segreta, nel PHP: env('…', valore), env('…') ?: valore, env('…') ?? valore,
# anche su più righe; lo stesso con getenv() e Env::get(); $_ENV['…'] e $_SERVER['…'] seguiti da ?: o ??.
perl -e '
    my ($trovato, $rotto, $nomi) = (0, 0, $ENV{NOMI});
    local $/ = "\0";
    while (my $file = <STDIN>) {
        chomp $file;
        next unless $file =~ /\.php$/;
        open(my $fh, "<", $file) or do { warn "non si apre: $file\n"; $rotto = 1; next };
        my $testo = do { local $/; <$fh> };
        close $fh;
        while ($testo =~ /(?:\b(?:env|getenv|Env::get)\s*\(\s*(["\x27])($nomi)\1\s*(?:,|\)\s*\?\s*[?:])|\$_(?:ENV|SERVER)\s*\[\s*(["\x27])($nomi)\3\s*\]\s*\?\s*[?:])/gi) {
            printf "%s:%d: %s\n", $file, 1 + (substr($testo, 0, $-[0]) =~ tr/\n//), $2 // $4;
            $trovato = 1;
        }
    }
    exit($rotto ? 2 : $trovato ? 1 : 0)' < "$lista"
esito $? "un segreto come valore di riserva di env()"

# Un valore per una variabile segreta nella configurazione di PHPUnit (phpunit.xml, phpunit.xml.dist, phpunit.dist.xml):
# <env name="…" value="…"/>, e così <server>, <var>, <const>, <ini>, con gli attributi in qualunque ordine e su più righe.
# Un valore vuoto passa.
perl -e '
    my ($trovato, $rotto, $nomi) = (0, 0, $ENV{NOMI});
    local $/ = "\0";
    while (my $file = <STDIN>) {
        chomp $file;
        next unless $file =~ m{(?:^|/)[^/]*phpunit[^/]*\.xml[^/]*$};
        open(my $fh, "<", $file) or do { warn "non si apre: $file\n"; $rotto = 1; next };
        my $testo = do { local $/; <$fh> };
        close $fh;
        while ($testo =~ /<(?:env|server|var|const|ini)\b([^>]*)>/gi) {
            my ($attributi, $riga) = ($1, 1 + (substr($testo, 0, $-[0]) =~ tr/\n//));
            next unless $attributi =~ /\bname\s*=\s*(["\x27])($nomi)\1/i;
            my $nome = $2;
            next unless $attributi =~ /\bvalue\s*=\s*(["\x27])(?!\1)/i;
            printf "%s:%d: %s\n", $file, $riga, $nome;
            $trovato = 1;
        }
    }
    exit($rotto ? 2 : $trovato ? 1 : 0)' < "$lista"
esito $? "un segreto nella configurazione di PHPUnit"

# Un valore per una variabile segreta in qualunque file di testo (un README, un esempio, uno script, il codice): scritto
# come in un .env o in una riga di comando, NOME=valore, senza spazi intorno all'uguale (== e => no); come in un file
# YAML, NOME: valore a inizio riga; come in un JSON, "nome": "valore"; come in un array PHP, 'nome' => 'valore'. Per JSON e
# array, solo un valore fra virgolette: una costante, una variabile, un numero non si leggono. Passano i segnaposto
# interi. Le traduzioni (resources/lang/, le copie di quelle del backoffice) non si leggono come JSON né come array: le
# loro chiavi sono i nomi dei messaggi (current_password, array_keys), e i valori sono testi. I file binari (con un NUL) no.
perl -e '
    my ($trovato, $rotto, $nomi, $segnaposto) = (0, 0, $ENV{NOMI}, $ENV{SEGNAPOSTO});
    local $/ = "\0";
    while (my $file = <STDIN>) {
        chomp $file;
        open(my $fh, "<", $file) or do { warn "non si apre: $file\n"; $rotto = 1; next };
        my $testo = do { local $/; <$fh> };
        close $fh;
        next if $testo =~ /\0/;
        # In contesto scalare: un match che fallisce, in una lista, è una lista vuota, e sposterebbe i valori dopo di lui.
        my $yaml = $file =~ /\.ya?ml$/ ? 1 : 0;
        my $traduzioni = $file =~ m{^resources/lang/} ? 1 : 0;
        my $riga = 0;
        for my $linea (split /\n/, $testo) {
            $riga++;
            my @nomi;
            push @nomi, $1 while $linea =~ /\b($nomi)=(?![=>])(?!$segnaposto)/gx;
            push @nomi, $1 if $yaml && $linea =~ /^\s*-?\s*($nomi):(?=\s|$)\s*+(?!$segnaposto)/x;
            push @nomi, $2 while ! $traduzioni && $linea =~ /(["\x27])($nomi)\g{1}\s*(?::|=>)\s*(?=["\x27])(?!$segnaposto)/gx;
            for my $nome (@nomi) {
                printf "%s:%d: %s\n", $file, $riga, $nome;
                $trovato = 1;
            }
        }
    }
    exit($rotto ? 2 : $trovato ? 1 : 0)' < "$lista"
esito $? "un segreto scritto come in un .env, in un YAML, in un JSON o in un array PHP"

if [ "$rotto" = 1 ]; then
    exit 2
fi

exit "$trovato"
