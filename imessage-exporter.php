#!/usr/bin/env php
<?php

# Export iMessage history to files.
# Based on https://github.com/PeterKaminski09/baskup, which was
# based on https://github.com/kyro38/MiscStuff/blob/master/OSXStuff/iMessageBackup.sh
#
# Usage:
# $ imessage-exporter.php [-o|--output_directory output_directory]
#                         The path to the directory where the messages should be saved. Save files in the current directory by default.
#                         [-f|--flush]
#                         Flushes the existing backup DB.
#                         [-r|--rebuild]
#                         Rebuild the HTML files from the existing DB.


$options = getopt( "o:fhr", array( "output_directory:", "flush", "help", "rebuild" ) );

if ( isset( $options['h'] ) || isset( $options['help'] ) ) {
	die( "Usage: imessage-exporter.php [-o|--output_directory /path/to/output/direcotry] [-f|--flush] [-r|--rebuild]\n" );
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

$database_file = $options['o'] . 'imessage-exporter.db';

if ( ! isset( $options['r'] ) ) {
	if ( isset( $options['f'] ) && file_exists( $database_file ) ) {
		unlink( $database_file );
	}
}

$temporary_db = $database_file;
$temp_db = new SQLite3( $temporary_db );
$temp_db->exec( "CREATE TABLE IF NOT EXISTS messages ( message_id INTEGER PRIMARY KEY, is_attachment INT, attachment_mime_type TEXT, contact TEXT, is_from_me INT, timestamp TEXT, content TEXT, UNIQUE (contact, timestamp) ON CONFLICT REPLACE )" );
$temp_db->exec( "CREATE INDEX IF NOT EXISTS contact_index ON messages (contact)" );
$temp_db->exec( "CREATE INDEX IF NOT EXISTS timestamp_index ON messages (timestamp)" );

if ( ! isset( $options['r'] ) ) {
	$db = new SQLite3( $_SERVER['HOME'] . "/Library/Messages/chat.db" );
	$chats = $db->query( "SELECT * FROM chat" );

	while ( $row = $chats->fetchArray( SQLITE3_ASSOC ) ) {
		$guid = $row['guid'];

		$contactNumber = array_pop( explode( ';', $guid ) );

		$statement = $db->prepare(
			"SELECT
				is_from_me,
				datetime(date + strftime('%s', '2001-01-01 00:00:00'), 'unixepoch', 'localtime') as date,
				text
			FROM message
			WHERE handle_id=(SELECT handle_id FROM chat_handle_join WHERE chat_id=:rowid)" );
		$statement->bindValue( ':rowid', $row['ROWID'] );
	
		$messages = $statement->execute();
	
		while ( $message = $messages->fetchArray( SQLITE3_ASSOC ) ) {
			if ( empty( trim( $message['text'] ) ) ) {
				continue;
			}
		
			$insert_statement = $temp_db->prepare( "INSERT INTO messages (contact, is_from_me, timestamp, content) VALUES (:contact, :is_from_me, :timestamp, :content)" );
			$insert_statement->bindValue( ':contact', $contactNumber );
			$insert_statement->bindValue( ':is_from_me', $message['is_from_me'] );
			$insert_statement->bindValue( ':timestamp', $message['date'] );
			$insert_statement->bindValue( ':content', $message['text'] );
			$insert_statement->execute();
		}
	
		$statement = $db->prepare(
			"SELECT
				filename,
				datetime(created_date + strftime('%s', '2001-01-01 00:00:00'), 'unixepoch', 'localtime') AS date,
				is_outgoing,
				mime_type
			FROM attachment
			WHERE rowid IN (
				SELECT attachment_id FROM message_attachment_join where message_id in (
					SELECT rowid FROM message where cache_has_attachments=1 and handle_id=(
						SELECT handle_id FROM chat_handle_join where chat_id=(
							SELECT ROWID FROM chat where guid=:guid
						)
					)
				)
			)" );
		$statement->bindValue( ':guid', $guid );
	
		$attachments = $statement->execute();
	
		while ( $attachment = $attachments->fetchArray( SQLITE3_ASSOC ) ) {
			$insert_statement = $temp_db->prepare( "INSERT INTO messages (contact, is_attachment, is_from_me, timestamp, content, attachment_mime_type) VALUES (:contact, :is_attachment, :is_from_me, :timestamp, :content, :attachment_mime_type)" );
			$insert_statement->bindValue( ':contact', $contactNumber );
			$insert_statement->bindValue( ':is_from_me', $attachment['is_outgoing'] );
			$insert_statement->bindValue( ':timestamp', $attachment['date'] );
			$insert_statement->bindValue( ':is_attachment', 1 );
			$insert_statement->bindValue( ':attachment_mime_type', $attachment['mime_type'] );
			$insert_statement->bindValue( ':content', $attachment['filename'] );
			$insert_statement->execute();
		}
	}
}

$contacts = $temp_db->query( "SELECT contact FROM messages GROUP BY contact ORDER BY contact ASC" );

while ( $row = $contacts->fetchArray() ) {
	$contact = $row['contact'];
	
	if ( ! file_exists( $options['o'] . $contact . ".html" ) ) {
		touch( $options['o'] . $contact . '.html' );
	}

	$messages_statement = $temp_db->prepare( "SELECT * FROM messages WHERE contact=:contact ORDER BY timestamp ASC" );
	$messages_statement->bindValue( ':contact', $contact );
	$messages = $messages_statement->execute();
	
	file_put_contents(
		$options['o'] . $contact . '.html',
		'<!doctype html>
<html>
	<head>
		<meta charset="UTF-8">
		<title>Conversation with ' . $contact . '</title>
		<style type="text/css">
		
		body { font-family: "Helvetica Neue", sans-serif; font-size: 10pt; }
		p { margin: 0; clear: both; }
		.timestamp { text-align: center; color: #8e8e93; font-variant: small-caps; font-weight: bold; font-size: 9pt; }
		img { max-width: 75%; }
		.message { text-align: left; color: black; border-radius: 8px; background-color: #e1e1e1; padding: 6px; display: inline-block; max-width: 75%; margin-bottom: 5px; float: left; }
		.message[data-from="self"] { text-align: right; background-color: #007aff; color: white; float: right;}
		
		</style>
	</head>
	<body>
'
	);

	$last_time = 0;

	while ( $message = $messages->fetchArray() ) {
		$this_time = strtotime( $message['timestamp'] );
		
		if ( $this_time - $last_time > ( 60 * 60 ) ) {
			file_put_contents(
				$options['o'] . $contact . '.html',
				"\t\t\t" . '<p class="timestamp" data-timestamp="' . $message['timestamp'] . '">' . date( "n/j/Y, g:i A", $this_time ) . '</p><br />' . "\n",
				FILE_APPEND
			);
		}
		
		$last_time = $this_time;

		if ( $message['is_attachment'] ) {
			if ( ! file_exists( $options['o'] . $contact . '/' ) ) {
				mkdir( $options['o'] . $contact . '/' );
			}
			
			$attachment_filename = basename( $message['content'] );
			list( $extension, $filename_base ) = array_map( 'strrev', explode( '.', strrev( basename( $message['content'] ) ), 2 ) );

			$suffix = 1;
			
			while ( file_exists( $options['o'] . $contact . '/' . $attachment_filename ) ) {
				++$suffix;
			
				$attachment_filename = $filename_base . '-' . $suffix . '.' . $extension;
			}
			
			copy( preg_replace( '/^~/', $_SERVER['HOME'], $message['content'] ), $options['o'] . $contact . '/' . $attachment_filename );

			$html_embed = '';

			if ( strpos( $message['attachment_mime_type'], 'image' ) === 0 ) {
				$html_embed = '<img src="' . $contact . '/' . $attachment_filename . '" />';
			}
			else {
				if ( strpos( $message['attachment_mime_type'], 'video' ) === 0 ) {
					$html_embed = '<video controls><source src="' . $contact . '/' . $attachment_filename . '" type="' . $message['attachment_mime_type'] . '"></video><br />';
				}
				else if ( strpos( $message['attachment_mime_type'], 'audio' ) === 0 ) {
					$html_embed = '<audio controls><source src="' . $contact . '/' . $attachment_filename . '" type="' . $message['attachment_mime_type'] . '"></audio><br />';
				}

				$html_embed .= '<a href="' . $contact . '/' . $attachment_filename . '">' . htmlspecialchars( $attachment_filename ) . '</a>';
			}
			
			file_put_contents(
				$options['o'] . $contact . '.html',
				"\t\t\t" . '<p class="message" data-from="' . ( $message['is_from_me'] ? 'self' : $contact ) . '" data-timestamp="' . $message['timestamp'] . '">' . $html_embed . '</p>',
				FILE_APPEND
			);
		}
		else {
			file_put_contents(
				$options['o'] . $contact . '.html',
				"\t\t\t" . '<p class="message" data-from="' . ( $message['is_from_me'] ? 'self' : $contact ) . '" data-timestamp="' . $message['timestamp'] . '">' . htmlspecialchars( trim( $message['content'] ) ) . '</p>',
				FILE_APPEND
			);
		}
		
		file_put_contents(
			$options['o'] . $contact . '.html',
			"<br />\n",
			FILE_APPEND
		);
	}
	
	file_put_contents( $options['o'] . $contact . '.html', "\t</body>\n</html>", FILE_APPEND );
}