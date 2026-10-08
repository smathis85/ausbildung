#!/usr/bin/env python3
# Terminplan over HTTP with synthetic data: admin CRUD, lesson link, print markup, participant read-only view.
import os,tempfile,subprocess,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,time,socket,pathlib,shutil,sqlite3,datetime
root=pathlib.Path(__file__).resolve().parents[1];data=tempfile.mkdtemp(prefix='training-schedule-http-')
env={**os.environ,'TRAINING_DATA_DIR':data,'TRAINING_LOCAL_TEST':'1'}
subprocess.run(['php',str(root/'tests/seed.php')],env=env,check=True,stdout=subprocess.DEVNULL)
db=sqlite3.connect(data+'/training.sqlite')
sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root/'public')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
admin,participant,anon=client(),client(),client();base=f'http://127.0.0.1:{port}'
def req(c,path='/',fields=None):
 try:r=c.open(urllib.request.Request(base+path,urllib.parse.urlencode(fields,doseq=True).encode() if fields is not None else None))
 except urllib.error.HTTPError as e:r=e
 return r.status,r.read().decode('utf-8-sig')
def token(body):return re.search(r'name="csrf" value="([a-f0-9]+)"',body).group(1)
next_year=datetime.date.today().year+1
try:
 for _ in range(50):
  try:_,body=req(admin,'/?page=setup');break
  except urllib.error.URLError:time.sleep(.1)
 _,body=req(admin,'/?page=setup',{'csrf':token(body),'action':'setup','email':'sascha.mathis@ffvgs.de','setup_code':'TEST-SETUP-ONLY','password':'Synthetic-admin-pass','password_confirmation':'Synthetic-admin-pass'})
 status,body=req(anon,'/?page=schedule');assert 'Admin-Anmeldung' in body and 'Terminplan für das Jahr' not in body
 status,body=req(admin,'/?page=schedule&year=2026');assert status==200 and 'Terminplan 2026' in body and 'noch keine Termine' in body
 # The existing seeded lesson on 2026-09-24 (a Thursday) is adopted with its attendance.
 db.execute('INSERT INTO attendance(participant_id,lesson_id) VALUES(1,1)');db.commit()
 _,body=req(admin,'/?page=schedule-entry&year=2026')
 fields={'csrf':token(body),'action':'schedule_save','id':0,'version':1,'date':'2026-09-24','units':0,'time_text':'19.00 Uhr\r\nbis\r\n21.30 Uhr','topic':'Gefahrstoffe <b>','content':'GAMS-Regel','notes':'Ort: Testhaus','vehicles':'HLF Test','instructors':'Muster, Max\r\nTest, Tina'}
 status,body=req(admin,'/?page=schedule-entry&year=2026',fields)
 assert status==200 and 'Termin gespeichert' in body and 'Gefahrstoffe &lt;b&gt;' in body and '<b>' not in body.split('<main>')[1]
 assert 'Donnerstag<br>24.09.2026' in body and 'Muster, Max<br>' in body and 'class="schedule-note">Ort: Testhaus' in body
 assert db.execute('SELECT title,schedule_id FROM lessons WHERE id=1').fetchone()==('Gefahrstoffe <b>',1)
 assert db.execute('SELECT COUNT(*) FROM lessons WHERE schedule_id=1').fetchone()[0]==1
 # Lessons linked to the plan are edited there.
 _,body=req(admin,'/?page=lesson&id=1');assert 'Im Terminplan bearbeiten' in body and 'name="action" value="lesson"' not in body
 status,body=req(admin,'/?page=lesson&id=1',{'csrf':token(body),'action':'lesson','id':1,'year':2026,'version':2,'date':'2026-09-25','unit':1,'title':'X'})
 assert 'im Terminplan gepflegt' in body
 # Saturday gets two units; failed save keeps the typed values.
 _,body=req(admin,'/?page=schedule-entry&id=1')
 status,body=req(admin,'/?page=schedule-entry&id=1',{**fields,'csrf':token(body),'id':1,'version':99,'date':'2026-09-26','topic':'Geändert'})
 assert 'inzwischen geändert' in body and 'value="Geändert"' in body
 status,body=req(admin,'/?page=schedule-entry&id=1',{**fields,'csrf':token(body),'id':1,'version':1,'date':'2026-09-26'})
 assert 'Samstag<br>26.09.2026' in body and '2 Ausbildungseinheiten' in body
 assert db.execute("SELECT COUNT(*) FROM lessons WHERE schedule_id=1 AND date='2026-09-26'").fetchone()[0]==2
 _,body=req(admin,'/?page=lessons&year=2026');assert body.count('<small>Terminplan</small>')==2
 # Copy into next year as a draft.
 _,body=req(admin,'/?page=schedule&year=2026')
 status,body=req(admin,'/?page=schedule&year=2026',{'csrf':token(body),'action':'schedule_copy','source_year':2026,'year':next_year})
 assert '1 Termine nach' in body and 'Entwurf' in body
 # Participant view: read-only, released years only, no admin controls.
 _,body=req(admin,'/?page=account');req(admin,'/?page=account',{'csrf':token(body),'action':'participant_access','access_version':1,'access_code':'Common-test-2026','enabled':'on'})
 _,body=req(participant,'/?page=participant-login')
 _,body=req(participant,'/?page=participant-login',{'csrf':token(body),'action':'participant_login','first_name':'Alex','last_name':'Beispiel','access_code':'Common-test-2026'})
 assert 'Nächste Termine' in body and 'Ganzen Terminplan ansehen' in body
 status,body=req(participant,'/?page=schedule');assert status==200 and 'Gefahrstoffe &lt;b&gt;' in body and 'Muster, Max' in body
 assert 'Bearbeiten' not in body and 'schedule_save' not in body and str(next_year)+'</option>' not in body
 status,body=req(participant,f'/?page=schedule&year={next_year}');assert 'Terminplan für das Jahr 2026' in body
 for path in ['/?page=schedule-entry&id=1','/?page=schedule-entry&year=2026']:
  status,_=req(participant,path);assert status==403,path
 _,body=req(participant,'/?page=me')
 status,body=req(participant,'/?page=schedule',{'csrf':token(body),'action':'schedule_save','id':1,'version':2,'date':'2026-09-26','topic':'Hack'})
 assert status==403 and db.execute('SELECT topic FROM schedule_entries WHERE id=1').fetchone()[0]=='Gefahrstoffe <b>'
 # Deleting keeps the attended unit as a standalone lesson.
 _,body=req(admin,'/?page=schedule-entry&id=1')
 status,body=req(admin,'/?page=schedule-entry&id=1',{'csrf':token(body),'action':'schedule_delete','id':1})
 assert 'Termin gelöscht' in body and '1 Ausbildungseinheit(en)' in body
 assert db.execute('SELECT COUNT(*) FROM attendance').fetchone()[0]==1 and db.execute('SELECT schedule_id FROM lessons WHERE id=1').fetchone()[0] is None
 print('PASS: schedule CRUD, lesson sync, Saturday units, copy draft, participant read-only view, access control and delete')
finally:
 server.terminate();server.wait(timeout=5);db.close();shutil.rmtree(data)
