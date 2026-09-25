# Feuerwehrname Freilingen

The original Excel import uses `Freilingen`. The specific variants `Freiligen`,
`FF Freiligen` and `FF Freilingen` map to `Freilingen` in the CSV parser and
manual participant form. Schema maintenance updates existing participant
`department` values once, preserving IDs, enrollments, attendance and exams.
It increments changed participant versions and records an audit count.

Maintenance prints the number of changed department values and potential people
with identical names after normalization. It never merges or deletes people.
The original correction was limited to these four variants. A follow-up CSV supplied
16 rows with eleven `FF ...` variants. The follow-up expands normalization to
the known firefighter names from the initial Excel import; unknown names remain
unchanged. It remains idempotent and preserves person IDs and attendance.

The user confirmed `Freilingen`. DEV and production were deployed after backups.
Production corrected 2 existing department values and found 0 potential duplicate people.
The training production page returned HTTP 200.

The follow-up implementation has passed synthetic CSV, attendance and archive tests.

After explicit user approval for direct production correction, the follow-up
release normalized 14 further participant department values. Together with the
previous 2 Freilingen corrections, all 16 rows in the supplied import are covered.
The maintenance reported 0 potential duplicate people, and production returned
HTTP 200. A database backup preceded the update.
