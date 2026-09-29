#!/bin/bash
# Real-HTTP checks against a dev site as admin. Usage: tests/http.sh
# Config (environment): SCPO_WP = wp-cli command (default "wp"), SCPO_SITE = site URL,
# SCPO_COOKIE = file holding an admin auth cookie (see make-cookie.php), SCPO_LOG = debug.log path.
WP_CMD="${SCPO_WP:-wp}"
C=$(cat "${SCPO_COOKIE:-$(dirname "$0")/.cookie}")
B="${SCPO_SITE:-http://localhost}/wp-admin"
LOG="${SCPO_LOG:-/dev/null}"
wpe(){ $WP_CMD eval "$@" 2>/dev/null | tail -1; }
PASS=0; FAIL=0
ok(){ if [ "$2" = "1" ]; then PASS=$((PASS+1)); echo "  PASS  $1"; else FAIL=$((FAIL+1)); echo "  FAIL  $1 ${3:+-- $3}"; fi; }
get(){ curl -s -H "Cookie: $C" "$B/$1"; }
has_sorter(){ get "$1" | grep -c "scporder-sortablejs" ; }
LOGSTART=$(wc -l < "$LOG" 2>/dev/null || echo 0)

echo "== Sorter gate =="
for u in "edit.php" "edit.php?post_type=page" "edit.php?post_type=portfolio" "edit-tags.php?taxonomy=post_tag" "edit-tags.php?taxonomy=category" "edit.php?cat=0&m=0&s=" "edit.php?order=asc"; do
  n=$(has_sorter "$u"); ok "sorter loads on $u" $([ "$n" -gt 0 ] && echo 1 || echo 0) "$n"
done
TAGID=$(wpe 'echo get_terms(["taxonomy"=>"post_tag","number"=>1,"hide_empty"=>false,"fields"=>"ids"])[0];')
for u in "edit.php?post_status=draft" "edit.php?post_status=pending" "edit.php?s=a" "edit.php?order=desc" "edit.php?orderby=title&order=asc" "edit-tags.php?taxonomy=post_tag&s=a" "term.php?taxonomy=post_tag&tag_ID=$TAGID" "post-new.php" "edit.php?post_type=page&post_status=draft" "index.php" "plugins.php"; do
  n=$(has_sorter "$u"); ok "sorter NOT loaded on $u" $([ "$n" -eq 0 ] && echo 1 || echo 0) "$n"
done

echo "== Tag list pagination =="
got=$(get "edit-tags.php?taxonomy=post_tag" | grep -o 'id="tag-[0-9]*"' | grep -o '[0-9]*' | tr '\n' ',')
want=$(wpe 'global $wpdb; echo implode(",",$wpdb->get_col("SELECT t.term_id FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON t.term_id=tt.term_id WHERE taxonomy=\"post_tag\" ORDER BY term_order LIMIT 20")).",";')
ok "tags page 1 shows term_order 1..20" $([ "$got" = "$want" ] && echo 1 || echo 0) "got ${got:0:60} want ${want:0:60}"
got2=$(get "edit-tags.php?taxonomy=post_tag&paged=2" | grep -o 'id="tag-[0-9]*"' | grep -o '[0-9]*' | tr '\n' ',')
want2=$(wpe 'global $wpdb; echo implode(",",$wpdb->get_col("SELECT t.term_id FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON t.term_id=tt.term_id WHERE taxonomy=\"post_tag\" ORDER BY term_order LIMIT 20 OFFSET 20")).",";')
ok "tags page 2 shows term_order 21..40" $([ "$got2" = "$want2" ] && echo 1 || echo 0)
gotn=$(get "edit-tags.php?taxonomy=post_tag&orderby=name&order=asc" | grep -o 'id="tag-[0-9]*"' | grep -o '[0-9]*' | tr '\n' ',')
wantn=$(wpe 'global $wpdb; echo implode(",",$wpdb->get_col("SELECT t.term_id FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON t.term_id=tt.term_id WHERE taxonomy=\"post_tag\" ORDER BY name LIMIT 20")).",";')
ok "column sort by Name still wins" $([ "$gotn" = "$wantn" ] && echo 1 || echo 0) "got ${gotn:0:40} want ${wantn:0:40}"
gotc=$(get "edit-tags.php?taxonomy=category" | grep -o 'id="tag-[0-9]*"' | grep -o '[0-9]*' | head -5 | tr '\n' ',')
ok "category (hierarchical) list renders rows" $([ -n "$gotc" ] && echo 1 || echo 0)

echo "== List orders =="
gotp=$(get "edit.php" | grep -o 'id="post-[0-9]*"' | grep -o '[0-9]*' | head -20 | tr '\n' ',')
wantp=$(wpe 'global $wpdb; echo implode(",",$wpdb->get_col("SELECT ID FROM $wpdb->posts WHERE post_type=\"post\" AND post_status IN (\"publish\",\"draft\",\"future\",\"pending\",\"private\") ORDER BY menu_order LIMIT 20")).",";')
ok "Posts list is in manual order" $([ "$gotp" = "$wantp" ] && echo 1 || echo 0) "got ${gotp:0:50} want ${wantp:0:50}"
gotd=$(get "edit.php?post_status=draft" | grep -o 'id="post-[0-9]*"' | grep -o '[0-9]*' | tr '\n' ',')
wantd=$(wpe 'global $wpdb; echo implode(",",$wpdb->get_col("SELECT ID FROM $wpdb->posts WHERE post_type=\"post\" AND post_status=\"draft\" ORDER BY post_modified DESC")).",";')
ok "Drafts view keeps core's most-recently-modified-first order" $([ "$gotd" = "$wantd" ] && echo 1 || echo 0) "got $gotd want $wantd"
gotpg=$(get "edit.php?post_type=page" | grep -c 'id="post-[0-9]*"')
ok "Pages list renders (core paginates trees at 20)" $([ "$gotpg" -ge 20 ] && echo 1 || echo 0) "$gotpg rows"
col=$(get "edit.php" | grep -c 'scpo-order-input')
ok "Order column inputs render on Posts" $([ "$col" -gt 0 ] && echo 1 || echo 0) "$col"
colpg=$(get "edit.php?post_type=page" | grep -c 'scporder-order-column')
ok "Order column script not loaded on hierarchical Pages" $([ "$colpg" -eq 0 ] && echo 1 || echo 0) "$colpg"
html=$(get "edit.php")
echo "$html" | grep -q '"ajax_url":"/wp-admin/admin-ajax.php"' && r=1 || r=0
ok "localized ajax_url is root-relative" $r

echo "== Settings page =="
S=$(get "options-general.php?page=scporder-settings")
echo "$S" | grep -q 'name="scporder_options\[_scpo_form\]"' && r=1 || r=0; ok "form carries the _scpo_form marker" $r
echo "$S" | grep -q 'filter=5' && r=0 || r=1; ok "no five-star filter link" $r
echo "$S" | grep -Eq 'url: "(\\)?/wp-admin(\\)?/admin-ajax.php"' && r=1 || r=0; ok "reset uses a root-relative ajax URL" $r
echo "$S" | grep -q 'scpo_reset_types\[\]" value="nav_menu_item"' && r=0 || r=1; ok "reset list excludes nav_menu_item" $r
echo "$S" | grep -q 'Page Attributes' && r=1 || r=0; ok "reset warns about Page Attributes values" $r
echo "$S" | grep -qi 'fatal\|warning:' && r=0 || r=1; ok "settings page has no PHP errors" $r

echo "== Log =="
NEW=$(tail -n +$((LOGSTART+1)) "$LOG" | grep -i "simple-custom-post-order" | grep -v "kali-forms" )
ok "no PHP notices/warnings from the plugin in debug.log" $([ -z "$NEW" ] && echo 1 || echo 0) "$(echo "$NEW" | head -3)"

echo; echo "HTTP RESULT: $PASS passed, $FAIL failed"
