#!/usr/bin/env python3
import os, tempfile, subprocess, urllib.request, urllib.parse, urllib.error, http.cookiejar, re, time, socket, pathlib, shutil
root=pathlib.Path(__file__).resolve().parents[1]
data=tempfile.mkdtemp(prefix='training-http-')
env={**os.environ,'TRAINING_DATA_DIR':data,'TRAINING_LOCAL_TEST':'1'}
subprocess.run(['php',str(root/'tests/seed.php')],env=env,check=True)
sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root/'public')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
jar=http.cookiejar.CookieJar();client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar));base=f'http://127.0.0.1:{port}'
def get(path='/',fields=None):
 req=urllib.request.Request(base+path,urllib.parse.urlencode(fields,doseq=True).encode() if fields is not None else None)
 try: response=client.open(req)
 except urllib.error.HTTPError as e:response=e
 return response.status,response.read().decode('utf-8-sig'),response.headers
def token(body):return re.search(r'name="csrf" value="([a-f0-9]+)"',body).group(1)
try:
 for _ in range(50):
  try:status,body,headers=get();break
  except urllib.error.URLError:time.sleep(.1)
 else:raise RuntimeError('Server did not start')
 assert status==200 and 'Beispiel' not in body
 if env.get('TRAINING_ENV')=='production':
  assert 'Testumgebung' not in body and 'VG Selters · DEV' not in body
  assert 'ausbildung_session=' in headers['Set-Cookie'] and 'ausbildung_dev_session=' not in headers['Set-Cookie']
 for path in ['/?page=person&id=1&year=2026','/?page=export&year=2026','/?page=lesson&id=1','/?page=import']:
  assert 'Beispiel' not in get(path)[1]
 assert 'no-store' in headers['Cache-Control'] and 'frame-ancestors' in headers['Content-Security-Policy']
 assert get('/',{'action':'login','email':'sascha.mathis@ffvgs.de','password':'password'})[0]==403
 status,body,_=get('/',{'action':'login','csrf':token(body),'email':'sascha.mathis@ffvgs.de','password':'password'})
 assert 'Anmeldung nicht möglich' in body and 'Beispiel' not in body
 status,body,_=get('/?page=setup')
 status,body,_=get('/?page=setup',{'action':'setup','csrf':token(body),'email':'sascha.mathis@ffvgs.de','setup_code':'wrong','password':'Demo-only-pass-123','password_confirmation':'Demo-only-pass-123'})
 assert 'ungültig' in body
 status,body,_=get('/?page=setup',{'action':'setup','csrf':token(body),'email':'sascha.mathis@ffvgs.de','setup_code':'TEST-SETUP-ONLY','password':'Demo-only-pass-123','password_confirmation':'Demo-only-pass-123'})
 assert status==200 and 'Beispiel' in body and '10 Teilnahmen erreicht · prüfungsberechtigt' in body
 import sqlite3
 db=sqlite3.connect(data+'/training.sqlite')
 _,body,_=get('/?page=csv-import')
 assert 'multipart/form-data' in body and 'CSV-Vorlage herunterladen' in body
 _,template,headers=get('/?page=csv-template');assert 'text/csv' in headers['Content-Type'] and 'Vorname;Nachname;Feuerwehr' in template
 def upload(csrf,csv):
  boundary='TestBoundary0123456789'
  parts=[]
  for name,value in [('csrf',csrf),('action','csv_preview'),('year','2026')]:
   parts.append('--'+boundary+'\r\nContent-Disposition: form-data; name="'+name+'"\r\n\r\n'+value+'\r\n')
  parts.append('--'+boundary+'\r\nContent-Disposition: form-data; name="csv_file"; filename="test.csv"\r\nContent-Type: text/csv\r\n\r\n'+csv+'\r\n--'+boundary+'--\r\n')
  req=urllib.request.Request(base+'/?page=csv-import',''.join(parts).encode(),headers={'Content-Type':'multipart/form-data; boundary='+boundary})
  try:res=client.open(req)
  except urllib.error.HTTPError as e:res=e
  return res.status,res.read().decode('utf-8-sig')
 csv='Vorname;Nachname;Feuerwehr\nAlex;Beispiel;Musterwehr\nKim;<script>Test</script>;Testwehr'
 assert upload('wrong',csv)[0]==403
 status,preview=upload(token(body),csv)
 assert status==200 and '1 neue Teilnehmer' in preview and '&lt;script&gt;Test&lt;/script&gt;' in preview
 assert db.execute('SELECT COUNT(*) FROM participants').fetchone()[0]==1
 ticket=re.search('name="import_token" value="([^"]+)"',preview).group(1)
 fields={'csrf':token(preview),'action':'csv_confirm','import_token':ticket}
 status,result,_=get('/?page=csv-import',fields)
 assert status==200 and '1 Teilnehmer importiert, 1' in result
 assert db.execute('SELECT COUNT(*) FROM participants').fetchone()[0]==2
 assert 'bereits importiert' in get('/?page=csv-import',fields)[1]
 assert db.execute('SELECT COUNT(*) FROM participants').fetchone()[0]==2
 _,body,_=get('/?page=csv-import')
 assert 'ungültig' in upload(token(body),'Vorname;Nachname;Feuerwehr\nA;;B')[1]
 assert db.execute('SELECT COUNT(*) FROM participants').fetchone()[0]==2
 db.close()
 print('PASS: authenticated upload, CSRF, template, preview, escaping, confirmation, replay protection and invalid CSV')
finally:
 server.terminate();server.wait(timeout=5);shutil.rmtree(data)
