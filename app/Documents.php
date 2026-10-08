<?php
declare(strict_types=1);
namespace Ausbildung;
use RuntimeException;

/** Training documents uploaded by administrators. Files live outside public/ under random names. */
final class Documents
{
    public const MAX_BYTES=50*1024*1024;
    /** Allowed extensions with the content type we send; never the type the browser claimed. */
    public const TYPES=[
        'pdf'=>'application/pdf','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp',
        'mp4'=>'video/mp4','mp3'=>'audio/mpeg','txt'=>'text/plain; charset=UTF-8','csv'=>'text/csv; charset=UTF-8','rtf'=>'application/rtf',
        'doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'=>'application/vnd.ms-powerpoint','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt'=>'application/vnd.oasis.opendocument.text','ods'=>'application/vnd.oasis.opendocument.spreadsheet','odp'=>'application/vnd.oasis.opendocument.presentation',
        'zip'=>'application/zip',
    ];
    /** Types a browser can show directly without running page scripts in our origin. */
    public const VIEWABLE=['pdf','png','jpg','jpeg','gif','webp','mp4','mp3','txt'];
    public function __construct(private Store $store,private string $dir) {}
    public static function viewable(array $doc): bool { return in_array($doc['extension'],self::VIEWABLE,true); }
    public static function size(int $bytes): string {
        if($bytes>=1048576)return number_format($bytes/1048576,1,',','.').' MB';
        return max(1,(int)ceil($bytes/1024)).' KB';
    }
    public function all(bool $visibleOnly): array {
        return $this->store->rows('SELECT d.*,a.name AS uploader FROM documents d LEFT JOIN accounts a ON a.id=d.uploaded_by'.($visibleOnly?' WHERE d.visible=1':'').' ORDER BY d.created_at DESC,d.id DESC');
    }
    public function find(int $id): ?array { return $this->store->one('SELECT * FROM documents WHERE id=?',[$id]); }
    public function path(array $doc): string {
        if(!preg_match('/^[a-f0-9]{32}$/',$doc['stored_name']))throw new RuntimeException('Ungültiger Dateiname.');
        return $this->dir.'/'.$doc['stored_name'];
    }
    /** @param array $file one entry of $_FILES */
    public function add(?array $file,string $title,string $description,bool $visible,int $uploadedBy): int {
        $error=is_array($file)?(int)($file['error']??UPLOAD_ERR_NO_FILE):UPLOAD_ERR_NO_FILE;
        if(in_array($error,[UPLOAD_ERR_NO_TMP_DIR,UPLOAD_ERR_CANT_WRITE,UPLOAD_ERR_EXTENSION],true)){
            error_log('Unterlagen-Upload: Serverfehler beim Zwischenspeichern (PHP-Upload-Code '.$error.')');
            throw new RuntimeException('Der Server konnte die Datei nicht zwischenspeichern. Bitte die Administration informieren.');
        }
        if($error===UPLOAD_ERR_INI_SIZE||$error===UPLOAD_ERR_FORM_SIZE)throw new RuntimeException('Die Datei ist zu groß (höchstens '.self::size(self::MAX_BYTES).').');
        if($error===UPLOAD_ERR_PARTIAL)throw new RuntimeException('Die Datei wurde nicht vollständig übertragen. Bitte erneut versuchen.');
        if($error!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))throw new RuntimeException('Bitte eine Datei auswählen.');
        $size=(int)filesize($file['tmp_name']);
        if($size<1)throw new RuntimeException('Die Datei ist leer.');
        if($size>self::MAX_BYTES)throw new RuntimeException('Die Datei ist zu groß (höchstens '.self::size(self::MAX_BYTES).').');
        $original=self::cleanName((string)($file['name']??''));
        $extension=strtolower(pathinfo($original,PATHINFO_EXTENSION));
        if(!isset(self::TYPES[$extension]))throw new RuntimeException('Dieser Dateityp ist nicht erlaubt. Erlaubt: '.implode(', ',array_keys(self::TYPES)).'.');
        $title=trim($title)!==''?trim($title):pathinfo($original,PATHINFO_FILENAME);
        if(mb_strlen($title)>200||mb_strlen($description)>1000)throw new RuntimeException('Titel oder Beschreibung ist zu lang.');
        if(!is_dir($this->dir)&&!mkdir($this->dir,0700,true)&&!is_dir($this->dir))throw new RuntimeException('Ablage für Unterlagen nicht verfügbar.');
        $stored=bin2hex(random_bytes(16));$target=$this->dir.'/'.$stored;
        if(!move_uploaded_file($file['tmp_name'],$target))throw new RuntimeException('Die Datei konnte nicht gespeichert werden.');
        chmod($target,0600);
        try{
            return $this->store->transaction(function()use($title,$description,$original,$stored,$extension,$size,$target,$visible,$uploadedBy){
                $this->store->run('INSERT INTO documents(title,description,original_name,stored_name,extension,size,sha256,visible,uploaded_by) VALUES(?,?,?,?,?,?,?,?,?)',
                    [$title,trim($description),$original,$stored,$extension,$size,hash_file('sha256',$target),$visible?1:0,$uploadedBy]);
                $id=(int)$this->store->db->lastInsertId();
                $this->store->audit('document_uploaded','document',$id,['size'=>$size,'extension'=>$extension]);
                return $id;
            });
        }catch(\Throwable $e){@unlink($target);throw $e;}
    }
    public function update(int $id,int $version,string $title,string $description,bool $visible): void {
        $title=trim($title);
        if($title===''||mb_strlen($title)>200||mb_strlen($description)>1000)throw new RuntimeException('Bitte einen Titel (höchstens 200 Zeichen) angeben.');
        $this->store->transaction(function()use($id,$version,$title,$description,$visible){
            if(!$this->store->run('UPDATE documents SET title=?,description=?,visible=?,version=version+1 WHERE id=? AND version=?',[$title,trim($description),$visible?1:0,$id,$version]))throw new RuntimeException('Unterlage inzwischen geändert oder gelöscht. Bitte neu laden.');
            $this->store->audit('document_updated','document',$id,['visible'=>$visible]);
        });
    }
    public function delete(int $id): void {
        $doc=$this->store->transaction(function()use($id){
            $doc=$this->find($id);
            if(!$doc)throw new RuntimeException('Unterlage nicht gefunden.');
            $this->store->run('DELETE FROM documents WHERE id=?',[$id]);
            $this->store->audit('document_deleted','document',$id);
            return $doc;
        });
        $path=$this->path($doc);if(is_file($path))unlink($path);
    }
    public static function cleanName(string $name): string {
        $name=basename(str_replace('\\','/',$name));
        $name=preg_replace('/[\x00-\x1F\x7F"]+/u','',$name)??'';
        $name=trim($name);
        if(mb_strlen($name)>180){$ext=pathinfo($name,PATHINFO_EXTENSION);$name=mb_substr(pathinfo($name,PATHINFO_FILENAME),0,170).($ext!==''?'.'.$ext:'');}
        return $name!==''?$name:'datei';
    }
    /** Streams a file with single-range support (needed for video on iOS). Sends its own headers. */
    public function send(array $doc,bool $download): never {
        $path=$this->path($doc);
        if(!is_file($path)){http_response_code(404);exit('Datei nicht gefunden.');}
        $size=(int)filesize($path);$inline=!$download&&self::viewable($doc);
        $fallback=preg_replace('/[^A-Za-z0-9._-]+/','_',$doc['original_name']);
        header_remove('Content-Security-Policy');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'");
        header('Content-Type: '.self::TYPES[$doc['extension']]);
        header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode($doc['original_name']));
        header('Accept-Ranges: bytes');
        $start=0;$end=$size-1;
        $range=$_SERVER['HTTP_RANGE']??'';
        if(is_string($range)&&$range!==''){
            if(!preg_match('/^bytes=(\d*)-(\d*)$/',$range,$m)||($m[1]===''&&$m[2]==='')){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
            if($m[1]===''){$start=max(0,$size-(int)$m[2]);}else{$start=(int)$m[1];if($m[2]!=='')$end=min($end,(int)$m[2]);}
            if($start>$end||$start>=$size){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
            http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
        }
        header('Content-Length: '.($end-$start+1));
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        while(ob_get_level())ob_end_clean();
        if(($_SERVER['REQUEST_METHOD']??'GET')==='HEAD')exit;
        $h=fopen($path,'rb');fseek($h,$start);$left=$end-$start+1;
        while($left>0&&!feof($h)){$chunk=fread($h,(int)min(1048576,$left));if($chunk===false)break;echo $chunk;flush();$left-=strlen($chunk);}
        fclose($h);exit;
    }
}
