#!/bin/sh
#
# Export iMessage history to files.
# Based on https://github.com/PeterKaminski09/baskup, which was
# based on https://github.com/kyro38/MiscStuff/blob/master/OSXStuff/iMessageBackup.sh
#
# Usage:
# $ imessage-exporter.sh -o /path/to/output/directory

# Save files in the current directory by default.
OUTPUT_DIR=`pwd`

# @see http://stackoverflow.com/a/14203146
while [[ $# > 1 ]]; do
	key="$1"

	case $key in
		-o|--output-directory)
		OUTPUT_DIR="$2"
		shift # past argument
		;;
		*)
			# unknown option
		;;
	esac
	shift # past argument or value
done

# Ensure a trailing slash on OUTPUT_DIR
OUTPUT_DIR=$(echo "$OUTPUT_DIR" | sed 's/\/$//g')
OUTPUT_DIR="$OUTPUT_DIR/"

# Create the output directory if it doesn't exist.
if [[ ! -e "$OUTPUT_DIR" ]]; then
	mkdir "$OUTPUT_DIR"
fi

sqlite3 ~/Library/Messages/chat.db "select guid from chat" | while read line; do
	cd "$OUTPUT_DIR"

	arrIN=(${line//;/ })
	contactNumber="${arrIN[2]}"

	# Make a directory for this contact if it doesn't exist.
	if [[ ! -e "${OUTPUT_DIR}${contactNumber}" ]]; then
		mkdir "${OUTPUT_DIR}${contactNumber}"
	fi

	# Save a log of messages.
	sqlite3 ~/Library/Messages/chat.db "select
		is_from_me,text, datetime(date + strftime('%s', '2001-01-01 00:00:00'), 'unixepoch', 'localtime') as date from message where handle_id=(
		select handle_id from chat_handle_join where chat_id=(select ROWID from chat where guid='$line')
	)" | sed 's/1\|/Me: /g;s/0\|/Friend: /g' > "${OUTPUT_DIR}${contactNumber}/${line}.txt"

	# Create a directory for attachments.
	if [[ ! -e "${OUTPUT_DIR}${contactNumber}/Attachments" ]]; then
		mkdir "${OUTPUT_DIR}${contactNumber}/Attachments"
	fi

	# Save attachments.
	sqlite3 ~/Library/Messages/chat.db "
		select filename from attachment where rowid in (
		select attachment_id from message_attachment_join where message_id in (
		select rowid from message where cache_has_attachments=1 and handle_id=(
		select handle_id from chat_handle_join where chat_id=(
		select ROWID from chat where guid='$line')
	)))" | cut -c 2- | awk -v home="$HOME" '{print home $0}' | tr '\n' '\0' | xargs -0 -t -I fname cp fname "${OUTPUT_DIR}${contactNumber}/Attachments/"

	# Delete the Attachments directory if it's empty.
	find . -type d -empty -name "${OUTPUT_DIR}${contactNumber}/Attachments" -delete
done