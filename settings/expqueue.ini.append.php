<?php /* #?ini charset="utf-8"?

# ezodf's background jobs on the job queue (expqueue); the work is in expOdfJobs::run().
[JobType_ezodf.install]
Handler=expOdfQueueHandler
Class=interactive
MaxAttempts=1
Timeout=3600
Description=ezodf: install, update or remove the LibreOffice used for document conversions

[JobType_ezodf.server]
Handler=expOdfQueueHandler
Class=interactive
MaxAttempts=1
Timeout=300
Description=ezodf: start, stop or restart the conversion server

[JobType_ezodf.test]
Handler=expOdfQueueHandler
Class=interactive
MaxAttempts=1
Timeout=900
Description=ezodf: the test conversions of the Document converter page

[JobType_ezodf.import]
Handler=expOdfQueueHandler
Class=bulk
MaxAttempts=2
Timeout=900
Description=ezodf: one document of an import batch

[JobType_ezodf.export]
Handler=expOdfQueueHandler
Class=bulk
MaxAttempts=1
Timeout=3600
Description=ezodf: an export of documents as a ZIP

*/ ?>
