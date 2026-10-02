#!/usr/bin/env bash
# Envoie dist/multisite-radar/ (produit par « make dist ») sur le serveur de test ou de production.
# Paramètres lus dans .env (voir .env.example). Usage : bin/deploy.sh test|prod [--dry-run]
#
# Le plugin est envoyé dans un dossier caché (.multisite-radar-new, ignoré par WordPress), puis
# échangé par renommage avec le dossier en place : le site ne voit jamais un plugin à moitié copié.
set -euo pipefail
cd "$(dirname "$0")/.."

SLUG=multisite-radar
LOCAL_DIR="$PWD/dist/$SLUG"
NEW=".$SLUG-new"
OLD=".$SLUG-old"

fail() {
	echo "$*" >&2
	exit 1
}
usage() { fail "Usage : bin/deploy.sh test|prod [--dry-run]"; }

case "${1:-}" in
	test) target=test prefix=TEST_FTP_ ;;
	prod) target=prod prefix=PROD_FTP_ ;;
	*) usage ;;
esac
case "${2:-}" in
	'') dry_run='' ;;
	--dry-run) dry_run=1 ;;
	*) usage ;;
esac

[ -f .env ] || fail "Fichier .env absent : cp .env.example .env, puis le remplir."
[ -d "$LOCAL_DIR" ] || fail "$LOCAL_DIR absent : lancer « make dist » d'abord."
command -v lftp >/dev/null 2>&1 || fail "lftp est requis (apt install lftp, brew install lftp)."

# Lecture littérale de .env : ni expansion de $, ni commentaire en fin de ligne ; guillemets retirés.
protocol=ftp host='' port='' user='' password='' plugins_dir='' verify_cert=yes
while IFS= read -r line || [ -n "$line" ]; do
	line="${line%$'\r'}"
	case "$line" in "$prefix"*=*) ;; *) continue ;; esac
	key="${line%%=*}"
	value="${line#*=}"
	case "$value" in
		\"*\" | \'*\') value="${value:1:${#value}-2}" ;;
	esac
	case "${key#"$prefix"}" in
		PROTOCOL) protocol="${value:-ftp}" ;;
		HOST) host="$value" ;;
		PORT) port="$value" ;;
		USER) user="$value" ;;
		PASSWORD) password="$value" ;;
		PLUGINS_DIR) plugins_dir="${value%/}" ;;
		VERIFY_CERT) verify_cert="${value:-yes}" ;;
	esac
done <.env

for name in HOST USER PASSWORD PLUGINS_DIR; do
	var="$(printf '%s' "$name" | tr '[:upper:]' '[:lower:]')"
	[ -n "${!var}" ] || fail "${prefix}${name} est vide dans .env."
done
case "$protocol" in ftp | ftps | sftp) ;; *) fail "${prefix}PROTOCOL doit valoir ftp, ftps ou sftp." ;; esac
case "$verify_cert" in yes | no) ;; *) fail "${prefix}VERIFY_CERT doit valoir yes ou no." ;; esac
case "$plugins_dir" in
	"$SLUG" | */"$SLUG") fail "${prefix}PLUGINS_DIR désigne le dossier des extensions, sans « /$SLUG »." ;;
esac

# Chaîne entre guillemets pour une commande lftp.
q() {
	local s="${1//\\/\\\\}"
	printf '"%s"' "${s//\"/\\\"}"
}

url="$protocol://$host${port:+:$port}"
lftp_run() {
	LFTP_PASSWORD="$password" lftp -c "
		set cmd:fail-exit yes
		set net:max-retries 2
		set net:reconnect-interval-base 5
		set net:timeout 30
		set ssl:verify-certificate $verify_cert
		set sftp:auto-confirm yes
		open -u $(q "$user") --env-password $(q "$url")
		cd $(q "$plugins_dir")
		$1"
}

version="$(sed -n 's/^ \* Version: *//p' "$LOCAL_DIR/$SLUG.php")"
echo "Déploiement $target : $SLUG $version vers $host, dossier $plugins_dir/$SLUG"

if [ -n "$dry_run" ]; then
	echo "Simulation : fichiers qui seraient envoyés ou supprimés dans $SLUG/ (rien n'est modifié)."
	lftp_run "mirror -R --dry-run --delete --no-perms $(q "$LOCAL_DIR") $SLUG"
	exit 0
fi

if [ "$target" = prod ]; then
	dirty=''
	[ -z "$(git status --porcelain)" ] || dirty=' (avec des modifications non commitées)'
	echo "Commit $(git rev-parse --short HEAD)$dirty"
	answer=''
	read -r -p "Tapez « prod » pour confirmer : " answer || true
	[ "$answer" = prod ] || fail "Déploiement annulé."
fi

# Liste du dossier distant (dossiers suffixés par « / ») : vérifie la connexion et repère le plugin
# en place et les restes d'un envoi interrompu.
listing="$(lftp_run 'cls -1a' | sed 's#/$##')"
has() { printf '%s\n' "$listing" | grep -qxF "$1"; }

commands=''
has "$NEW" && commands+="rm -r $NEW"$'\n'
has "$OLD" && commands+="rm -r $OLD"$'\n'
commands+="mirror -R --no-perms $(q "$LOCAL_DIR") $NEW"$'\n'
has "$SLUG" && commands+="mv $SLUG $OLD"$'\n'
commands+="mv $NEW $SLUG"$'\n'
has "$SLUG" && commands+="rm -r $OLD"$'\n'

echo "Envoi de $(find "$LOCAL_DIR" -type f | wc -l | tr -d ' ') fichiers…"
lftp_run "$commands"
echo "Terminé : $SLUG $version est en place sur $target."
