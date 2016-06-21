OSX Messages Exporter
=================
Exports Messages' conversations to HTML files. This includes iMessages, SMSs, and group conversations.

This script processes all of the existing conversations in the Messages app, logs them to a separate backup database in your specified output directory, and then generates HTML files for each conversation, mimicking the look and feel of Messages conversations. (See [example.html](example.html) for an example of one of the HTML files.) Attachments are saved in a separate directory for each conversation.

It will try and match phone numbers and email addresses to real names, using your Mac's Address Book. For best results, open your Contacts app and drag all contacts from the iCloud section to the "On My Mac" section first.

With the `-f` or `--flush` option, you can force the script to delete the existing backup database and regenerate everything. (Without this option, you could maintain backups of conversations even if Messages deletes them, accidentally or not.) 

With the `-r` or `--rebuild` option, you can regenerate the HTML files from the backup library.

Usage
=====
```
$ messages-exporter.php [-o|--output_directory output_directory]
                        output_directory: a path to the directory where the messages should be saved. Save files in the current directory by default.
                        [-f|--flush]
                        Flushes the existing backup DB, essentially starting over from scratch.
                        [-r|--rebuild]
                        Rebuild the HTML files from the existing DB.
```

Caveats
=======

If you run the script, and then delete a conversation in Messages, and then run the script again, the backup of the deleted conversation will not be deleted. This is by design.

Questions?
==========
Email me at cfinke@gmail.com.