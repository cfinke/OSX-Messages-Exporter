#!/bin/bash
#
# Tests for messages-exporter.php.
#
# Usage: tests/run-tests.sh [/path/to/messages-exporter.php] [php-binary]
#
# Each test exports a small, made-up Messages database into a temporary directory,
# so no real Messages database, Address Book or export directory is ever read or
# modified. Requires macOS (for stat -f) and the sqlite3 command-line tool.
#
# The tests run in a new directory in $TMPDIR (or /tmp), so to run them on another
# volume, set TMPDIR to a directory on it.

SCRIPT="${1:-$(cd "$(dirname "$0")/.." && pwd)/messages-exporter.php}"
PHP="${2:-php}"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/messages-exporter-tests.XXXXXX")" || exit 1
trap 'rm -rf "$WORK"' EXIT

# The exporter looks up contact names in the Address Book under $HOME, so give it an
# empty home directory.
export HOME="$WORK/home"
mkdir -p "$HOME"

# Keep the exporter's temporary files where the tests can check that none are left.
export TMPDIR="$WORK/tmp"
mkdir -p "$TMPDIR"

FAILURES=0

pass() { echo "  PASS: $1"; }
fail() { echo "  FAIL: $1"; FAILURES=$((FAILURES + 1)); }

# List the exported HTML files and their modification times. %Fm includes fractions
# of a second, so a file that is rewritten within the same second still shows up.
snapshot() {
	find "$1" -maxdepth 1 -type f -name '*.html' -exec stat -f '%Fm %N' {} + | sort
}

# The modification times of an output directory itself and of its backup database.
snapshot_backup() {
	stat -f '%Fm %N' "$1" "$1/messages-exporter.db"
}

# The number of HTML files that were created or rewritten between two snapshots.
count_changed() {
	echo "$1" > "$WORK/snapshot"
	echo "$2" | grep -vxF -f "$WORK/snapshot" | grep -c .
}

# sqlite3 reads ~/.sqliterc from the real home directory whatever $HOME says, so
# tell it not to.
sql() {
	sqlite3 -init /dev/null "$@"
}

apple_ns() {
	# Messages stores dates as nanoseconds since 2001-01-01.
	echo "(strftime('%s','$1') - 978307200) * 1000000000"
}

long_handle() {
	# An email address that is $2 bytes long, made of the letter $1.
	echo "$(printf "%$(( $2 - 12 ))s" '' | tr ' ' "$1")@example.com"
}

# The title of a group chat with these five participants is 250 bytes long, so its
# HTML file has a 255-byte name: the longest filename that macOS allows.
LONG_TITLE="$(long_handle a 48), $(long_handle b 48), $(long_handle c 48), $(long_handle d 49), $(long_handle e 49)"

build_fixture() {
	# A minimal Messages database with a one-to-one chat and two group chats.
	local db="$1"
	rm -f "$db"

	sql "$db" <<-'SQL'
		CREATE TABLE handle (ROWID INTEGER PRIMARY KEY, id TEXT, country TEXT, service TEXT, uncanonicalized_id TEXT);
		CREATE TABLE chat (ROWID INTEGER PRIMARY KEY, guid TEXT, style INTEGER, state INTEGER, chat_identifier TEXT, service_name TEXT, room_name TEXT, display_name TEXT);
		CREATE TABLE message (ROWID INTEGER PRIMARY KEY, guid TEXT, text TEXT, attributedBody BLOB, handle_id INTEGER, service TEXT, date INTEGER, is_from_me INTEGER, cache_has_attachments INTEGER, balloon_bundle_id TEXT);
		CREATE TABLE attachment (ROWID INTEGER PRIMARY KEY, guid TEXT, filename TEXT, mime_type TEXT, transfer_name TEXT);
		CREATE TABLE chat_handle_join (chat_id INTEGER, handle_id INTEGER);
		CREATE TABLE chat_message_join (chat_id INTEGER, message_id INTEGER, message_date INTEGER);
		CREATE TABLE message_attachment_join (message_id INTEGER, attachment_id INTEGER);
	SQL

	sql "$db" <<-SQL
		INSERT INTO handle (ROWID, id, service) VALUES
			(1, '+15550000001', 'iMessage'),
			(2, '+15550000002', 'iMessage'),
			(3, '$(long_handle a 48)', 'iMessage'),
			(4, '$(long_handle b 48)', 'iMessage'),
			(5, '$(long_handle c 48)', 'iMessage'),
			(6, '$(long_handle d 49)', 'iMessage'),
			(7, '$(long_handle e 49)', 'iMessage');

		INSERT INTO chat (ROWID, guid, style, chat_identifier, service_name) VALUES
			(1, 'iMessage;-;+15550000001', 45, '+15550000001', 'iMessage'),
			(2, 'iMessage;+;chat0001',     43, 'chat0001',     'iMessage'),
			(3, 'iMessage;+;chat0002',     43, 'chat0002',     'iMessage');

		INSERT INTO message (ROWID, guid, text, handle_id, service, date, is_from_me, cache_has_attachments) VALUES
			(1, 'FIX-1', 'First message in the one-to-one chat.',  1, 'iMessage', $(apple_ns '2020-03-01 09:00:00'), 0, 0),
			(2, 'FIX-2', 'A reply from me.',                       1, 'iMessage', $(apple_ns '2020-03-01 09:01:00'), 1, 0),
			(3, 'FIX-3', 'Group message from the first handle.',   1, 'iMessage', $(apple_ns '2020-04-05 12:00:00'), 0, 0),
			(4, 'FIX-4', 'Group message from the second handle.',  2, 'iMessage', $(apple_ns '2020-04-05 12:30:00'), 0, 0),
			(6, 'FIX-6', 'Message in the chat with a long title.', 3, 'iMessage', $(apple_ns '2020-05-01 08:00:00'), 0, 0),
			-- handle_id 0 has no matching handle row, so this message's contact is null.
			(10, 'FIX-10', 'Sent message with no handle row.',     0, 'iMessage', $(apple_ns '2020-03-01 09:02:00'), 1, 0);

		INSERT INTO chat_handle_join (chat_id, handle_id) VALUES
			(1, 1), (2, 1), (2, 2), (3, 3), (3, 4), (3, 5), (3, 6), (3, 7);

		INSERT INTO chat_message_join (chat_id, message_id, message_date) VALUES
			(1, 1, $(apple_ns '2020-03-01 09:00:00')),
			(1, 2, $(apple_ns '2020-03-01 09:01:00')),
			(1, 10, $(apple_ns '2020-03-01 09:02:00')),
			(2, 3, $(apple_ns '2020-04-05 12:00:00')),
			(2, 4, $(apple_ns '2020-04-05 12:30:00')),
			(3, 6, $(apple_ns '2020-05-01 08:00:00'));
	SQL
}

# Add message $1 to chat $2, from handle $3, with the text $4, sent at $5.
add_message() {
	sql "$DB" "
		INSERT INTO message (ROWID, guid, text, handle_id, service, date, is_from_me, cache_has_attachments)
		VALUES ($1, 'FIX-$1', '$4', $3, 'iMessage', $(apple_ns "$5"), 0, 0);
		INSERT INTO chat_message_join (chat_id, message_id, message_date)
		VALUES ($2, $1, $(apple_ns "$5"));
	"
}

# Run the exporter, making sure that PHP prints any warnings (once), whatever
# php.ini says, and that it uses $TMPDIR for its temporary files.
php_export() {
	"$PHP" -d display_errors=stderr -d log_errors=0 -d error_reporting=-1 -d sys_temp_dir="$TMPDIR" "$SCRIPT" "$@"
}

# Export database $2 into directory $1. Using -d also means that the exporter
# doesn't look for attachments, which these tests don't need.
run_export() {
	php_export -o "$1" -d "$2"
}

echo "Testing: $SCRIPT"
echo "PHP:     $("$PHP" --version 2>&1 | head -1)"
echo

OUT="$WORK/out"
DB="$WORK/chat.db"
mkdir -p "$OUT"
build_fixture "$DB"

ONE_TO_ONE_FILE="$OUT/+15550000001.html"
GROUP_FILE="$OUT/+15550000001, +15550000002.html"

# ---------------------------------------------------------------------------
echo "TEST 1: every conversation is exported, including one with a 255-byte filename"
run_export "$OUT" "$DB" > "$WORK/run1.log" 2>&1
EXIT=$?
COUNT="$(snapshot "$OUT" | grep -c .)"

if [ "$EXIT" -eq 0 ] && [ "$COUNT" -eq 3 ] && [ -f "$OUT/$LONG_TITLE.html" ] && grep -q 'Sent message with no handle row.' "$ONE_TO_ONE_FILE" && grep -q '^Read 6 message(s)' "$WORK/run1.log"; then
	pass "6 messages read and 3 HTML files exported"
else
	fail "expected exit status 0, \"Read 6 message(s)\" and 3 HTML files, including the long title and the message with no handle; got status $EXIT and $COUNT file(s)"
	head -20 "$WORK/run1.log"
fi

# ---------------------------------------------------------------------------
echo "TEST 2: a re-run with no new messages leaves every HTML file and the backup database untouched"
BEFORE="$(snapshot "$OUT")"
BACKUP_BEFORE="$(snapshot_backup "$OUT")"
sleep 1.1
run_export "$OUT" "$DB" > "$WORK/run2.log" 2>&1
EXIT=$?
AFTER="$(snapshot "$OUT")"
BACKUP_AFTER="$(snapshot_backup "$OUT")"

if [ "$EXIT" -eq 0 ] && [ "$BEFORE" = "$AFTER" ] && [ "$BACKUP_BEFORE" = "$BACKUP_AFTER" ] && grep -q '^0 HTML file(s) updated, 3 unchanged' "$WORK/run2.log"; then
	pass "3 HTML files and the backup database untouched"
else
	fail "expected exit status 0 and nothing rewritten; got status $EXIT, $(count_changed "$BEFORE" "$AFTER") rewritten HTML file(s), and the backup database $([ "$BACKUP_BEFORE" = "$BACKUP_AFTER" ] && echo untouched || echo changed)"
fi

# ---------------------------------------------------------------------------
echo "TEST 3: a new message rewrites only its conversation, which keeps its permissions"
add_message 5 1 1 'A brand new message arrives.' '2020-03-02 10:00:00'
chmod 600 "$ONE_TO_ONE_FILE"
BEFORE="$(snapshot "$OUT")"
sleep 1.1
run_export "$OUT" "$DB" > "$WORK/run3.log" 2>&1
EXIT=$?
AFTER="$(snapshot "$OUT")"
CHANGED="$(count_changed "$BEFORE" "$AFTER")"
MODE="$(stat -f '%Lp' "$ONE_TO_ONE_FILE")"

if [ "$EXIT" -eq 0 ] && [ "$CHANGED" -eq 1 ] && [ "$MODE" = 600 ] && grep -q 'A brand new message arrives.' "$ONE_TO_ONE_FILE"; then
	pass "only the one-to-one conversation was rewritten"
else
	fail "expected exit status 0 and only the one-to-one conversation to be rewritten, keeping mode 600; got status $EXIT, $CHANGED rewritten file(s) and mode $MODE"
fi

# ---------------------------------------------------------------------------
echo "TEST 4: an exported file whose contents differ is rewritten, even if its size is the same"
perl -pi -e 's/First message/First massage/' "$ONE_TO_ONE_FILE"
grep -q 'First massage' "$ONE_TO_ONE_FILE"
EDITED=$?
run_export "$OUT" "$DB" > "$WORK/run4.log" 2>&1
EXIT=$?

if [ "$EDITED" -eq 0 ] && [ "$EXIT" -eq 0 ] && grep -q 'First message' "$ONE_TO_ONE_FILE"; then
	pass "the edited file was rewritten"
else
	fail "expected exit status 0 and the edited file to be rewritten; got status $EXIT"
fi

# ---------------------------------------------------------------------------
echo "TEST 5: rebuilding from the backup database leaves unchanged files and the database untouched"
BEFORE="$(snapshot "$OUT")"
BACKUP_BEFORE="$(snapshot_backup "$OUT")"
sleep 1.1
php_export -o "$OUT" -r > "$WORK/run5.log" 2>&1
EXIT=$?
AFTER="$(snapshot "$OUT")"
BACKUP_AFTER="$(snapshot_backup "$OUT")"

if [ "$EXIT" -eq 0 ] && [ "$BEFORE" = "$AFTER" ] && [ "$BACKUP_BEFORE" = "$BACKUP_AFTER" ]; then
	pass "3 HTML files and the backup database untouched"
else
	fail "expected exit status 0 and nothing rewritten; got status $EXIT, $(count_changed "$BEFORE" "$AFTER") rewritten HTML file(s), and the backup database $([ "$BACKUP_BEFORE" = "$BACKUP_AFTER" ] && echo untouched || echo changed)"
fi

# ---------------------------------------------------------------------------
echo "TEST 6: if the HTML can't be written, the exported files are left alone and the export fails"
add_message 7 2 2 'A message that could not be written.' '2020-04-06 09:00:00'
BEFORE="$(snapshot "$OUT")"
sleep 1.1
# Point the exporter at a temporary directory that doesn't exist.
( TMPDIR="$WORK/missing"; run_export "$OUT" "$DB" ) > "$WORK/unwritable.txt" 2>&1
EXIT=$?
AFTER="$(snapshot "$OUT")"

if [ "$EXIT" -eq 1 ] && [ "$BEFORE" = "$AFTER" ] && grep -q '^Error: 3 HTML file(s) could not be updated' "$WORK/unwritable.txt"; then
	pass "exited with status 1 and left the 3 HTML files alone"
else
	fail "expected exit status 1 and no rewritten files; got status $EXIT and $(count_changed "$BEFORE" "$AFTER") rewritten file(s)"
fi

# ---------------------------------------------------------------------------
echo "TEST 7: an exported file that can't be overwritten is left alone, and the export fails"
chmod 444 "$GROUP_FILE"
run_export "$OUT" "$DB" > "$WORK/read-only.txt" 2>&1
EXIT=$?

if [ "$EXIT" -eq 1 ] && grep -q '^Error: 1 HTML file(s) could not be updated' "$WORK/read-only.txt" && ! grep -q 'A message that could not be written.' "$GROUP_FILE"; then
	pass "exited with status 1 and left the file alone"
else
	fail "expected exit status 1 and the read-only file to be left alone; got status $EXIT"
fi

# ---------------------------------------------------------------------------
echo "TEST 8: once the problem is fixed, the next export catches up"
chmod 644 "$GROUP_FILE"
run_export "$OUT" "$DB" > "$WORK/run8.log" 2>&1
EXIT=$?

if [ "$EXIT" -eq 0 ] && grep -q 'A message that could not be written.' "$GROUP_FILE"; then
	pass "the new message was exported"
else
	fail "expected exit status 0 and the new message to be exported; got status $EXIT"
fi

# ---------------------------------------------------------------------------
echo "TEST 9: if a conversation's HTML can only be partly written, its exported file is left alone"
PARTIAL_OUT="$WORK/partial-out"
PARTIAL_DB="$WORK/partial.db"
build_fixture "$PARTIAL_DB"
run_export "$PARTIAL_OUT" "$PARTIAL_DB" > /dev/null 2>&1
cp "$PARTIAL_OUT/+15550000001.html" "$WORK/partial-before.html"
# Add a 100 KB message to the one-to-one chat in the backup database, and make the
# group chat's exported file out of date, so that a rebuild has to update both.
sql "$PARTIAL_OUT/messages-exporter.db" "
	INSERT INTO messages (chat_title, contact, is_from_me, timestamp, content)
	VALUES ('+15550000001', '+15550000001', 0, '2020-06-01 00:00:00', replace(hex(zeroblob(50000)), '0', 'x'));
"
perl -pi -e 's/Group message/Group massage/' "$PARTIAL_OUT/+15550000001, +15550000002.html"
# Limit the size of the files that the exporter can write, so that staging the
# one-to-one chat fails partway through, as it would on a full disk. (A rebuild only
# writes to the start of the backup database.) Ignoring SIGXFSZ makes the write fail
# instead of killing PHP.
( trap '' XFSZ; ulimit -f 64; php_export -o "$PARTIAL_OUT" -r ) > /dev/null 2> "$WORK/partial.txt"
EXIT=$?

if [ "$EXIT" -eq 1 ] && cmp -s "$WORK/partial-before.html" "$PARTIAL_OUT/+15550000001.html" && grep -q 'Group message' "$PARTIAL_OUT/+15550000001, +15550000002.html" && grep -q '^Error: 1 HTML file(s) could not be updated' "$WORK/partial.txt"; then
	pass "exited with status 1, left the one-to-one chat's file alone and updated the group chat's"
else
	fail "expected exit status 1, the one-to-one chat's file to be left alone and the group chat's to be updated; got status $EXIT"
fi

# ---------------------------------------------------------------------------
echo "TEST 10: an export stopped with Ctrl+C or SIGTERM removes its staging directory and leaves the exported files alone"
INTERRUPT_OUT="$WORK/interrupt-out"
INTERRUPT_DB="$WORK/interrupt.db"
INTERRUPT_TMP="$WORK/interrupt-tmp"
mkdir -p "$INTERRUPT_TMP"
build_fixture "$INTERRUPT_DB"
run_export "$INTERRUPT_OUT" "$INTERRUPT_DB" > /dev/null 2>&1
BEFORE="$(snapshot "$INTERRUPT_OUT")"
# Add enough messages straight to the backup database that rebuilding from it takes long
# enough to interrupt. (Exporting them from a Messages database would take much longer.)
# A rebuild that isn't interrupted would rewrite the one-to-one chat's file.
sql "$INTERRUPT_OUT/messages-exporter.db" "
	WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 30000)
	INSERT INTO messages (chat_title, contact, is_from_me, timestamp, content)
	SELECT chat_title, contact, 0, datetime(timestamp, '+' || i || ' minutes'), 'Message number ' || i FROM messages, n WHERE message_id = 1;
"
RESULTS=""

for SIGNAL in INT TERM; do
	# Rebuild in the background, with SIGINT handled as it is in a terminal (background jobs
	# otherwise ignore it), and send the signal as soon as an HTML file has been staged.
	perl -e '$SIG{INT} = "DEFAULT"; exec @ARGV' "$PHP" -d display_errors=stderr -d log_errors=0 -d error_reporting=-1 -d sys_temp_dir="$INTERRUPT_TMP" "$SCRIPT" -o "$INTERRUPT_OUT" -r > /dev/null 2> "$WORK/interrupt-$SIGNAL.err" &
	PID=$!
	TRIES=0

	while [ -z "$(find "$INTERRUPT_TMP" -mindepth 2 -name '*.html')" ] && [ "$TRIES" -lt 500 ] && kill -0 "$PID" 2> /dev/null; do
		sleep 0.02
		TRIES=$((TRIES + 1))
	done

	kill -"$SIGNAL" "$PID" 2> /dev/null || RESULTS="$RESULTS (the export finished before it could be stopped)"
	# The braces keep the shell from reporting that the job was terminated.
	{ wait "$PID"; } 2> /dev/null
	STATUS=$?
	RESULTS="$RESULTS $SIGNAL:$STATUS:$(find "$INTERRUPT_TMP" -mindepth 1 | grep -c .)"
done

AFTER="$(snapshot "$INTERRUPT_OUT")"

if [ "$RESULTS" = " INT:130:0 TERM:143:0" ] && [ "$BEFORE" = "$AFTER" ]; then
	pass "stopped with statuses 130 and 143, removed the staging directory and left the exported files alone"
else
	fail "expected INT:130:0 TERM:143:0 (signal:status:leftover files) and no rewritten files; got$RESULTS and $(count_changed "$BEFORE" "$AFTER") rewritten file(s)"
fi

# ---------------------------------------------------------------------------
echo "TEST 11: a Messages database with no messages is reported as an error"
EMPTY_DB="$WORK/empty.db"
build_fixture "$EMPTY_DB"
# Like a Messages database whose messages haven't been downloaded from iCloud: the
# conversations are there, but none of their messages are. Export it on top of a copy
# of the existing backup, which is where this happens in practice.
sql "$EMPTY_DB" "DELETE FROM message; DELETE FROM chat_message_join;"
cp -R "$OUT" "$WORK/empty-out"
BEFORE="$(snapshot "$WORK/empty-out")"
sleep 1.1
run_export "$WORK/empty-out" "$EMPTY_DB" > /dev/null 2> "$WORK/empty.err"
EXIT=$?
AFTER="$(snapshot "$WORK/empty-out")"

if [ "$EXIT" -eq 1 ] && [ "$BEFORE" = "$AFTER" ] && grep -q '^Error: No messages were read' "$WORK/empty.err"; then
	pass "exited with status 1, explained why on stderr and left the exported files alone"
else
	fail "expected exit status 1, an error on stderr and no rewritten files; got status $EXIT"
fi

# ---------------------------------------------------------------------------
echo "TEST 12: a --match that matches no conversations is reported as an error"
php_export -o "$WORK/match-out" -d "$DB" --match "Nobody" > /dev/null 2> "$WORK/match.err"
EXIT=$?

if [ "$EXIT" -eq 1 ] && grep -q -- '--match' "$WORK/match.err"; then
	pass "exited with status 1 and suggested checking --match"
else
	fail "expected exit status 1 and a hint about --match; got status $EXIT"
fi

# ---------------------------------------------------------------------------
echo "TEST 13: a Messages database that doesn't exist is reported as an error"
php_export -o "$WORK/missing-out" -d "$WORK/no-such.db" > /dev/null 2> "$WORK/missing.err"
EXIT=$?

if [ "$EXIT" -eq 1 ] && grep -q 'does not exist' "$WORK/missing.err"; then
	pass "exited with status 1 and explained why on stderr"
else
	fail "expected exit status 1 and an error on stderr; got status $EXIT"
fi

# ---------------------------------------------------------------------------
echo "TEST 14: an invalid --timezone is reported as an error"
php_export -o "$WORK/timezone-out" -d "$DB" --timezone "Not/A_Zone" > /dev/null 2> "$WORK/timezone.err"
EXIT=$?

if [ "$EXIT" -eq 1 ] && grep -q 'Invalid timezone identifier' "$WORK/timezone.err"; then
	pass "exited with status 1 and explained why on stderr"
else
	fail "expected exit status 1 and an error on stderr; got status $EXIT"
fi

# ---------------------------------------------------------------------------
echo "TEST 15: no run, even one that failed, leaves files behind in the output or temporary directory"
STRAY="$(find "$OUT" -mindepth 1 -maxdepth 1 ! -name '*.html' ! -name 'messages-exporter.db'; find "$TMPDIR" -mindepth 1)"

if [ -z "$STRAY" ]; then
	pass "only the HTML files and the backup database are left"
else
	fail "found leftover files: $STRAY"
fi

# ---------------------------------------------------------------------------
echo "TEST 16: exporting prints no PHP warnings, notices or deprecations"
WARNINGS="$(cat "$WORK"/run*.log "$WORK"/*.err | grep -iE 'deprecated|warning|notice|fatal')"

if [ -z "$WARNINGS" ]; then
	pass "no warnings"
else
	fail "$(echo "$WARNINGS" | grep -c .) warning line(s), such as:"
	echo "$WARNINGS" | head -3
fi

echo
if [ "$FAILURES" -eq 0 ]; then
	echo "All tests passed."
	exit 0
else
	echo "$FAILURES test(s) failed."
	exit 1
fi
