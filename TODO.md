Alec Smecher Review 2018-12-19:

* This note [1] doesn't seem right to me -- maybe test again?

* e.g. here [2], we try to avoid manual concatenation of URLs -- it won't work when the path_info_disabled setting is turned on.

* Several of the entity fetches (e.g. getting objects/settings from the database using the DAOs) take the IDs from the request without checking that they're appropriate. This may be accidentally opening the system to e.g. exposing unintended data. I haven't reviewed this in detail, though.

[1] https://github.com/fiduswriter/ojs-fiduswriter/blob/69eea07ea42edb62a5b5a100112715c6aee3486a/FidusWriterPlugin.inc.php#L22

[2] https://github.com/fiduswriter/ojs-fiduswriter/blob/69eea07ea42edb62a5b5a100112715c6aee3486a/FidusWriterPlugin.inc.php#L244
