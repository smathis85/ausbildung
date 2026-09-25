# Feuerwehrname Freilingen

The original Excel import uses `Freilingen`. The specific variants `Freiligen`,
`FF Freiligen` and `FF Freilingen` map to `Freilingen` in the CSV parser and
manual participant form. Schema maintenance updates existing participant
`department` values once, preserving IDs, enrollments, attendance and exams.
It increments changed participant versions and records an audit count.

Maintenance prints the number of changed department values and potential people
with identical names after normalization. It never merges or deletes people.
The normalization is idempotent and limited to these four variants.

The user confirmed `Freilingen`. DEV and production were deployed after backups.
Production corrected 2 existing department values and found 0 potential duplicate people.
The training production page returned HTTP 200.
