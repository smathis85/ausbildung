# Feuerwehrname Freilingen

The original Excel import uses `Freilingen`. The specific variants `Freiligen`,
`FF Freiligen` and `FF Freilingen` map to `Freilingen` in the CSV parser and
manual participant form. Schema maintenance updates existing participant
`department` values once, preserving IDs, enrollments, attendance and exams.
It increments changed participant versions and records an audit count.

Maintenance prints the number of changed department values and potential people
with identical names after normalization. It never merges or deletes people.
The normalization is idempotent and limited to these four variants.

DEV validated with synthetic fixtures. Production deployment awaits user response
on the preferred canonical spelling; the current candidate is the workbook spelling.
