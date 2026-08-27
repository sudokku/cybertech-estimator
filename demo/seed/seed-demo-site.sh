#!/usr/bin/env bash
# Seed the Local demo site for the Cybertech Project Estimator.
# Idempotent: safe to re-run. Uses bin/wp (Local's PHP + WP-CLI).
#
#   demo/seed/seed-demo-site.sh
#
# What it does: symlink + activate the cybertech-demo theme, set site
# name/tagline, create Home / Services / Estimate pages, set the static
# front page, build the `primary` menu, set pretty permalinks, set the site
# icon, and seed demo leads via `wp ct-estimator seed`.
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP="$REPO/bin/wp"
THEME_SRC="$REPO/demo/theme/cybertech-demo"
SITE="${LOCAL_SITE_PATH:-$HOME/Local Sites/cybertech-cta-demo/app/public}"
THEME_DST="$SITE/wp-content/themes/cybertech-demo"

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

# --- theme ------------------------------------------------------------------
log "Linking theme -> $THEME_DST"
if [ -e "$THEME_DST" ] && [ ! -L "$THEME_DST" ]; then
	echo "Refusing to replace a real directory at $THEME_DST" >&2
	exit 1
fi
ln -sfn "$THEME_SRC" "$THEME_DST"

if [ "$("$WP" theme list --status=active --field=name)" != "cybertech-demo" ]; then
	log "Activating theme"
	"$WP" theme activate cybertech-demo
else
	log "Theme already active"
fi

# --- site identity ----------------------------------------------------------
log "Site name + tagline"
"$WP" option update blogname "Cybertech" >/dev/null
"$WP" option update blogdescription "Navigating the digital ocean since 1999" >/dev/null

# --- pages ------------------------------------------------------------------
# page_id <slug> <title> <content> -> prints ID (creates when missing)
page_id() {
	local slug="$1" title="$2" content="$3" id
	id="$("$WP" post list --post_type=page --name="$slug" --post_status=publish,draft,private,pending --field=ID | head -n1)"
	if [ -z "$id" ]; then
		id="$("$WP" post create --post_type=page --post_status=publish --post_title="$title" --post_name="$slug" --post_content="$content" --porcelain)"
		log "Created page '$title' (#$id)" >&2
	else
		# make sure it is published (it may have been left as a draft)
		if [ "$("$WP" post get "$id" --field=post_status)" != "publish" ]; then
			"$WP" post update "$id" --post_status=publish >/dev/null
		fi
		log "Reusing page '$title' (#$id)" >&2
	fi
	printf '%s' "$id"
}

HOME_ID="$(page_id home "Home" "")"
SERVICES_ID="$(page_id services "Services" "")"
ESTIMATE_ID="$(page_id estimate-project "Estimate my project" "[cybertech_estimator]")"

"$WP" post meta update "$SERVICES_ID" _wp_page_template template-services.php >/dev/null

# The estimator page must contain the shortcode.
if ! "$WP" post get "$ESTIMATE_ID" --field=post_content | grep -q '\[cybertech_estimator'; then
	log "Adding [cybertech_estimator] to page #$ESTIMATE_ID"
	"$WP" post update "$ESTIMATE_ID" --post_content="[cybertech_estimator]" >/dev/null
fi

log "Static front page -> #$HOME_ID"
"$WP" option update show_on_front page >/dev/null
"$WP" option update page_on_front "$HOME_ID" >/dev/null

# --- menu -------------------------------------------------------------------
HOME_URL="$("$WP" option get home)"
if ! "$WP" menu list --fields=slug --format=csv | tail -n +2 | grep -qx primary; then
	log "Creating menu 'primary'"
	"$WP" menu create "Primary" >/dev/null
fi
# rebuild items so re-runs converge on the same list
for item in $("$WP" menu item list primary --format=ids 2>/dev/null); do
	"$WP" menu item delete "$item" >/dev/null
done
log "Adding menu items"
"$WP" menu item add-custom primary "Home"     "$HOME_URL/#home-demo"    >/dev/null
"$WP" menu item add-custom primary "Services" "$HOME_URL/#our-services" >/dev/null
"$WP" menu item add-custom primary "Clients"  "$HOME_URL/#our-clients"  >/dev/null
"$WP" menu item add-custom primary "About us" "$HOME_URL/#about-us"     >/dev/null
"$WP" menu item add-custom primary "Our team" "$HOME_URL/#team"         >/dev/null
"$WP" menu item add-custom primary "Contact"  "$HOME_URL/#contact"      >/dev/null
"$WP" menu location assign primary primary >/dev/null

# --- permalinks -------------------------------------------------------------
if [ "$("$WP" option get permalink_structure)" != "/%postname%/" ]; then
	log "Permalinks -> /%postname%/"
	"$WP" rewrite structure '/%postname%/' >/dev/null
fi
"$WP" rewrite flush >/dev/null

# --- site icon --------------------------------------------------------------
ICON_ID="$("$WP" option get site_icon 2>/dev/null || echo 0)"
if [ "${ICON_ID:-0}" = "0" ] || ! "$WP" post get "$ICON_ID" --field=ID >/dev/null 2>&1; then
	log "Importing site icon"
	ICON_ID="$("$WP" media import "$THEME_SRC/assets/img/favicon-192.png" --title="Cybertech icon" --porcelain)"
	"$WP" option update site_icon "$ICON_ID" >/dev/null
else
	log "Site icon already set (#$ICON_ID)"
fi

# --- demo leads -------------------------------------------------------------
log "Seeding demo leads"
"$WP" ct-estimator seed

log "Done. $HOME_URL/"
