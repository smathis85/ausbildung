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
 for page in ['person&id=1&year=2026','lessons','lesson&id=1','import','account']:
  status,view,_=get('/?page='+page);assert status==200,(page,status)
 status,body,_=get('/?page=lesson&id=1')
 status,body,_=get('/?page=lesson&id=1',{'action':'attendance','csrf':token(body),'id':1,'version':1,'present[]':[1]})
 assert status==200 and 'Anwesenheiten gespeichert' in body
 assert '12 Teilnahmen erreicht' in get('/')[1]
 status,body,_=get('/?page=lesson&id=1',{'action':'attendance','csrf':token(body),'id':1,'version':1})
 assert 'zwischenzeitlich' in body
 assert '12 Teilnahmen erreicht' in get('/')[1]
 # Check the eligibility boundary and retained 12-participation milestone.
 import sqlite3
 db=sqlite3.connect(data+'/training.sqlite')
 for total,expected in [(9,'pending'),(10,'ready10'),(11,'ready10'),(12,'ready')]:
  db.execute('UPDATE enrollments SET adjustment=? WHERE participant_id=1 AND year=2026',(total-1,));db.commit()
  _,view,_=get('/')
  assert 'data-status="'+expected+'"' in view,(total,expected)
  assert '<option value="ready10">10 Teilnahmen erreicht</option>' in view
  _,csv,_=get('/?page=export&year=2026');assert 'Fehlend bis 10' in csv
 for total,exam,expected in [(11,'2026-09-24','passed'),(12,'2026-09-24','completed'),(12,None,'ready')]:
  db.execute('UPDATE enrollments SET adjustment=?,exam_date=? WHERE participant_id=1 AND year=2026',(total-1,exam));db.commit()
  _,view,_=get('/')
  assert 'data-status="'+expected+'"' in view
  if expected=='completed':assert '>Abgeschlossen</span>' in view
 db.close()
 # Checkbox POST archives the qualified person and preserves the legacy note.
 db=sqlite3.connect(data+'/training.sqlite')
 db.execute("UPDATE enrollments SET reported='Legacy note',exam_label='bestanden' WHERE participant_id=1 AND year=2026");db.commit()
 _,view,_=get('/?page=person&id=1&year=2026')
 assert 'type="checkbox" name="reported_confirmed"' in view
 assert 'name="reported"' not in view
 def formvalue(name):return re.search('name="'+name+'" value="([^"]+)"',view).group(1)
 fields={'action':'person','csrf':token(view),'id':1,'year':2026,'first_name':'Alex','last_name':'Beispiel','department':'Musterwehr','adjustment':11,'adjustment_reason':'Test only','exam_date':'','reported_confirmed':'1','person_version':formvalue('person_version'),'enrollment_version':formvalue('enrollment_version')}
 status,view,_=get('/?page=person&id=1&year=2026',fields)
 assert status==200 and 'Dieser Teilnehmer ist archiviert' in view
 assert db.execute('SELECT archived FROM participants WHERE id=1').fetchone()[0]==1
 assert db.execute('SELECT reported,reported_confirmed,exam_label FROM enrollments WHERE participant_id=1 AND year=2026').fetchone()==('Legacy note',1,'bestanden')
 db.execute('UPDATE participants SET archived=0 WHERE id=1');db.execute("UPDATE enrollments SET exam_date=NULL,exam_label='',reported_confirmed=0 WHERE participant_id=1 AND year=2026");db.commit();db.close()
 # CRUD and annual rollover use synthetic data only.
 status,body,_=get('/?page=person&year=2026')
 fields={'action':'person','csrf':token(body),'id':0,'year':2026,'first_name':'<script>Test</script>','last_name':'Neu','department':'Musterwehr','adjustment':0,'adjustment_reason':''}
 status,body,_=get('/?page=person&year=2026',fields)
 assert status==200 and '&lt;script&gt;Test&lt;/script&gt;' in body and '<script>Test</script>' not in body
 status,body,_=get('/?page=lessons&year=2026')
 status,body,_=get('/?page=lessons&year=2026',{'action':'lesson','csrf':token(body),'id':0,'year':2026,'date':'2026-10-01','unit':2,'title':'Neue Testeinheit'})
 assert 'Neue Testeinheit' in body
 status,body,_=get('/');status,body,_=get('/',{'action':'year','csrf':token(body),'source_year':2026,'year':2027})
 assert 'Beispiel' in body and '12 Teilnahmen erreicht' in body and '2 aktive Teilnehmer' in body
 status,body,_=get('/?year=2027');status,body,_=get('/?year=2027',{'action':'year','csrf':token(body),'source_year':2026,'year':2027})
 assert 'bereits angelegt' in body
 status,csv,headers=get('/?page=export&year=2026');assert 'text/csv' in headers['Content-Type'] and 'Beispiel' in csv
 status,body,_=get('/');status,body,_=get('/',{'action':'logout','csrf':token(body)})
 assert 'Beispiel' not in body
 status,body,_=get('/',{'action':'login','csrf':token(body),'email':'sascha.mathis@ffvgs.de','password':'Demo-only-pass-123'})
 assert 'Beispiel' in body
 status,body,_=get('/?page=account',{'action':'password','csrf':token(body),'current_password':'Demo-only-pass-123','password':'New-demo-pass-456','password_confirmation':'New-demo-pass-456'})
 assert 'Beispiel' not in body
 status,body,_=get('/',{'action':'login','csrf':token(body),'email':'sascha.mathis@ffvgs.de','password':'Demo-only-pass-123'})
 assert 'Anmeldung nicht möglich' in body
 for _ in range(12):status,body,_=get('/',{'action':'login','csrf':token(body),'email':'wrong@example.invalid','password':'wrong'})
 assert status==429
 print('PASS: protected pages/export, CSRF, setup, login, password change, logout, rate limit, views, attendance and stale writes')
finally:
 server.terminate();server.wait(timeout=5);shutil.rmtree(data)
