iMessage-Exporter
=================
Exports iMessages and SMS's to HTML files.

iMessage Exporter processes all of the existing conversations in iMessage's library, logs them to a separate backup database in your specified output directory, and then generates HTML files for each conversation, mimicking the look and feel of iMessage conversations. Attachments are saved in a separate directory for each conversation.

With the `-f` or `--flush` option, you can force iMessage Exporter to delete the existing backup database and regenerate everything. (Without this option, iMessage Exporter could maintain backups of conversations even if iMessage deletes them, accidentally or not.) 

With the `-r` or `--rebuild` option, you can regenerate the HTML files from the backup library.

Usage
=====
```
$ imessage-exporter.php [-o|--output_directory output_directory]
                        output_directory: a path to the directory where the messages should be saved. Save files in the current directory by default.
                        [-f|--flush]
                        Flushes the existing backup DB.
                        [-r|--rebuild]
                        Rebuild the HTML files from the existing DB.
```