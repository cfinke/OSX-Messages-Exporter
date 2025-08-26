#!/usr/bin/env php
<?php

error_reporting( E_ALL );

# Export Messages conversations to HTML files.
# Based on https://github.com/PeterKaminski09/baskup, which was
# based on https://github.com/kyro38/MiscStuff/blob/master/OSXStuff/iMessageBackup.sh
#
# Basic Usage (see -h output for more):
# $ messages-exporter.php [-o|--output_directory output_directory]
#                         The path to the directory where the messages should be saved. Save files in the current directory by default.
#                         [-f|--flush]
#                         Flushes the existing backup DB.
#                         [-r|--rebuild]
#                         Rebuild the HTML files from the existing DB.

define( 'VERSION', 2 );

$options = getopt(
	"o:fhrd:t:p:",
	array(
		"output_directory:",
		"flush",
		"help",
		"rebuild",
		"database:",
		"force-attachments",
		"date-start:",
		"date-stop:",
		"timezone:",
		"path-template:",
		"match:",
		"match_regex:",
	)
);

if ( isset( $options['h'] ) || isset( $options['help'] ) ) {
	echo "Usage: messages-exporter.php [-o|--output_directory /path/to/output/directory] [-f|--flush] [-r|--rebuild] [-d|--database /path/to/chat/database]\n\n"
		. "    OPTIONS:\n"
		. "\n"

		. "    [-o|--output_directory]\n"
		. "      A path to the directory where the messages should be saved. Save files in the current directory by default.\n"
		. "\n"

		. "    [-f|--flush]\n"
		. "      Flushes the existing backup database, essentially starting over from scratch.\n"
		. "\n"

		. "    [-r|--rebuild]\n"
		. "      Rebuild the HTML files from the existing database.\n"
		. "\n"

		. "    [-d|--database /path/to/chat/database]\n"
		. "      You can specify an alternate database file if, for example, you're running this script on a backup of chat.db from another machine. Note that if you use this argument, attachments will not be saved because it is presumed that they are not available on the current machine.\n"
		. "\n"

		. "    [--force-attachments]\n"
		. "      If you specify -d|--database, you can use this parameter to force the script to try and find attachments anyway.\n"
		. "\n"

		. "    [--date-start YYYY-MM-DD]\n"
		. "      Optionally, specify the first date that should be queried from the Messages database.\n"
		. "\n"

		. "    [--date-stop YYYY-MM-DD]\n"
		. "      Optionally, specify the last date that should be queried from the Messages database.\n"
		. "\n"

		. "    [-t|--timezone \"America/Los_Angeles\"]\n"
		. "      Optionally, supply a timezone to use for any dates and times that are displayed. If none is supplied, times will be in UTC. For a list of valid timezones, see https://www.php.net/manual/en/timezones.php\n"
		. "\n"

		. "    [-p|--path-template \"%Y-%m-%d - _CHAT_TITLE_\"]\n"
		. "      Optionally, supply a strftime-style format string to use for the exported chat files. **Use _CHAT_TITLE_ for the name of the chat.** For example, you can separate your chats into yearly files by using `--path-template \"%Y - _CHAT_TITLE_\"` or monthly files by using `--path-template \"%Y-%m - _CHAT_TITLE_\"`. You may also wish to use the date as a suffix so that chats from the same person are all organized together in Finder, in which case you might use `--path-template \"_CHAT_TITLE_ - %Y-%m-%d\"`"
		. "\n"

		. "    [--match \"Conversation Title\"]\n"
		. "      Limit the output to conversations that include this argument somewhere in their title. For example, to only back up chats with your friend Alex Smith, you'd specify `--match \"Alex Smith\"`."
		. "\n"

		. "    [--match_regex \"/^Conversation Title$/\"]\n"
		. "      Limit the output to conversations whose titles match this regular expression. For example, to only back up one-on-one chats with your friend Alex Smith, you'd specify `--match \"/^Alex Smith$/\"`."
		. "\n"

		. "";
	echo "\n";
	die();
}

if ( ! isset( $options['o'] ) && empty( $options['output_directory'] ) ) {
	$options['o'] = getcwd();
}
else if ( ! empty( $options['output_directory'] ) ) {
	$options['o'] = $options['output_directory'];
}

if ( ! empty( $options['database'] ) ) {
	$options['d'] = $options['database'];
}

if ( ! isset( $options['f'] ) && isset( $options['flush'] ) ) {
	$options['f'] = true;
}

if ( ! isset( $options['r'] ) && isset( $options['rebuild'] ) ) {
	$options['r'] = true;
}

if ( isset( $options['timezone'] ) ) {
	$options['t'] = $options['timezone'];
}

if ( isset( $options['o'] ) ) {
	$options['o'] = preg_replace( '/^~/', $_SERVER['HOME'], $options['o'] );
}

if ( isset( $options['d'] ) ) {
	$options['d'] = preg_replace( '/^~/', $_SERVER['HOME'], $options['d'] );
}

if ( isset( $options['path-template'] ) ) {
	$options['p'] = $options['path-template'];
}

if ( empty( $options['p'] ) ) {
	$options['p'] = '_CHAT_TITLE_';
}

if ( isset( $options['m'] ) ) {
	$options['match'] = $options['m'];
}

# Ensure a trailing slash on the output directory.
$options['o'] = rtrim( $options['o'], '/' ) . '/';

if ( ! empty( $options['t'] ) ) {
	try {
		new DateTimeZone( $options['t'] );
	} catch ( Exception $e ) {
		file_put_contents('php://stderr', "Invalid timezone identifier: " . $options['t'] . "\n" );
		die;
	}

	date_default_timezone_set( $options['t'] );

	$timezone = new DateTimeZone( $options['t'] );
	$time_right_now = new DateTime( 'now', $timezone );
	$timezone_offset = $timezone->getOffset( $time_right_now );
}
else {
	$timezone_offset = 0;
}

# Create the output directory if it doesn't exist.
if ( ! file_exists( $options['o'] ) ) {
	mkdir( $options['o'] );
}

$database_file = $options['o'] . 'messages-exporter.db';

if ( ! isset( $options['r'] ) ) {
	if ( isset( $options['f'] ) && file_exists( $database_file ) ) {
		unlink( $database_file );
	}
}

$temporary_db = $database_file;
$temp_db = new SQLite3( $temporary_db );
$temp_db->exec( "CREATE TABLE IF NOT EXISTS messages ( message_id INTEGER PRIMARY KEY, chat_title TEXT, is_attachment INT, attachment_mime_type TEXT, contact TEXT, is_from_me INT, timestamp TEXT, content TEXT, UNIQUE (chat_title, contact, timestamp, content, is_from_me) ON CONFLICT REPLACE )" );
$temp_db->exec( "CREATE INDEX IF NOT EXISTS chat_title_index ON messages (chat_title)" );
$temp_db->exec( "CREATE INDEX IF NOT EXISTS contact_index ON messages (contact)" );
$temp_db->exec( "CREATE INDEX IF NOT EXISTS timestamp_index ON messages (timestamp)" );

$temp_db->exec( "CREATE TABLE IF NOT EXISTS meta ( meta_id INTEGER PRIMARY KEY, meta_key TEXT, meta_value TEXT, UNIQUE (meta_key) ON CONFLICT REPLACE )" );

$previous_version = $temp_db->querySingle( "SELECT meta_value FROM meta WHERE meta_key='version'" );

if ( ! $previous_version ) {
	$previous_version = 1;
}

if ( $previous_version < 2 ) {
	// In version 2, we switched to timestamp-based attachment filenames. Update all existing attachments that are referenced in a message.
	$attachments_statement = $temp_db->prepare( "SELECT * FROM messages WHERE is_attachment=1" );
	$attachments = $attachments_statement->execute();

	while ( $attachment = $attachments->fetchArray() ) {
		$chat_title = $attachment['chat_title'];

		$old_attachment_filename = basename( $attachment['content'] );

		if ( ! $old_attachment_filename ) {
			continue;
		}

		$new_attachment_filename = date( 'Y-m-d H i s', strtotime( $attachment['timestamp'] ) ) . ' - ' . $old_attachment_filename;

		$chat_title_for_filesystem = get_chat_title_for_filesystem( $chat_title );
		$attachments_directory = get_attachments_directory( $chat_title_for_filesystem );

		if ( file_exists( $attachments_directory . $old_attachment_filename ) && ! file_exists( $attachments_directory . $new_attachment_filename ) ) {
			rename( $attachments_directory . $old_attachment_filename, $attachments_directory . $new_attachment_filename );
		}
	}
}

$version_statement = $temp_db->prepare( "INSERT INTO meta (meta_key, meta_value) VALUES ('version', :meta_value)" );
$version_statement->bindValue( ':meta_value', VERSION, SQLITE3_TEXT );
$version_statement->execute();

$updated_contacts_memo = array();

if ( ! isset( $options['r'] ) ) {
	$chat_db_path = $_SERVER['HOME'] . "/Library/Messages/chat.db";

	if ( isset( $options['d'] ) ) {
		$chat_db_path = $options['d'];
	}

	if ( ! file_exists( $chat_db_path ) ) {
		die( "Error: The file " . $chat_db_path . " does not exist.\n" );
	}

	$db = new SQLite3( $chat_db_path, SQLITE3_OPEN_READONLY );
	$chats = $db->query( "SELECT * FROM chat" );

	while ( $row = $chats->fetchArray( SQLITE3_ASSOC ) ) {
		$guid = $row['guid'];
		$chat_id = $row['ROWID'];
		$contactArray = explode( ';', $guid );
		$contactNumber = array_pop( $contactArray );

		$participant_identifiers = array();
		$chat_participants_statement = $db->prepare(
			"SELECT id FROM handle WHERE ROWID IN (SELECT handle_id FROM chat_handle_join WHERE chat_id=:chat_id)"
		);
		$chat_participants_statement->bindValue( ':chat_id', $chat_id );
		$chat_participants = $chat_participants_statement->execute();

		while ( $participant = $chat_participants->fetchArray( SQLITE3_ASSOC ) ) {
			$participant_identifiers[] = get_contact_nicename( $participant['id'] );
		}

		sort( $participant_identifiers );
		$chat_title = implode( ", ", $participant_identifiers );

		if ( empty( $chat_title ) ) {
			$chat_title = $contactNumber;
		}

		if ( isset( $options['match'] ) ) {
			if ( stripos( $chat_title, $options['match'] ) === false ) {
				continue;
			}
		}

		if ( isset( $options['match_regex'] ) ) {
			if ( ! preg_match( $options['match_regex'], $chat_title ) ) {
				continue;
			}
		}


		$statement = $db->prepare(
			"SELECT
				*,
				message.ROWID,
				message.is_from_me,
				message.text,
				message.attributedBody,
				handle.id as contact,
				message.cache_has_attachments,
				datetime(message.date/1000000000 + strftime('%s', '2001-01-01 00:00:00'), 'unixepoch', 'localtime') AS date_from_nanoseconds,
				datetime(message.date + strftime('%s', '2001-01-01 00:00:00'), 'unixepoch', 'localtime') date_from_seconds
			FROM message LEFT JOIN handle ON message.handle_id=handle.ROWID
			WHERE message.ROWID IN (SELECT message_id FROM chat_message_join WHERE chat_id=:rowid)" );
		$statement->bindValue( ':rowid', $row['ROWID'] );

		$messages = $statement->execute();

		while ( $message = $messages->fetchArray( SQLITE3_ASSOC ) ) {
			if ( empty( $message['text'] ) ) {
				$message['text'] = '';
			}

			if ( '' === $message['text'] && $message['attributedBody'] ) {
				if ( ! $message['text'] ) {
					// Look for NSStrings.
					$parts = explode( 'NSString', $message['attributedBody'] );

					array_shift( $parts );

					foreach ( $parts as $part ) {
						// This is probably a bad method but it works for now.
						// Ideally I could read the object stored in attributedBody with PHP and just access the data I want.

						// Find the byte 2b.
						//
						// There is either the byte 81 followed by 2 bytes and the message, or there is a single non-81 byte, then the start of the message.

						$two_b = strpos( $part, '+' );

						if ( $two_b ) {
							if ( substr( $part, $two_b + 1 , 1 ) == hex2bin( '81' ) ) {
								$message_text_and_more = substr( $part, $two_b + 4 );
							}
							else {
								$message_text_and_more = substr( $part, $two_b + 2 );
							}

							// Messages seem to be ended by the bytes 86 84
							$end_index = strpos( $message_text_and_more, hex2bin( '8684' ) );

							if ( ! $end_index ) {
							}
							else {
								$message_text = substr( $message_text_and_more, 0, $end_index );
								$message['text'] = $message_text;
								break;
							}

						}
					}
				}

				if ( ! $message['text'] ) {
					// echo "Didn't find the message in an NSString.\n";
					// var_dump( $message['attributedBody'] );
					// Look for the longest ASCII text string.
					preg_match_all( '/([ -~]+)/', $message['attributedBody'], $ascii_strings );

					$longest_string = '';

					foreach ( $ascii_strings[0] as $ascii_string ) {
						if ( strlen( $ascii_string ) > strlen( $longest_string ) ) {
							$longest_string = $ascii_string;
						}
					}

					$message['text'] = '[OSX Messages Exporter encountered an error, but this is probably the message] ' . $longest_string;
				}
			}

			if ( strpos( $chat_title, ', ' ) === false && ! isset( $updated_contacts_memo[ $message['contact'] ] ) ) {
				// Get all existing chat names for this contact ID.
				// If the contact name has changed, update it for old messages and update the folder and filenames.
				$stored_messages_statement = $temp_db->prepare( "SELECT chat_title FROM messages WHERE contact=:contact GROUP BY chat_title" );
				$stored_messages_statement->bindValue( ":contact", $message['contact'] );
				$stored_messages = $stored_messages_statement->execute();

				while ( $stored_message = $stored_messages->fetchArray( SQLITE3_ASSOC ) ) {
					if ( $stored_message['chat_title'] === $chat_title ) {
						continue;
					}

					if ( strpos( $stored_message['chat_title'], ', ' ) !== false ) {
						// Group chats are tricky. @todo
						continue;
					}

					// If the contact name has changed, update it in old stored messages.
					$update_statement = $temp_db->prepare( "UPDATE messages SET chat_title=:new_chat_title WHERE contact=:contact AND chat_title=:old_chat_title" );
					$update_statement->bindValue( ":new_chat_title", $chat_title, SQLITE3_TEXT );
					$update_statement->bindValue( ":contact", $message['contact'] );
					$update_statement->bindValue( ":old_chat_title", $stored_message['chat_title'], SQLITE3_TEXT );
					$update_statement->execute();

					// Update the folder and filenames.

					// For the HTML, we can just delete it, since it gets regenerated.
					$old_html_file = get_html_file( get_chat_title_for_filesystem( $stored_message['chat_title'] ) );

					if ( file_exists( $old_html_file ) ) {
						unlink( $old_html_file );
					}

					// For the attachments directory, we need to create the new one and move everything from the old one.
					$old_attachments_directory = get_attachments_directory( get_chat_title_for_filesystem( $stored_message['chat_title'] ) );

					if ( file_exists( $old_attachments_directory ) ) {
						$new_attachments_directory = get_attachments_directory( get_chat_title_for_filesystem( $chat_title ) );

						if ( ! file_exists( $new_attachments_directory ) ) {
							mkdir( $new_attachments_directory );
						}

						$files_in_old_directory = explode( "\n", trim( shell_exec( "find " . escapeshellarg( $old_attachments_directory ) . " -type f" ) ) );

						foreach ( $files_in_old_directory as $file_in_old_directory ) {
							shell_exec( "mv -n " . escapeshellarg( $file_in_old_directory ) . " " . escapeshellarg( $new_attachments_directory ) );
						}

						if ( empty( glob( $old_attachments_directory . "/*" ) ) ) {
							// If there were two files with the same filename, keep the old directory around.
							rmdir( $old_attachments_directory );
						}
					}
				}

				$updated_contacts_memo[ $message['contact'] ] = true;
			}

			// 0xfffc is the Object Replacement Character. Messages uses it as a placeholder for the image attachment, but we can strip it out because we process attachments separately.
			$message['text'] = trim( str_replace( '￼', '', $message['text'] ) );

			// Apple switched to storing a nanosecond value in the date field at some point.
			// Due to SQLite not being able to handle converting huge timestamp values to dates,
			// all dates would have been stored as some time on -1413-03-01, with no way to retrieve
			// the original date.
			//
			// What we can do is check if we've improperly stored the date for this message, and then
			// delete the bad record and insert a new record.  The "ON CONFLICT REPLACE" clause won't
			// do this automatically, because the timestamp is part of the unique index.
			//
			// Depending on the current environment, date_from_seconds might be right or date_from_nanoseconds might be right.
			// If date_from_seconds is right, then this DB shouldn't have been affected by the bug.
			// If date_from_nanoseconds is right, then we need to delete any records that used date_from_seconds.
			// Or, we can just delete any records that used date_from_seconds anyway, since it'll just be re-inserted in a moment.
			//
			// If dates are still being stored as seconds (and not nanoseconds), then date_from_nanoseconds will be very close to 978307200 (January 1, 2001).

			if ( strtotime( $message['date_from_nanoseconds'] ) - 978307200 < 1000 ) {
				$correct_date = $message['date_from_seconds'];
			}
			else {
				$correct_date = $message['date_from_nanoseconds'];
			}

			if ( ! empty( $options['date-start'] ) && $correct_date < $options['date-start'] . " 00:00:00" ) {
				continue;
			}

			if ( ! empty( $options['date-stop'] ) && $correct_date > $options['date-stop'] . " 23:59:59" ) {
				continue;
			}

			if ( ! empty( $message['text'] ) ) {
				if ( $correct_date != $message['date_from_seconds'] ) {
					$delete_old_date_statement = $temp_db->prepare(
						"DELETE FROM messages
						WHERE
							chat_title=:chat_title AND "
							. ( $message['is_from_me'] ? " is_from_me=1 AND " : " contact=:contact AND is_from_me=0 AND " )
							. "timestamp=:timestamp AND
							content=:content" );

					$delete_old_date_statement->bindValue( ':chat_title', $chat_title, SQLITE3_TEXT );

					if ( ! $message['is_from_me'] ) {
						$delete_old_date_statement->bindValue( ':contact', $message['contact'], SQLITE3_TEXT );
					}

					$delete_old_date_statement->bindValue( ':timestamp', $message['date_from_seconds'], SQLITE3_TEXT );
					$delete_old_date_statement->bindValue( ':content', $message['text'], SQLITE3_TEXT );
					$delete_old_date_statement->execute();
				}

				if ( ensure_unique_row( $temp_db, $chat_title, $message['contact'], $correct_date, $message['text'], $message['is_from_me'] ) ) {
					$insert_statement = $temp_db->prepare( "INSERT INTO messages (chat_title, contact, is_from_me, timestamp, content) VALUES (:chat_title, :contact, :is_from_me, :timestamp, :content)" );
					$insert_statement->bindValue( ':chat_title', $chat_title, SQLITE3_TEXT );
					$insert_statement->bindValue( ':contact', $message['contact'], SQLITE3_TEXT );
					$insert_statement->bindValue( ':is_from_me', $message['is_from_me'] );
					$insert_statement->bindValue( ':timestamp', $correct_date, SQLITE3_TEXT );
					$insert_statement->bindValue( ':content', $message['text'], SQLITE3_TEXT );
					$insert_statement->execute();
				}
			}

			// Handle any attachments.

			if ( isset( $message['balloon_bundle_id'] ) && 'com.apple.messages.URLBalloonProvider' === $message['balloon_bundle_id'] ) {
				// The attachment would just be a URL preview.
				continue;
			}

			if ( $message['cache_has_attachments'] ) {
				$attachmentStatement = $db->prepare(
					"SELECT
						attachment.filename,
						attachment.mime_type,
						*
					FROM message_attachment_join LEFT JOIN attachment ON message_attachment_join.attachment_id=attachment.ROWID
					WHERE message_attachment_join.message_id=:message_id"
				);
				$attachmentStatement->bindValue( ':message_id', $message['ROWID'] );

				$attachmentResults = $attachmentStatement->execute();

				while ( $attachmentResult = $attachmentResults->fetchArray( SQLITE3_ASSOC ) ) {
					if ( $correct_date != $message['date_from_seconds'] ) {
						// See the comment above for why we do this DELETE.
						$delete_old_date_statement = $temp_db->prepare(
							"DELETE FROM messages
							WHERE
								chat_title=:chat_title AND "
								. ( $message['is_from_me'] ? " is_from_me=1 AND " : " contact=:contact AND is_from_me=0 AND " )
								. "timestamp=:timestamp AND
								content=:content" );

						$delete_old_date_statement->bindValue( ':chat_title', $chat_title, SQLITE3_TEXT );

						if ( ! $message['is_from_me'] ) {
							$delete_old_date_statement->bindValue( ':contact', $message['contact'], SQLITE3_TEXT );
						}

						$delete_old_date_statement->bindValue( ':timestamp', $message['date_from_seconds'], SQLITE3_TEXT );
						$delete_old_date_statement->bindValue( ':content', $attachmentResult['filename'], SQLITE3_TEXT );
						$delete_old_date_statement->execute();
					}

					if ( empty( $attachmentResult['filename'] ) ) {
						// Could be something like an Apple Pay request.
						// $attachmentResult['attribution_info'] has a hint: bplist00?TnameYbundle-idiApple?Pay_vcom.apple.messages.MSMessageExtensionBalloonPlugin:0000000000:com.apple.PassbookUIService.PeerPaymentMessage...
						// @todo
					}

					if ( ! empty( $options['d'] ) && ! isset( $options['force-attachments'] ) ) {
						// If we're running on a database that is not the default system DB, the attachments are likely not available,
						// and even if there's a filename match, it may not be the correct file.  Simply note that there was an attachment
						// that is now unavailable.
						if (
							ensure_unique_row( $temp_db, $chat_title, $message['contact'], $correct_date, '[File unavailable: ' . $attachmentResult['filename'] . ']', $message['is_from_me'] )
							&& ensure_unique_row( $temp_db, $chat_title, $message['contact'], $correct_date, $attachmentResult['filename'], $message['is_from_me'] ) /* The file was maybe available at the time. */
							) {
							$insert_statement = $temp_db->prepare( "INSERT INTO messages (chat_title, contact, is_from_me, timestamp, content) VALUES (:chat_title, :contact, :is_from_me, :timestamp, :content)" );
							$insert_statement->bindValue( ':chat_title', $chat_title, SQLITE3_TEXT );
							$insert_statement->bindValue( ':contact', $message['contact'], SQLITE3_TEXT );
							$insert_statement->bindValue( ':is_from_me', $message['is_from_me'] );
							$insert_statement->bindValue( ':timestamp', $correct_date, SQLITE3_TEXT );
							$insert_statement->bindValue( ':content', '[File unavailable: ' . $attachmentResult['filename'] . ']', SQLITE3_TEXT );
							$insert_statement->execute();
						}
					}
					else {
						if ( ensure_unique_row( $temp_db, $chat_title, $message['contact'], $correct_date, $attachmentResult['filename'], $message['is_from_me'] ) ) {
							$insert_statement = $temp_db->prepare( "INSERT INTO messages (chat_title, contact, is_attachment, is_from_me, timestamp, content, attachment_mime_type) VALUES (:chat_title, :contact, 1, :is_from_me, :timestamp, :content, :attachment_mime_type)" );
							$insert_statement->bindValue( ':chat_title', $chat_title, SQLITE3_TEXT );
							$insert_statement->bindValue( ':contact', $message['contact'], SQLITE3_TEXT );
							$insert_statement->bindValue( ':is_from_me', $message['is_from_me'] );
							$insert_statement->bindValue( ':timestamp', $correct_date, SQLITE3_TEXT );
							$insert_statement->bindValue( ':attachment_mime_type', $attachmentResult['mime_type'], SQLITE3_TEXT );
							$insert_statement->bindValue( ':content', $attachmentResult['filename'], SQLITE3_TEXT );
							$insert_statement->execute();
						}
					}
				}
			}
		}
	}
}

do {
	// SQLite doesn't enforce multi-column uniqueness if one of the values is null, which unfortunately breaks how we try and enforce our unique message index. So I guess we'll just go and delete any duplicates each time this runs.
	$found_duplicates = false;

	$duplicate_messages_statement = $temp_db->prepare( "SELECT *, COUNT(*) c FROM messages GROUP BY chat_title, contact, timestamp, content, is_from_me HAVING c > 1 ORDER BY message_id DESC" );
	$duplicate_messages = $duplicate_messages_statement->execute();

	while ( $duplicate_message = $duplicate_messages->fetchArray() ) {
		$found_duplicates = true;

		$delete_statement = $temp_db->prepare( "DELETE FROM messages WHERE message_id=:message_id" );
		$delete_statement->bindValue( ':message_id', $duplicate_message['message_id'] );
		$delete_statement->execute();
	}
} while ( $found_duplicates );

$messages_statement = $temp_db->prepare( "SELECT * FROM messages GROUP BY chat_title, is_from_me, timestamp, content ORDER BY timestamp ASC" );
$messages = $messages_statement->execute();

$files_started = array();

$leftover_files_to_delete = array();

while ( $message = $messages->fetchArray() ) {
	$output = '';

	if ( empty( $message['attachment_mime_type'] ) ) {
		$message['attachment_mime_type'] = '';
	}

	$conversation_participant_count = substr_count( $message['chat_title'], "," ) + 2;

	$chat_title = str_replace(
		'_CHAT_TITLE_',
		$message['chat_title'],
		strftime_manual( $options['p'], strtotime( $message['timestamp'] ) )
	);

	$chat_title_for_filesystem = get_chat_title_for_filesystem( $chat_title );
	$html_file = get_html_file( $chat_title_for_filesystem );
	$attachments_directory = get_attachments_directory( $chat_title_for_filesystem );

	$write_mode = FILE_APPEND;

	if ( ! isset( $files_started[ $html_file ] ) ) {
		$write_mode = 0;

		$output .= '<!doctype html>
<html>
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>Conversation: ' . $chat_title . '</title>
		<style type="text/css">

		body { font-family: "Helvetica Neue", sans-serif; font-size: 10pt; }
		p { margin: 0; clear: both; }
		.t /* timestamp */ { text-align: center; color: #8e8e93; font-variant: small-caps; font-weight: bold; font-size: 9pt; }
		.b /* byline */ { text-align: left; color: #8e8e93; font-size: 9pt; padding-left: 1ex; padding-top: 1ex; margin-bottom: 2px; }
		img { max-width: 100%; }
		.m /* message */ { text-align: left; color: black; border-radius: 8px; background-color: #e1e1e1; padding: 6px; display: inline-block; max-width: 75%; margin-bottom: 5px; float: left; }
		.m.s /* self */ { text-align: right; background-color: #007aff; color: white; float: right;}

		</style>
	</head>
	<body>
	';

		$files_started[ $html_file ]['last_time'] = 0;
		$files_started[ $html_file ]['last_participant'] = null;
	}

	$this_time = strtotime( $message['timestamp'] );

	if ( $this_time < 0 ) {
		// There was a bug present from when Apple started storing timestamps as nanoseconds instead of seconds, so the stored
		// timestamps were all from the year -1413. There's no way to fix it without re-importing the messages. Sorry.
		$this_time = 0;
		$message['timestamp'] = "Unknown Date";
	}

	if ( $this_time - $files_started[ $html_file ]['last_time'] > ( 60 * 60 ) ) {
		$files_started[ $html_file ]['last_participant'] = null;

		$output .= "\t\t\t" . '<p class="t">' . date( "n/j/Y, g:i A", $this_time + $timezone_offset ) . '</p><br />' . "\n";
	}

	$files_started[ $html_file ]['last_time'] = $this_time;

	if ( $conversation_participant_count > 2 && ! $message['is_from_me'] && $message['contact'] != $files_started[ $html_file ]['last_participant'] ) {
		$files_started[ $html_file ]['last_participant'] = $message['contact'];

		$output .= "\t\t\t" . '<p class="b">' . htmlspecialchars( get_contact_nicename( $message['contact'] ) ) .'</p>' . "\n";
	}

	if ( $message['is_attachment'] ) {
		if ( ! file_exists( $attachments_directory ) ) {
			mkdir( $attachments_directory );
		}

		if ( empty( $message['content'] ) ) {
			$html_embed = '[Unknown Message]';
		}
		else {
			// Give the attachment filename a date-based prefix to avoid filename collisions if this backup is ever migrated to another machine.
			$attachment_filename = date( 'Y-m-d H i s', strtotime( $message['timestamp'] ) ) . ' - ' . basename( $message['content'] );

			$file_to_copy = preg_replace( '/^~/', $_SERVER['HOME'], $message['content'] );

			if ( is_dir( $file_to_copy ) ) {
				$attachment_filename .= ".zip";
			}

			// If the file is no longer available and we didn't previously save it, show "File Not Found".
			if ( ! file_exists( $file_to_copy ) && ! file_exists( $attachments_directory . $attachment_filename ) ) {
				$html_embed = '[File Not Found: ' . $attachment_filename . ']';
			}
			else {
				if ( strpos( $message['content'], '.' ) !== false ) {
					list( $extension, $filename_base ) = array_map( 'strrev', explode( '.', strrev( basename( $message['content'] ) ), 2 ) );
				}
				else {
					$extension = null;
					$filename_base = basename( $message['content'] );
				}

				$copy_file = true;

				if ( ! file_exists( $file_to_copy ) && file_exists( $attachments_directory . $attachment_filename ) ) {
					// We previously saved the attachment but it's no longer available.
					$copy_file = false;
				} else if ( is_dir( $file_to_copy ) ) {
					// @noop
				} else {
					$suffix = 1;

					// If a file already exists where we want to save this attachment, add a suffix like -2, -3, etc. until we get a unique filename.
					// But don't copy the file if the destination file is the same as the one we're copying.
					while ( file_exists( $attachments_directory . $attachment_filename ) ) {
						if (
							sha1_file( $attachments_directory . $attachment_filename ) == sha1_file( $file_to_copy )
							&& filesize( $attachments_directory . $attachment_filename ) == filesize( $file_to_copy )
						) {
							$copy_file = false;

							// Now, clean up after ourselves, because previously, we would copy a new copy of this file
							// every time the exporter ran, creating foo-2.jpg, foo-3.jpg, foo-4.jpg, [...], foo-3424.jpg
							// Check the rest of the sequence, and if the filesize and sha1 match, delete those extra copies.

							$leftover_files = array();
							$leftover_filename = $attachment_filename;

							do {
								++$suffix;

								$leftover_filename = $filename_base . '-' . $suffix;

								if ( $extension ) {
									$leftover_filename .= '.' . $extension;
								}

								if ( file_exists( $attachments_directory . $leftover_filename ) ) {
									if (
										sha1_file( $attachments_directory . $leftover_filename ) == sha1_file( $file_to_copy )
										&& filesize( $attachments_directory . $leftover_filename ) == filesize( $file_to_copy )
									) {
										$leftover_files[] = $attachments_directory . $leftover_filename;
									}
								}
							} while ( file_exists( $attachments_directory . $leftover_filename ) );

							if ( ! empty( $leftover_files ) ) {
								$leftover_files_to_delete = array_merge( $leftover_files_to_delete, $leftover_files );
							}

							break;
						}

						++$suffix;

						$attachment_filename = $filename_base . '-' . $suffix;

						if ( $extension ) {
							$attachment_filename .= '.' . $extension;
						}
					}
				}

				if ( $copy_file ) {
					if ( is_dir( $file_to_copy ) ) {
						// Zip the directory and copy it.

						// echo( $file_to_copy . " is a directory, not a file. Found in message body " . $message['content'] . "\n" );

						// Change to the directory above the directory we want to zip so that the full path isn't stored in the zip.
						$cwd = getcwd();
						chdir( $file_to_copy );
						chdir( ".." );

						$folder_name = basename( $file_to_copy );
						$tmp_zip_path = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . uniqid( "messages-exporter-" ) . ".zip";
						shell_exec( "zip -r " . escapeshellarg( $tmp_zip_path ) . " " . $folder_name );

						chdir( $cwd );

						copy( $tmp_zip_path, $attachments_directory . $attachment_filename );

						unlink( $tmp_zip_path );
					} else {
						copy( $file_to_copy, $attachments_directory . $attachment_filename );
					}
				}

				$html_embed = '';

				if ( strpos( $message['attachment_mime_type'], 'image' ) === 0 ) {
					$html_embed = '<img src="' . $chat_title_for_filesystem . '/' . $attachment_filename . '" />';
				}
				else {
					if ( strpos( $message['attachment_mime_type'], 'video' ) === 0 ) {
						$html_embed = '<video controls><source src="' . $chat_title_for_filesystem . '/' . $attachment_filename . '" type="' . $message['attachment_mime_type'] . '"></video><br />';
					}
					else if ( strpos( $message['attachment_mime_type'], 'audio' ) === 0 ) {
						$html_embed = '<audio controls><source src="' . $chat_title_for_filesystem . '/' . $attachment_filename . '" type="' . $message['attachment_mime_type'] . '"></audio><br />';
					}

					$html_embed .= '<a href="' . $chat_title_for_filesystem . '/' . $attachment_filename . '">' . htmlspecialchars( $attachment_filename ) . '</a>';
				}
			}
		}

		$output .= "\t\t\t" . '<p class="m' . ( $message['is_from_me'] ? ' s' : '' ) . '"  title="' . date( "n/j/Y, g:i A", $this_time + $timezone_offset ) . '">' . $html_embed . '</p>';
	}
	else {
		$output .= "\t\t\t" . '<p class="m' . ( $message['is_from_me'] ? ' s' : '' ) . '" title="' . date( "n/j/Y, g:i A", $this_time + $timezone_offset ) . '">' . nl2br( htmlspecialchars( trim( $message['content'] ) ) ) . '</p>';
	}

	$output .= "<br />\n";

	file_put_contents(
		$html_file,
		$output,
		$write_mode
	);
}

foreach ( $files_started as $html_file => $meta ) {
	file_put_contents( $html_file, "\t</body>\n</html>", FILE_APPEND );
}

foreach ( $leftover_files_to_delete as $file_to_delete ) {
	unlink( $file_to_delete );
	echo "Deleted duplicate: " . $file_to_delete . "\n";
}

if ( count( $leftover_files_to_delete ) > 0 ) {
	echo "Deleted " . count( $leftover_files_to_delete ) . " duplicate files.\n";
}

function get_contact_nicename( $contact_notnice_name ) {
	static $contact_nicename_map = array();

	if ( ! $contact_notnice_name ) {
		return $contact_notnice_name;
	}

	if ( isset( $contact_nicename_map[ $contact_notnice_name ] ) ) {
		return $contact_nicename_map[ $contact_notnice_name ];
	}

	$contact_nicename_map[ $contact_notnice_name ] = $contact_notnice_name;

	// These are SQLite files that are synced with iCloud, I think.
	$possible_address_book_db_files = glob( $_SERVER['HOME'] . "/Library/Application Support/AddressBook/Sources/*/AddressBook-v22.abcddb" );

	// But check the local contacts DB first.
	array_unshift( $possible_address_book_db_files, $_SERVER['HOME'] . "/Library/Application Support/AddressBook/AddressBook-v22.abcddb" );

	foreach ( $possible_address_book_db_files as $address_book_db_file ) {
		if ( ! file_exists( $address_book_db_file ) ) {
			echo $address_book_db_file . " does not exist.\n";
			continue;
		}

		$contacts_db = new SQLite3( $address_book_db_file, SQLITE3_OPEN_READONLY );

		if ( strpos( $contact_notnice_name, '@' ) !== false ) {
			// Assume an email address.
			$nameStatement = $contacts_db->prepare(
				"SELECT
					ZABCDRECORD.ZFIRSTNAME,
					ZABCDRECORD.ZLASTNAME
				FROM ZABCDEMAILADDRESS
					LEFT JOIN ZABCDRECORD ON ZABCDEMAILADDRESS.ZOWNER=ZABCDRECORD.Z_PK
				WHERE
					ZABCDEMAILADDRESS.ZADDRESS=:address"
			);

			$nameStatement->bindValue( ':address', $contact_notnice_name );
			$nameResults = $nameStatement->execute();

			while ( $nameResult = $nameResults->fetchArray( SQLITE3_ASSOC ) ) {
				$name = trim( $nameResult['ZFIRSTNAME'] . ' ' . $nameResult['ZLASTNAME'] );

				if ( $name ) {
					$contact_nicename_map[ $contact_notnice_name ] = $name;
					break 2;
				}
			}
		}
		else {
			// Assume a phone number.
			$forms = array();
			$forms[] = $contact_notnice_name;
			$forms[] = preg_replace( '/[^0-9]/', '', $contact_notnice_name );
			$forms[] = preg_replace( '/[^0-9]/', '', preg_replace( '/^\+1/', '', $contact_notnice_name ) );

			$forms = array_unique( $forms );

			$phoneNumberStatement = $contacts_db->prepare( "SELECT ZOWNER, ZFULLNUMBER FROM ZABCDPHONENUMBER" );
			$phoneNumberResults = $phoneNumberStatement->execute();

			while ( $phoneNumberResult = $phoneNumberResults->fetchArray( SQLITE3_ASSOC ) ) {
				if (
					in_array( $phoneNumberResult['ZFULLNUMBER'], $forms )
					|| in_array( preg_replace( '/[^0-9]/', '', $phoneNumberResult['ZFULLNUMBER'] ), $forms )
					|| in_array( preg_replace( '/^\+1/', '', preg_replace( '/[^0-9]/', '', $phoneNumberResult['ZFULLNUMBER'] ) ), $forms )
					) {
					$nameStatement = $contacts_db->prepare(
						"SELECT ZABCDRECORD.ZFIRSTNAME, ZABCDRECORD.ZLASTNAME, ZABCDRECORD.ZORGANIZATION FROM ZABCDRECORD WHERE Z_PK = :zowner"
					);
					$nameStatement->bindValue( ':zowner', $phoneNumberResult['ZOWNER'] );
					$nameResults = $nameStatement->execute();

					while ( $nameResult = $nameResults->fetchArray( SQLITE3_ASSOC ) ) {
						$name = trim( $nameResult['ZFIRSTNAME'] . ' ' . $nameResult['ZLASTNAME'] );

						if ( $nameResult['ZORGANIZATION'] ) {
							if ( ! $name ) {
								$name = $nameResult['ZORGANIZATION'];
							}
							else {
								$name .= ' (' . $nameResult['ZORGANIZATION'] . ')';
							}
						}

						if ( $name ) {
							$contact_nicename_map[ $contact_notnice_name ] = $name;
							break 3;
						}
					}
				}
			}
		}
	}

	return $contact_nicename_map[ $contact_notnice_name ];
}

function get_chat_title_for_filesystem( $chat_title ) {
	$chat_title_for_filesystem = $chat_title;

	// Mac OSX has a 255-char filename limit, so if the number of contacts in a chat
	// would push the filenames past 255 chars, truncate the filename and add an identifier
	// to ensure that another chat with the same initial list of contacts doesn't overlap
	// with it.

	// Colon and slash are prohibited in filenames on Mac.
	$chat_title_for_filesystem = str_replace( array( ":", "/" ), "-", $chat_title_for_filesystem );

	if ( strlen( $chat_title_for_filesystem . ".html" ) > 255 ) {
		$unique_chat_hash = "{" . md5( $chat_title ) . "}";

		// Shorten the filename until there's enough room for the identifying hash and a space.
		while ( strlen( $chat_title_for_filesystem . ".html" ) > 255 - 1 - strlen( $unique_chat_hash ) ) {
			$chat_title_for_filesystem = explode( " ", $chat_title_for_filesystem );
			array_pop( $chat_title_for_filesystem );
			$chat_title_for_filesystem = join( " ", $chat_title_for_filesystem );
		}

		$chat_title_for_filesystem .= " " . $unique_chat_hash;
	}

	return $chat_title_for_filesystem;
}

function get_html_file( $chat_title_for_filesystem ) {
	global $options;

	return $options['o'] . $chat_title_for_filesystem . '.html';
}

function get_attachments_directory( $chat_title_for_filesystem ) {
	global $options;

	return $options['o'] . $chat_title_for_filesystem . '/';
}

/**
 * PHP made the choice to deprecate the useful strftime function,
 * so we just have to do it ourselves. The documentation for
 * IntlDateFormatter is... not illuminating.
 *
 * @see https://stackoverflow.com/questions/22665959/using-php-strftime-using-date-format-string
 */
function strftime_manual( $format_string, $timestamp ) {
	// These don't map, so they'll stay as literals.
	// $unsupported = ['%U', '%V', '%C', '%g', '%G'];

	$strftime_to_date = array(
		['%a','%A','%d','%e','%u','%w','%W','%b','%h','%B','%m','%y','%Y', '%D',  '%F', '%x', '%n', '%t', '%H', '%k', '%I', '%l', '%M', '%p', '%P', '%r' /* %I:%M:%S %p */, '%R' /* %H:%M */, '%S', '%T' /* %H:%M:%S */, '%X', '%z', '%Z', '%c', '%s', '%%'],
		['D','l', 'd', 'j', 'N', 'w', 'W', 'M', 'M', 'F', 'm', 'y', 'Y', 'm/d/y', 'Y-m-d', 'm/d/y',"\n","\t", 'H', 'G', 'h', 'g', 'i', 'A', 'a', 'h:i:s A', 'H:i', 's', 'H:i:s', 'H:i:s', 'O', 'T', 'D M j H:i:s Y' /*Tue Feb 5 00:45:10 2009*/, 'U', '%'],
	);

	$formatted_string = $format_string;

	foreach ( $strftime_to_date[0] as $idx => $strftime_symbol ) {
		$formatted_string = str_replace( $strftime_symbol, date( $strftime_to_date[1][$idx], $timestamp ), $formatted_string );
	}

	return $formatted_string;
}

/**
 * SQLite doesn't enforce multi-column uniqueness if one of the values is null,
 * which unfortunately breaks how we try and enforce our unique message index.
 * So we'll do the check manually here.
 */
function ensure_unique_row( $temp_db, $chat_title, $contact, $timestamp, $content, $is_from_me ) {
	$query = "SELECT message_id FROM messages WHERE chat_title=:chat_title";

	if ( ! is_null( $contact ) ) {
		$query .= " AND contact=:contact ";
	} else {
		$query .= " AND contact IS NULL ";
	}

	$query .= " AND timestamp=:timestamp ";

	if ( ! is_null( $content ) ) {
		$query .= " AND content=:content ";
	} else {
		$query .= " AND content IS NULL ";
	}

	$query .= " AND is_from_me=:is_from_me LIMIT 1";

	$check_statement = $temp_db->prepare( $query );

	$check_statement->bindValue( ':chat_title', $chat_title, SQLITE3_TEXT );
	$check_statement->bindValue( ':contact', $contact, SQLITE3_TEXT );
	$check_statement->bindValue( ':timestamp', $timestamp, SQLITE3_TEXT );
	$check_statement->bindValue( ':content', $content, SQLITE3_TEXT );
	$check_statement->bindValue( ':is_from_me', $is_from_me );

	$existing_rows = $check_statement->execute();

	while ( $existing_row = $existing_rows->fetchArray() ) {
		return false;
	}

	return true;
}