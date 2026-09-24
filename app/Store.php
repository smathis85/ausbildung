<?php
declare(strict_types=1);
namespace Ausbildung;
use PDO;
use RuntimeException;

final class Store
{
    public PDO $db;
    public function __construct(string $path) {
        $this->db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
    }
    public function schema(): void {
        $this->db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS accounts(id INTEGER PRIMARY KEY CHECK(id=1),email TEXT NOT NULL UNIQUE,password_hash TEXT,setup_hash TEXT,setup_expires INTEGER,version INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS participants(id INTEGER PRIMARY KEY,source_key TEXT UNIQUE,last_name TEXT NOT NULL,first_name TEXT NOT NULL,department TEXT NOT NULL,archived INTEGER NOT NULL DEFAULT 0,version INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS lessons(id INTEGER PRIMARY KEY,source_key TEXT UNIQUE,year INTEGER NOT NULL,date TEXT,unit INTEGER NOT NULL DEFAULT 1,title TEXT NOT NULL,source_label TEXT,version INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS enrollments(participant_id INTEGER NOT NULL REFERENCES participants(id),year INTEGER NOT NULL,adjustment INTEGER NOT NULL DEFAULT 0,adjustment_reason TEXT NOT NULL DEFAULT '',source_carry INTEGER,source_total INTEGER,start_label TEXT NOT NULL DEFAULT '',start_date TEXT,course TEXT NOT NULL DEFAULT '',exam_date TEXT,exam_label TEXT NOT NULL DEFAULT '',reported TEXT NOT NULL DEFAULT '',comment TEXT NOT NULL DEFAULT '',version INTEGER NOT NULL DEFAULT 1,PRIMARY KEY(participant_id,year));
CREATE TABLE IF NOT EXISTS attendance(participant_id INTEGER NOT NULL,lesson_id INTEGER NOT NULL REFERENCES lessons(id),created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(participant_id,lesson_id),FOREIGN KEY(participant_id) REFERENCES participants(id));
CREATE TABLE IF NOT EXISTS audit(id INTEGER PRIMARY KEY,at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,event TEXT NOT NULL,entity TEXT,entity_id INTEGER,details TEXT NOT NULL DEFAULT '{}');
CREATE TABLE IF NOT EXISTS imports(id INTEGER PRIMARY KEY,source_hash TEXT NOT NULL UNIQUE,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,summary TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS rate_limits(key TEXT PRIMARY KEY,attempts INTEGER NOT NULL,started INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS participant_access(id INTEGER PRIMARY KEY CHECK(id=1),code_hash TEXT,enabled INTEGER NOT NULL DEFAULT 0,version INTEGER NOT NULL DEFAULT 1);
INSERT OR IGNORE INTO participant_access(id) VALUES(1);
SQL);
        $this->transaction(function() {
            $columns=array_column($this->rows('PRAGMA table_info(enrollments)'),'name');
            if(!in_array('reported_confirmed',$columns,true)) {
                $this->db->exec('ALTER TABLE enrollments ADD COLUMN reported_confirmed INTEGER NOT NULL DEFAULT 0 CHECK(reported_confirmed IN (0,1))');
                foreach($this->rows("SELECT participant_id,year,reported FROM enrollments WHERE reported<>''") as $row) {
                    if(self::legacyReportedConfirmed($row['reported']))$this->run('UPDATE enrollments SET reported_confirmed=1,version=version+1 WHERE participant_id=? AND year=?',[$row['participant_id'],$row['year']]);
                }
                $this->audit('reported_checkbox_migrated');
                $this->archiveCompletedParticipants();
            }
        });
    }
    public static function legacyReportedConfirmed(string $value): bool {
        $value=trim($value);
        if(in_array(mb_strtolower($value),['x','ja','yes','1','true','✓','✔'],true))return true;
        foreach(['!Y-m-d H:i:s','!Y-m-d','!d.m.Y'] as $format){
            $date=\DateTimeImmutable::createFromFormat($format,$value);
            $errors=\DateTimeImmutable::getLastErrors();
            if($date&&($errors===false||($errors['warning_count']===0&&$errors['error_count']===0)))return true;
        }
        return false;
    }
    /** Called inside the surrounding write transaction; never deletes history. */
    public function archiveCompletedParticipants(?int $onlyId=null): int {
        $candidates=$this->rows("SELECT p.id,MAX(e.year) AS year FROM participants p JOIN enrollments e ON e.participant_id=p.id WHERE p.archived=0".($onlyId!==null?' AND p.id=?':'')." GROUP BY p.id",$onlyId!==null?[$onlyId]:[]);
        $count=0;
        foreach($candidates as $p){
            if($this->total((int)$p['id'],(int)$p['year'])<12)continue;
            if(!$this->one("SELECT year FROM enrollments WHERE participant_id=? AND reported_confirmed=1 AND (COALESCE(exam_date,'')<>'' OR TRIM(exam_label)<>'') LIMIT 1",[$p['id']]))continue;
            $this->run('UPDATE participants SET archived=1,version=version+1 WHERE id=? AND archived=0',[$p['id']]);
            $this->audit('participant_auto_archived','participant',(int)$p['id'],['year'=>(int)$p['year'],'reason'=>'passed_exam_twelve_participations_reported']);
            $count++;
        }
        return $count;
    }
    public function rows(string $sql,array $params=[]): array { $q=$this->db->prepare($sql); $q->execute($params); return $q->fetchAll(); }
    public function one(string $sql,array $params=[]): ?array { return $this->rows($sql,$params)[0]??null; }
    public function run(string $sql,array $params=[]): int { $q=$this->db->prepare($sql); $q->execute($params); return $q->rowCount(); }
    public function transaction(callable $fn): mixed {
        $this->db->exec('BEGIN IMMEDIATE');
        try { $result=$fn(); $this->db->exec('COMMIT'); return $result; }
        catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function audit(string $event,string $entity='',?int $id=null,array $details=[]): void {
        $this->run('INSERT INTO audit(event,entity,entity_id,details) VALUES(?,?,?,?)',[$event,$entity,$id,json_encode($details,JSON_THROW_ON_ERROR)]);
    }
    public function total(int $id,int $year): int {
        $a=$this->one('SELECT COALESCE(SUM(adjustment),0) n FROM enrollments WHERE participant_id=? AND year<=?',[$id,$year]);
        $b=$this->one('SELECT COUNT(*) n FROM attendance a JOIN lessons l ON l.id=a.lesson_id WHERE a.participant_id=? AND l.year<=?',[$id,$year]);
        return (int)$a['n']+(int)$b['n'];
    }
    public function annual(int $id,int $year): int { return (int)$this->one('SELECT COUNT(*) n FROM attendance a JOIN lessons l ON l.id=a.lesson_id WHERE a.participant_id=? AND l.year=?',[$id,$year])['n']; }
    public function participants(int $year): array {
        return $this->rows('SELECT p.*,e.*,p.id AS id,p.version AS person_version,e.version AS enrollment_version,
            (SELECT COALESCE(SUM(adjustment),0) FROM enrollments z WHERE z.participant_id=p.id AND z.year<=e.year)+(SELECT COUNT(*) FROM attendance a JOIN lessons l ON l.id=a.lesson_id WHERE a.participant_id=p.id AND l.year<=e.year) AS total,
            (SELECT COUNT(*) FROM attendance a JOIN lessons l ON l.id=a.lesson_id WHERE a.participant_id=p.id AND l.year=e.year) AS annual
            FROM participants p JOIN enrollments e ON e.participant_id=p.id WHERE e.year=? ORDER BY p.last_name COLLATE NOCASE,p.first_name COLLATE NOCASE',[$year]);
    }
    public function import(array $data): array {
        if (($data['format_version']??null)!==1 || !preg_match('/^[a-f0-9]{64}$/',$data['source_sha256']??'')) throw new RuntimeException('Unbekanntes Importformat.');
        return $this->transaction(function() use($data) {
            if ($this->one('SELECT id FROM imports WHERE source_hash=?',[$data['source_sha256']])) return ['duplicate'=>true];
            if ($this->one('SELECT id FROM participants LIMIT 1')) throw new RuntimeException('Erstimport nur in leere Ausbildungsdatenbank erlaubt.');
            $people=$lessons=[];
            foreach($data['people'] as $p) {
                $this->run('INSERT INTO participants(source_key,last_name,first_name,department) VALUES(?,?,?,?)',[$p['source_key'],$p['last_name'],$p['first_name'],$p['department']]);
                $people[$p['source_key']]=(int)$this->db->lastInsertId();
            }
            foreach($data['sessions'] as $l) {
                $this->run('INSERT INTO lessons(source_key,year,date,unit,title,source_label) VALUES(?,?,?,?,?,?)',[$l['source_key'],$l['year'],$l['date'],$l['unit'],$l['title'],$l['source_label']]);
                $lessons[$l['source_key']]=(int)$this->db->lastInsertId();
            }
            foreach($data['enrollments'] as $e) {
                $this->run('INSERT INTO enrollments(participant_id,year,adjustment,adjustment_reason,source_carry,source_total,start_label,start_date,course,exam_date,exam_label,reported,comment) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$people[$e['person']],$e['year'],$e['adjustment'],$e['adjustment_reason'],$e['source_carry'],$e['source_total'],$e['start_label'],$e['start_date'],$e['course'],$e['exam_date'],$e['exam_label'],$e['reported'],$e['comment']]);
            }
            foreach($data['enrollments'] as $e)if(self::legacyReportedConfirmed($e['reported']))$this->run('UPDATE enrollments SET reported_confirmed=1 WHERE participant_id=? AND year=?',[$people[$e['person']],$e['year']]);
            foreach($data['attendance'] as $a) $this->run('INSERT INTO attendance(participant_id,lesson_id) VALUES(?,?)',[$people[$a['person']],$lessons[$a['session']]]);
            foreach($data['enrollments'] as $e) if($this->total($people[$e['person']],$e['year'])!==$e['source_total']) throw new RuntimeException('Importabgleich fehlgeschlagen.');
            $this->archiveCompletedParticipants();
            $summary=['people'=>count($people),'lessons'=>count($lessons),'attendance'=>count($data['attendance']),'adjustments'=>count($data['warnings']),'years'=>$data['summary']];
            $this->run('INSERT INTO imports(source_hash,summary) VALUES(?,?)',[$data['source_sha256'],json_encode($summary,JSON_THROW_ON_ERROR)]);
            $this->audit('excel_import','import',(int)$this->db->lastInsertId(),$summary);
            return $summary;
        });
    }
    public function saveAttendance(int $lessonId,int $version,array $selected): void {
        $this->transaction(function() use($lessonId,$version,$selected) {
            $lesson=$this->one('SELECT * FROM lessons WHERE id=?',[$lessonId]);
            if(!$lesson || (int)$lesson['version']!==$version) throw new RuntimeException('Der Termin wurde zwischenzeitlich geändert. Bitte neu laden.');
            $allowed=array_map('intval',array_column($this->rows('SELECT participant_id FROM enrollments WHERE year=?',[$lesson['year']]),'participant_id'));
            if(array_diff($selected,$allowed)) throw new RuntimeException('Ungültige Teilnehmerauswahl.');
            $before=array_map('intval',array_column($this->rows('SELECT participant_id FROM attendance WHERE lesson_id=?',[$lessonId]),'participant_id'));
            foreach(array_diff($before,$selected) as $id) $this->run('DELETE FROM attendance WHERE lesson_id=? AND participant_id=?',[$lessonId,$id]);
            foreach(array_diff($selected,$before) as $id) $this->run('INSERT INTO attendance(participant_id,lesson_id) VALUES(?,?)',[$id,$lessonId]);
            $this->run('UPDATE lessons SET version=version+1 WHERE id=?',[$lessonId]);
            foreach(array_unique(array_merge($before,$selected)) as $participantId)$this->archiveCompletedParticipants((int)$participantId);
            $this->audit('attendance_saved','lesson',$lessonId,['added'=>array_values(array_diff($selected,$before)),'removed'=>array_values(array_diff($before,$selected))]);
        });
    }
}
