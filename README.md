iMessage-Exporter
=================
Exports iMessages and SMS's to HTML files.

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