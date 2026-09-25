# CSV date correction

A date such as 28.08.26 was parsed with PHP's four-digit Y format as year 0026.
The CSV parser explicitly expands `TT.MM.JJ` to `TT.MM.20JJ`; four-digit
German and ISO dates remain supported. The preview shows `TT.MM.JJJJ`.

The idempotent schema maintenance fixes only previously saved CSV enrollment
start dates with years 0000–0099 and no Excel source label. It increments the
enrollment version to prevent a stale form from overwriting the repair. Existing
Excel data, other date fields and current records are not changed. A backup is
created before running maintenance. Production deployment awaits approval.
