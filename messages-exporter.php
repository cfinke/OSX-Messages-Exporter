#!/usr/bin/env php
<?php

# Export Messages conversations to HTML files.
# Based on https://github.com/PeterKaminski09/baskup, which was
# based on https://github.com/kyro38/MiscStuff/blob/master/OSXStuff/iMessageBackup.sh
#
# Usage:
# $ messages-exporter.php [-o|--output_directory output_directory]
#                         The path to the directory where the messages should be saved. Save files in the current directory by default.
#                         [-f|--flush]
#                         Flushes the existing backup DB.
#                         [-r|--rebuild]
#                         Rebuild the HTML files from the existing DB.

$options = getopt( "o:fhr", array( "output_directory:", "flush", "help", "rebuild" ) );

if ( isset( $options['h'] ) || isset( $options['help'] ) ) {
	echo "Usage: messages-exporter.php [-o|--output_directory /path/to/output/direcotry] [-f|--flush] [-r|--rebuild]\n"
	   . "                             output_directory: a path to the directory where the messages should be saved. Save files in the current directory by default.\n"
	   . "                             [-f|--flush]\n"
	   . "                             Flushes the existing backup database, essentially starting over from scratch.\n"
	   . "                             [-r|--rebuild]\n"
	   . "                             Rebuild the HTML files from the existing database.\n";
	echo "\n";
	die();
}

if ( ! isset( $options['o'] ) && empty( $options['output_directory'] ) ) {
	$options['o'] = getcwd();
}
else if ( ! empty( $options['output_directory'] ) ) {
	$options['o'] = $options['output_directory'];
}

if ( ! isset( $options['f'] ) && isset( $options['flush'] ) ) {
	$options['f'] = true;
}

if ( ! isset( $options['r'] ) && isset( $options['rebuild'] ) ) {
	$options['r'] = true;
}

# Ensure a trailing slash on the output directory.
$options['o'] = rtrim( $options['o'], '/' ) . '/';

# Create the output directory if it doesn't exist.
if ( ! file_exists( $options['o'] ) ) {
	mkdir( $options['o'] );
}

# Prior to Mac OS X High Sierra  (10.13), time in 'chat.db' was in seconds
# Starting with High Sierra time in 'chat.db' is in nanoseconds
# Mac OS X High Sierra started the Darwin 17 major version
# We assume that all Darwin >= 17 uses the same nanosecond convention
$time_to_seconds = 1
$darwin_release = php_uname('r')
$darwin_nanosecond_release = 17
if ( $darwin_release >= $darwin_nanosecond_release ){
	$time_to_seconds = 1E-09;
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

if ( ! isset( $options['r'] ) ) {
	$db = new SQLite3( $_SERVER['HOME'] . "/Library/Messages/chat.db" );
	$chats = $db->query( "SELECT * FROM chat" );

	while ( $row = $chats->fetchArray( SQLITE3_ASSOC ) ) {
		$guid = $row['guid'];
		$chat_id = $row['ROWID'];
		$contactNumber = array_pop( explode( ';', $guid ) );

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

		$statement = $db->prepare(
			"SELECT
				message.ROWID,
				message.is_from_me,
				datetime(message.date*time_to_seconds + strftime('%s', '2001-01-01 00:00:00'), 'unixepoch', 'localtime') as date,
				message.text,
				handle.id as contact,
				message.cache_has_attachments
			FROM message LEFT JOIN handle ON message.handle_id=handle.ROWID
			WHERE message.ROWID IN (SELECT message_id FROM chat_message_join WHERE chat_id=:rowid)" );
		$statement->bindValue( ':rowid', $row['ROWID'] );
	
		$messages = $statement->execute();
	
		while ( $message = $messages->fetchArray( SQLITE3_ASSOC ) ) {
			// 0xfffc is the Object Replacement Character. Messages uses it as a placeholder for the image attachment, but we can strip it out because we process attachments separately.
			$message['text'] = trim( str_replace( '￼', '', $message['text'] ) );

			$contact = $message['contact'];
			
			if ( ! empty( $message['text'] ) ) {
				$insert_statement = $temp_db->prepare( "INSERT INTO messages (chat_title, contact, is_from_me, timestamp, content) VALUES (:chat_title, :contact, :is_from_me, :timestamp, :content)" );
				$insert_statement->bindValue( ':chat_title', $chat_title, SQLITE3_TEXT );
				$insert_statement->bindValue( ':contact', $contact, SQLITE3_TEXT );
				$insert_statement->bindValue( ':is_from_me', $message['is_from_me'] );
				$insert_statement->bindValue( ':timestamp', $message['date'], SQLITE3_TEXT );
				$insert_statement->bindValue( ':content', $message['text'], SQLITE3_TEXT );
				$insert_statement->execute();
			}
			
			if ( $message['cache_has_attachments'] ) {
				$attachmentStatement = $db->prepare(
					"SELECT 
						attachment.filename,
						attachment.mime_type
					FROM message_attachment_join LEFT JOIN attachment ON message_attachment_join.attachment_id=attachment.ROWID
					WHERE message_attachment_join.message_id=:message_id"
				);
				$attachmentStatement->bindValue( ':message_id', $message['ROWID'] );
				
				$attachmentResults = $attachmentStatement->execute();
				
				while ( $attachmentResult = $attachmentResults->fetchArray( SQLITE3_ASSOC ) ) {
					$insert_statement = $temp_db->prepare( "INSERT INTO messages (chat_title, contact, is_attachment, is_from_me, timestamp, content, attachment_mime_type) VALUES (:chat_title, :contact, 1, :is_from_me, :timestamp, :content, :attachment_mime_type)" );
					$insert_statement->bindValue( ':chat_title', $chat_title, SQLITE3_TEXT );
					$insert_statement->bindValue( ':contact', $contact, SQLITE3_TEXT );
					$insert_statement->bindValue( ':is_from_me', $message['is_from_me'] );
					$insert_statement->bindValue( ':timestamp', $message['date'], SQLITE3_TEXT );
					$insert_statement->bindValue( ':attachment_mime_type', $attachmentResult['mime_type'], SQLITE3_TEXT );
					$insert_statement->bindValue( ':content', $attachmentResult['filename'], SQLITE3_TEXT );
					$insert_statement->execute();
				}
			}
		}
	}
}

$contacts = $temp_db->query( "SELECT chat_title FROM messages GROUP BY chat_title ORDER BY chat_title ASC" );

while ( $row = $contacts->fetchArray() ) {
	$chat_title = $row['chat_title'];
	$html_file = $options['o'] . $chat_title . '.html';
	$attachments_directory = $options['o'] . $chat_title . '/';
	
	$conversation_participant_count = substr_count( $chat_title, "," ) + 2;
	
	if ( ! file_exists( $html_file ) ) {
		touch( $html_file );
	}

	$messages_statement = $temp_db->prepare( "SELECT * FROM messages WHERE chat_title=:chat_title ORDER BY timestamp ASC" );
	$messages_statement->bindValue( ':chat_title', $chat_title, SQLITE3_TEXT );
	$messages = $messages_statement->execute();
	
	file_put_contents(
		$html_file,
		'<!doctype html>
<html>
	<head>
		<meta charset="UTF-8">
		<title>Conversation: ' . $chat_title . '</title>
		<style type="text/css">
		
		body { font-family: "Helvetica Neue", sans-serif; font-size: 10pt; }
		p { margin: 0; clear: both; }
		.timestamp { text-align: center; color: #8e8e93; font-variant: small-caps; font-weight: bold; font-size: 9pt; }
		.byline { text-align: left; color: #8e8e93; font-size: 9pt; padding-left: 1ex; padding-top: 1ex; margin-bottom: 2px; }
		img { max-width: 100%; }
		.message { text-align: left; color: black; border-radius: 8px; background-color: #e1e1e1; padding: 6px; display: inline-block; max-width: 75%; margin-bottom: 5px; float: left; }
		.message[data-from="self"] { text-align: right; background-color: #007aff; color: white; float: right;}
		
		</style>
	</head>
	<body>
' );

	$last_time = 0;
	$last_participant = null;
	
	while ( $message = $messages->fetchArray() ) {
		$this_time = strtotime( $message['timestamp'] );
		$contact_nicename = get_contact_nicename( $message['contact'] );
		
		if ( $this_time - $last_time > ( 60 * 60 ) ) {
			$last_participant = null;

			file_put_contents(
				$html_file,
				"\t\t\t" . '<p class="timestamp" data-timestamp="' . $message['timestamp'] . '">' . date( "n/j/Y, g:i A", $this_time ) . '</p><br />' . "\n",
				FILE_APPEND
			);
		}
		
		$last_time = $this_time;

		if ( $conversation_participant_count > 2 && ! $message['is_from_me'] && $message['contact'] != $last_participant ) {
			$last_participant = $message['contact'];
			
			file_put_contents(
				$html_file,
				"\t\t\t" . '<p class="byline">' . htmlspecialchars( $contact_nicename ) .'</p>' . "\n",
				FILE_APPEND
			);
		}

		if ( $message['is_attachment'] ) {
			if ( ! file_exists( $attachments_directory ) ) {
				mkdir( $attachments_directory );
			}
			
			$attachment_filename = basename( $message['content'] );
			
			if ( strpos( $message['content'], '.' ) !== false ) {
				list( $extension, $filename_base ) = array_map( 'strrev', explode( '.', strrev( basename( $message['content'] ) ), 2 ) );
			}
			else {
				$extension = null;
				$filename_base = basename( $message['content'] );
			}

			$suffix = 1;
			
			while ( file_exists( $attachments_directory . $attachment_filename ) ) {
				++$suffix;
			
				$attachment_filename = $filename_base . '-' . $suffix;
				
				if ( $extension ) {
					$attachment_filename .= '.' . $extension;
				}
			}
			
			copy( preg_replace( '/^~/', $_SERVER['HOME'], $message['content'] ), $attachments_directory . $attachment_filename );

			$html_embed = '';

			if ( strpos( $message['attachment_mime_type'], 'image' ) === 0 ) {
				$html_embed = '<img src="' . $chat_title . '/' . $attachment_filename . '" />';
			}
			else {
				if ( strpos( $message['attachment_mime_type'], 'video' ) === 0 ) {
					$html_embed = '<video controls><source src="' . $chat_title . '/' . $attachment_filename . '" type="' . $message['attachment_mime_type'] . '"></video><br />';
				}
				else if ( strpos( $message['attachment_mime_type'], 'audio' ) === 0 ) {
					$html_embed = '<audio controls><source src="' . $chat_title . '/' . $attachment_filename . '" type="' . $message['attachment_mime_type'] . '"></audio><br />';
				}

				$html_embed .= '<a href="' . $chat_title . '/' . $attachment_filename . '">' . htmlspecialchars( $attachment_filename ) . '</a>';
			}
			
			file_put_contents(
				$html_file,
				"\t\t\t" . '<p class="message" data-from="' . ( $message['is_from_me'] ? 'self' : $message['contact'] ) . '" data-timestamp="' . $message['timestamp'] . '">' . $html_embed . '</p>',
				FILE_APPEND
			);
		}
		else {
			file_put_contents(
				$html_file,
				"\t\t\t" . '<p class="message" data-from="' . ( $message['is_from_me'] ? 'self' : $message['contact'] ) . '" data-timestamp="' . $message['timestamp'] . '">' . htmlspecialchars( trim( $message['content'] ) ) . '</p>',
				FILE_APPEND
			);
		}
		
		file_put_contents(
			$html_file,
			"<br />\n",
			FILE_APPEND
		);
	}
	
	file_put_contents( $html_file, "\t</body>\n</html>", FILE_APPEND );
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
	
	$address_book_db_file = $_SERVER['HOME'] . "/Library/Application Support/AddressBook/AddressBook-v22.abcddb";
	
	if ( ! file_exists( $address_book_db_file ) ) {
		return $contact_nicename_map[ $contact_notnice_name ];
	}
	
	$contacts_db = new SQLite3( $address_book_db_file );
	
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
				break;
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
						break 2;
					}
				}
			}
		}
	}
	
	return $contact_nicename_map[ $contact_notnice_name ];
}