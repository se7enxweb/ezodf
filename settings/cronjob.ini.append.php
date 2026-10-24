<?php /* #?ini charset="utf-8"?

# The conversion server is kept running (after a reboot, after a crash) and old work files are removed by the cron
# part ezodf_keepalive.php: in the expqueue group, which every installation with the job queue runs each minute, in
# the default group, and in a group of its own (runcronjobs.php ezodf).
[CronjobSettings]
ExtensionDirectories[]=ezodf
Scripts[]=ezodf_keepalive.php

[CronjobPart-expqueue]
Scripts[]=ezodf_keepalive.php

[CronjobPart-ezodf]
Scripts[]=ezodf_keepalive.php

*/ ?>
