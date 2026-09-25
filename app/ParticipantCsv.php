<?php
declare(strict_types=1);
namespace Ausbildung;
use RuntimeException;
final class ParticipantCsv
{
    public function __construct(private Store $store) {}
    public static function department(string $value): string {
        $value=trim(preg_replace('/\s+/u',' ',$value));
        return in_array(mb_strtolower($value),['freiligen','ff freiligen','freilingen','ff freilingen'],true)
            ? 'Freilingen' : $value;
    }
    private static function key(array $r): string {
        $r['department']=self::department($r['department']);
        return implode("\x1f",array_map(fn($v)=>mb_strtolower(\Normalizer::normalize(preg_replace('/\s+/u',' ',trim($v)),\Normalizer::FORM_C)),[$r['first_name'],$r['last_name'],$r['department']]));
    }
    public static function parse(string $csv): array {
        if(strlen($csv)>524288)throw new RuntimeException('Die CSV-Datei darf höchstens 512 KB groß sein.');
        $csv=preg_replace('/^\xEF\xBB\xBF/','',$csv);
        if(!mb_check_encoding($csv,'UTF-8'))throw new RuntimeException('Bitte die Datei als CSV UTF-8 speichern.');
        if(str_contains($csv,"\0"))throw new RuntimeException('Ungültige CSV-Datei.');
        $stream=fopen('php://temp','r+');fwrite($stream,$csv);rewind($stream);
        try {
            $headerLine=fgets($stream);if($headerLine===false)throw new RuntimeException('Die Datei ist leer.');
            $separator=count(str_getcsv($headerLine,';','"',''))>=count(str_getcsv($headerLine,',','"',''))?';':',';
            rewind($stream);$headers=fgetcsv($stream,0,$separator,'"','');
            $names=['vorname'=>'first_name','nachname'=>'last_name','feuerwehr'=>'department','ausbildungsbeginn'=>'start_date','lehrgang'=>'course'];
            $mapped=[];
            foreach($headers as $h){$key=mb_strtolower(trim($h));if(!isset($names[$key])||in_array($names[$key],$mapped,true))throw new RuntimeException('Unbekannte oder doppelte Spalte. Bitte die CSV-Vorlage verwenden.');$mapped[]=$names[$key];}
            foreach(['first_name','last_name','department'] as $required)if(!in_array($required,$mapped,true))throw new RuntimeException('Pflichtspalten: Vorname, Nachname, Feuerwehr.');
            $rows=[];$line=1;
            while(($values=fgetcsv($stream,0,$separator,'"',''))!==false){
                $line++;if(count($values)===1&&trim($values[0]??'')==='')continue;
                if(count($rows)>=1000)throw new RuntimeException('Maximal 1.000 Teilnehmer pro Datei.');
                if(count($values)!==count($mapped))throw new RuntimeException("Zeile $line: Anzahl der Spalten stimmt nicht.");
                $row=array_merge(['start_date'=>'','course'=>''],array_combine($mapped,array_map('trim',$values)));
                $row['department']=self::department($row['department']);
                foreach(['first_name'=>100,'last_name'=>100,'department'=>120,'course'=>100] as $key=>$limit){
                    if(mb_strlen($row[$key])>$limit||($key!=='course'&&$row[$key]==='')||preg_match('/[\x00-\x1f\x7f]/u',$row[$key]))throw new RuntimeException("Zeile $line: Name, Feuerwehr oder Lehrgang ist ungültig.");
                }
                if($row['start_date']!==''){
                    $raw=$row['start_date'];
                    if(preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2})$/D',$raw,$parts)){
                        $raw=sprintf('%02d.%02d.20%02d',(int)$parts[1],(int)$parts[2],(int)$parts[3]);
                    }
                    $date=false;
                    foreach(['!Y-m-d'=>'/^\d{4}-\d{2}-\d{2}$/D','!d.m.Y'=>'/^\d{1,2}\.\d{1,2}\.\d{4}$/D'] as $format=>$pattern){
                        if(!preg_match($pattern,$raw))continue;
                        $d=\DateTimeImmutable::createFromFormat($format,$raw);
                        $errors=\DateTimeImmutable::getLastErrors();
                        if($d&&($errors===false||(!$errors['warning_count']&&!$errors['error_count']))&&((int)$d->format('Y'))>=2000){$date=$d;break;}
                    }
                    if(!$date)throw new RuntimeException("Zeile $line: Ausbildungsbeginn bitte als TT.MM.JJJJ oder JJJJ-MM-TT angeben.");
                    $row['start_date']=$date->format('Y-m-d');
                }
                $row['line']=$line;$rows[]=$row;
            }
            if(!$rows)throw new RuntimeException('Die CSV-Datei enthält keine Teilnehmer.');
            return $rows;
        }finally{fclose($stream);}
    }
    public function preview(array $rows): array {
        $seen=[];foreach($this->store->rows('SELECT first_name,last_name,department FROM participants') as $p)$seen[self::key($p)]=true;
        foreach($rows as &$row){$key=self::key($row);$row['duplicate']=isset($seen[$key]);$seen[$key]=true;}unset($row);
        return $rows;
    }
    public function apply(array $rows,int $year): array {
        if($year<2010||$year>2100)throw new RuntimeException('Ungültiges Ausbildungsjahr.');
        return $this->store->transaction(function()use($rows,$year){
            $added=0;$skipped=0;
            foreach($this->preview($rows) as $row){
                if($row['duplicate']){$skipped++;continue;}
                $this->store->run('INSERT INTO participants(first_name,last_name,department) VALUES(?,?,?)',[$row['first_name'],$row['last_name'],$row['department']]);$id=(int)$this->store->db->lastInsertId();
                $this->store->run('INSERT INTO enrollments(participant_id,year,start_date,course) VALUES(?,?,?,?)',[$id,$year,$row['start_date']?:null,$row['course']]);$added++;
            }
            $this->store->audit('participants_csv_imported','year',$year,['added'=>$added,'skipped'=>$skipped]);
            return ['added'=>$added,'skipped'=>$skipped];
        });
    }
}
