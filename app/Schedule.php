<?php
declare(strict_types=1);
namespace Ausbildung;
use DateTimeImmutable;
use RuntimeException;

/**
 * Yearly training schedule (Terminplan). Each entry owns its lessons, so dates and units
 * changed here flow into "Termine & Anwesenheiten". Lessons with attendance are never deleted.
 */
final class Schedule
{
    public const WEEKDAYS=['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
    public const DEFAULT_TITLE='Zwei-Jahres-Ausbildung in der Feuerwehr der VG-Selters';
    public const DEFAULT_SUBTITLE='(Kurzfristige Änderungen vorbehalten)';
    public const DEFAULT_FOOTER="Ort: Gerätehaus Herschbach, wenn nicht anders angegeben\nZu den Übungsterminen ist grundsätzlich die komplette persönliche Schutzausrüstung zu tragen!\n\nRückmeldung für die Termine immer über DIVERA.\nFür die Samstagstermine ist eine verbindliche Anmeldung wichtig für die Essensbestellung!";
    public const WEEKDAY_TIME="19.00 Uhr\nbis\n21.30 Uhr";
    public const SATURDAY_TIME="8.00 Uhr\nbis\n14.30 Uhr";
    /** Field => maximum length. */
    public const TEXT_FIELDS=['time_text'=>300,'topic'=>200,'content'=>2000,'vehicles'=>2000,'instructors'=>2000,'notes'=>1000];

    public function __construct(private Store $store) {}

    public static function weekday(string $date): string { return self::WEEKDAYS[(int)(new DateTimeImmutable($date))->format('w')]; }
    /** Saturdays are two training units, all other days one. */
    public static function defaultUnits(string $date): int { return (new DateTimeImmutable($date))->format('w')==='6'?2:1; }
    public static function units(array $entry): int { return $entry['units']!==null?(int)$entry['units']:self::defaultUnits($entry['date']); }
    public static function defaultTime(string $date): string { return self::defaultUnits($date)===2?self::SATURDAY_TIME:self::WEEKDAY_TIME; }
    public static function validDate(string $value): string {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!$d||$d->format('Y-m-d')!==$value)throw new RuntimeException('Bitte ein gültiges Datum angeben.');
        return $value;
    }

    public function entries(int $year): array {
        return $this->store->rows('SELECT s.*,(SELECT COUNT(*) FROM attendance a JOIN lessons l ON l.id=a.lesson_id WHERE l.schedule_id=s.id) AS attendance
            FROM schedule_entries s WHERE s.year=? ORDER BY s.date,s.id',[$year]);
    }
    public function find(int $id): ?array { return $this->store->one('SELECT * FROM schedule_entries WHERE id=?',[$id]); }
    public function lessons(int $entryId): array {
        return $this->store->rows('SELECT l.*,(SELECT COUNT(*) FROM attendance a WHERE a.lesson_id=l.id) AS count FROM lessons l WHERE l.schedule_id=? ORDER BY l.unit,l.id',[$entryId]);
    }
    /** Years that have entries; for participants only released ones. */
    public function years(bool $publishedOnly): array {
        return array_map('intval',array_column($this->store->rows('SELECT DISTINCT s.year FROM schedule_entries s LEFT JOIN schedule_years y ON y.year=s.year'
            .($publishedOnly?' WHERE COALESCE(y.published,1)=1':'').' ORDER BY s.year DESC'),'year'));
    }
    public function upcoming(int $limit): array {
        return $this->store->rows('SELECT s.* FROM schedule_entries s LEFT JOIN schedule_years y ON y.year=s.year WHERE COALESCE(y.published,1)=1 AND s.date>=? ORDER BY s.date,s.id LIMIT '.max(1,$limit),[date('Y-m-d')]);
    }
    public function settings(int $year): array {
        return $this->store->one('SELECT * FROM schedule_years WHERE year=?',[$year])
            ??['year'=>$year,'title'=>self::DEFAULT_TITLE,'subtitle'=>self::DEFAULT_SUBTITLE,'footer'=>self::DEFAULT_FOOTER,'published'=>1,'version'=>0];
    }
    public function saveSettings(int $year,int $version,string $title,string $subtitle,string $footer,bool $published): void {
        $title=trim($title);$subtitle=trim($subtitle);$footer=trim($footer);
        if($title===''||mb_strlen($title)>200||mb_strlen($subtitle)>200||mb_strlen($footer)>2000)throw new RuntimeException('Bitte eine Überschrift angeben und die Textlängen prüfen.');
        $this->store->transaction(function()use($year,$version,$title,$subtitle,$footer,$published){
            $changed=$version===0
                ?$this->store->run('INSERT OR IGNORE INTO schedule_years(year,title,subtitle,footer,published) VALUES(?,?,?,?,?)',[$year,$title,$subtitle,$footer,$published?1:0])
                :$this->store->run('UPDATE schedule_years SET title=?,subtitle=?,footer=?,published=?,version=version+1 WHERE year=? AND version=?',[$title,$subtitle,$footer,$published?1:0,$year,$version]);
            if(!$changed)throw new RuntimeException('Der Terminplan wurde inzwischen geändert. Bitte neu laden.');
            $this->store->audit('schedule_settings_saved','schedule_year',$year,['published'=>$published]);
        });
    }

    /** Creates ($id=0) or updates an entry and keeps its lessons in step. Returns the entry id. */
    public function save(int $id,int $version,array $input): int {
        $data=self::clean($input);
        return $this->store->transaction(function()use($id,$version,$data){
            if($id){
                $old=$this->find($id);
                if(!$old)throw new RuntimeException('Termin nicht gefunden.');
                if((int)$old['year']!==$data['year'])throw new RuntimeException('Das Jahr eines Termins bleibt erhalten. Für ein anderes Jahr bitte einen neuen Termin anlegen.');
                if(!$this->store->run('UPDATE schedule_entries SET date=?,time_text=?,topic=?,content=?,vehicles=?,instructors=?,notes=?,units=?,version=version+1 WHERE id=? AND version=?',
                    [$data['date'],$data['time_text'],$data['topic'],$data['content'],$data['vehicles'],$data['instructors'],$data['notes'],$data['units'],$id,$version]))
                    throw new RuntimeException('Der Termin wurde inzwischen geändert. Bitte neu laden.');
            }else{
                $this->store->run('INSERT INTO schedule_entries(year,date,time_text,topic,content,vehicles,instructors,notes,units) VALUES(?,?,?,?,?,?,?,?,?)',
                    [$data['year'],$data['date'],$data['time_text'],$data['topic'],$data['content'],$data['vehicles'],$data['instructors'],$data['notes'],$data['units']]);
                $id=(int)$this->store->db->lastInsertId();
            }
            $this->sync($id);
            $this->store->audit('schedule_entry_saved','schedule_entry',$id,['date'=>$data['date']]);
            return $id;
        });
    }
    /** Deletes the entry. Lessons with recorded attendance stay as standalone lessons. Returns how many were kept. */
    public function delete(int $id): int {
        return $this->store->transaction(function()use($id){
            if(!$this->find($id))throw new RuntimeException('Termin nicht gefunden.');
            $kept=0;
            foreach($this->lessons($id) as $lesson){
                if((int)$lesson['count']>0){$this->store->run('UPDATE lessons SET schedule_id=NULL,version=version+1 WHERE id=?',[$lesson['id']]);$kept++;}
                else $this->store->run('DELETE FROM lessons WHERE id=?',[$lesson['id']]);
            }
            $this->store->run('DELETE FROM schedule_entries WHERE id=?',[$id]);
            $this->store->audit('schedule_entry_deleted','schedule_entry',$id,['kept_lessons'=>$kept]);
            return $kept;
        });
    }
    /** Same topics again: copies a year's plan, each date moved to the same weekday in the target year. */
    public function copyYear(int $from,int $to): int {
        if($to<=$from||$to>2100)throw new RuntimeException('Das Zieljahr muss nach dem Ausgangsjahr liegen.');
        return $this->store->transaction(function()use($from,$to){
            if($this->store->one('SELECT id FROM schedule_entries WHERE year=? LIMIT 1',[$to]))throw new RuntimeException('Für '.$to.' gibt es bereits einen Terminplan.');
            $entries=$this->entries($from);
            if(!$entries)throw new RuntimeException('Im Jahr '.$from.' gibt es keine Termine zum Übernehmen.');
            foreach($entries as $e){
                $this->store->run('INSERT INTO schedule_entries(year,date,time_text,topic,content,vehicles,instructors,notes,units) VALUES(?,?,?,?,?,?,?,?,?)',
                    [$to,self::sameWeekday($e['date'],$to),$e['time_text'],$e['topic'],$e['content'],$e['vehicles'],$e['instructors'],$e['notes'],$e['units']]);
                $this->sync((int)$this->store->db->lastInsertId());
            }
            $settings=$this->settings($from);
            // A copied plan is a draft until the dates have been checked.
            $this->store->run('INSERT OR IGNORE INTO schedule_years(year,title,subtitle,footer,published) VALUES(?,?,?,?,0)',[$to,$settings['title'],$settings['subtitle'],$settings['footer']]);
            $this->store->audit('schedule_year_copied','schedule_year',$to,['source'=>$from,'entries'=>count($entries)]);
            return count($entries);
        });
    }
    /** One-off import of an existing plan into an empty year (bin/manage.php import-schedule). */
    public function import(int $year,array $entries): array {
        return $this->store->transaction(function()use($year,$entries){
            if($this->store->one('SELECT id FROM schedule_entries WHERE year=? LIMIT 1',[$year]))return ['skipped'=>true];
            $added=[];
            foreach($entries as $input){
                $data=self::clean($input);
                if($data['year']!==$year)throw new RuntimeException('Termin '.$data['date'].' liegt nicht im Jahr '.$year.'.');
                $before=(int)$this->store->one('SELECT COUNT(*) n FROM lessons WHERE year=? AND date=? AND schedule_id IS NULL',[$year,$data['date']])['n'];
                $this->store->run('INSERT INTO schedule_entries(year,date,time_text,topic,content,vehicles,instructors,notes,units) VALUES(?,?,?,?,?,?,?,?,?)',
                    [$year,$data['date'],$data['time_text'],$data['topic'],$data['content'],$data['vehicles'],$data['instructors'],$data['notes'],$data['units']]);
                $id=(int)$this->store->db->lastInsertId();
                $this->sync($id);
                $added[]=['date'=>$data['date'],'units'=>self::units($this->find($id)),'existing_lessons'=>$before];
            }
            $this->store->audit('schedule_imported','schedule_year',$year,['entries'=>count($added)]);
            return ['skipped'=>false,'entries'=>$added];
        });
    }

    private static function clean(array $input): array {
        $data=[];
        foreach(self::TEXT_FIELDS as $field=>$max){
            $value=$input[$field]??'';
            if(!is_string($value))throw new RuntimeException('Ungültige Eingabe.');
            $value=trim(str_replace(["\r\n","\r"],"\n",$value));
            if(mb_strlen($value)>$max)throw new RuntimeException('Eingabe zu lang (höchstens '.$max.' Zeichen).');
            $data[$field]=$value;
        }
        if($data['topic']==='')throw new RuntimeException('Bitte ein Thema angeben.');
        $data['date']=self::validDate(is_string($input['date']??null)?$input['date']:'');
        $data['year']=(int)substr($data['date'],0,4);
        if($data['year']<2010||$data['year']>2100)throw new RuntimeException('Bitte ein gültiges Datum angeben.');
        $units=$input['units']??null;
        $data['units']=$units===null||$units===''||(int)$units===0?null:(int)$units;
        if($data['units']!==null&&($data['units']<1||$data['units']>4))throw new RuntimeException('Ausbildungseinheiten: 1 bis 4.');
        return $data;
    }
    private static function sameWeekday(string $date,int $year): string {
        $source=new DateTimeImmutable($date);
        $day=min((int)$source->format('d'),(int)(new DateTimeImmutable(sprintf('%04d-%s-01',$year,$source->format('m'))))->format('t'));
        $base=new DateTimeImmutable(sprintf('%04d-%s-%02d',$year,$source->format('m'),$day));
        $shift=((int)$source->format('w')-(int)$base->format('w')+7)%7;
        if($shift>3)$shift-=7;
        $target=$base->modify(($shift>=0?'+':'').$shift.' days');
        if((int)$target->format('Y')!==$year)$target=$target->modify($shift>0?'-7 days':'+7 days');
        return $target->format('Y-m-d');
    }
    /** Called inside a transaction: one lesson per training unit, carrying the entry's date and topic. */
    private function sync(int $id): void {
        $entry=$this->find($id);
        $units=self::units($entry);
        $lessons=$this->lessons($id);
        if(count($lessons)<$units){
            // Existing lessons on that date (for example from the Excel import) are adopted, keeping their attendance.
            $free=$this->store->rows('SELECT id FROM lessons WHERE year=? AND date=? AND schedule_id IS NULL ORDER BY unit,id LIMIT '.($units-count($lessons)),[$entry['year'],$entry['date']]);
            foreach($free as $lesson)$this->store->run('UPDATE lessons SET schedule_id=? WHERE id=?',[$id,$lesson['id']]);
            for($i=count($lessons)+count($free);$i<$units;$i++)$this->store->run('INSERT INTO lessons(year,date,unit,title,schedule_id) VALUES(?,?,?,?,?)',[$entry['year'],$entry['date'],$i+1,$entry['topic'],$id]);
            $lessons=$this->lessons($id);
        }
        foreach(array_slice($lessons,$units) as $lesson){
            if((int)$lesson['count']>0)throw new RuntimeException('Für Ausbildungseinheit '.(int)$lesson['unit'].' sind bereits Anwesenheiten erfasst. Bitte diese zuerst entfernen.');
            $this->store->run('DELETE FROM lessons WHERE id=?',[$lesson['id']]);
        }
        foreach(array_slice($lessons,0,$units) as $i=>$lesson){
            if($lesson['date']!==$entry['date']||$lesson['title']!==$entry['topic']||(int)$lesson['unit']!==$i+1)
                $this->store->run('UPDATE lessons SET date=?,title=?,unit=?,version=version+1 WHERE id=?',[$entry['date'],$entry['topic'],$i+1,$lesson['id']]);
        }
    }
}
