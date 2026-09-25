#!/usr/bin/env python3
import os,tempfile,subprocess,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,time,socket,pathlib,shutil,sqlite3
root=pathlib.Path(__file__).resolve().parents[1];data=tempfile.mkdtemp(prefix='training-participant-http-')
env={**os.environ,'TRAINING_DATA_DIR':data,'TRAINING_LOCAL_TEST':'1'}
subprocess.run(['php',str(root/'tests/seed.php')],env=env,check=True)
db=sqlite3.connect(data+'/training.sqlite');db.execute("UPDATE enrollments SET comment='INTERNAL-ADMIN-NOTE',reported='INTERNAL-ADMIN-REPORT'");db.commit()
sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root/'public')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
admin,participant=client(),client();base=f'http://127.0.0.1:{port}'
def req(c,path='/',fields=None):
 try:r=c.open(urllib.request.Request(base+path,urllib.parse.urlencode(fields,doseq=True).encode() if fields is not None else None))
 except urllib.error.HTTPError as e:r=e
 return r.status,r.read().decode('utf-8-sig')
def token(body):return re.search(r'name="csrf" value="([a-f0-9]+)"',body).group(1)
def login(c,code):
 _,body=req(c,'/?page=participant-login')
 return req(c,'/?page=participant-login',{'csrf':token(body),'action':'participant_login','first_name':'Alex','last_name':'Beispiel','access_code':code})
try:
 for _ in range(50):
  try:_,body=req(admin,'/?page=setup');break
  except urllib.error.URLError:time.sleep(.1)
 _,body=req(admin,'/?page=setup',{'csrf':token(body),'action':'setup','email':'sascha.mathis@ffvgs.de','setup_code':'TEST-SETUP-ONLY','password':'Synthetic-admin-pass','password_confirmation':'Synthetic-admin-pass'})
 _,public=req(participant,'/?page=participant-login');assert 'nicht freigeschaltet' in public
 _,body=req(admin,'/?page=account');csrf=token(body)
 _,body=req(admin,'/?page=account',{'csrf':csrf,'action':'participant_access','access_version':1,'access_code':'Common-test-2026','enabled':'on'})
 assert 'Teilnehmerzugang gespeichert' in body and 'Common-test-2026' not in body
 _,body=req(participant,'/?page=participant-login')
 assert '<select name="department">' in body and body.count('value="Musterwehr"')==1
 assert 'name="department" value=' not in body
 status,body=login(participant,'incorrect');assert 'Anmeldung nicht möglich' in body and 'Beispiel' not in body
 status,body=login(participant,'Common-test-2026');assert status==200 and 'Alex Beispiel' in body and '11 / 10' in body and '10 Teilnahmen erreicht' in body
 assert 'INTERNAL-ADMIN' not in body and 'CSV exportieren' not in body and 'name="action" value="person"' not in body
 for path in ['/?page=csv-import','/?page=csv-template','/?page=person&id=1&year=2026','/?page=person&id=2&year=2026','/?page=account','/?page=export&year=2026','/?page=import','/?page=lesson&id=1','/?page=lessons']:
  status,body=req(participant,path);assert status==403,(path,status);assert 'INTERNAL-ADMIN' not in body
 _,body=req(participant,'/?page=me&id=2');assert 'Alex Beispiel' in body
 status,body=req(participant,'/?page=me',{'csrf':token(body),'action':'attendance','id':1,'version':1,'present[]':[1]});assert status==403
 assert db.execute('SELECT count(*) FROM attendance').fetchone()[0]==0
 _,body=req(participant,'/?page=me');status,body=req(participant,'/?page=me',{'csrf':token(body),'action':'participant_access','access_version':2,'access_code':'Stolen-test-code','enabled':'on'});assert status==403
 _,body=req(admin,'/?page=account');_,body=req(admin,'/?page=account',{'csrf':token(body),'action':'participant_access','access_version':2,'access_code':'Rotated-test-2026','enabled':'on'})
 _,body=req(participant,'/?page=me');assert 'Alex Beispiel' not in body and 'Teilnehmeranmeldung' in body
 _,body=login(participant,'Common-test-2026');assert 'Anmeldung nicht möglich' in body
 _,body=login(participant,'Rotated-test-2026');assert 'Alex Beispiel' in body
 _,body=req(admin,'/?page=account');_,body=req(admin,'/?page=account',{'csrf':token(body),'action':'participant_access','access_version':3,'access_code':''})
 _,body=req(participant,'/?page=me');assert 'nicht freigeschaltet' in body and 'Alex Beispiel' not in body
 # Bad requests and repeated attempts remain protected, without exposing known names.
 status,body=req(participant,'/?page=participant-login',{'action':'participant_login'});assert status==403
 _,body=req(admin,'/?page=account');_,body=req(admin,'/?page=account',{'csrf':token(body),'action':'participant_access','access_version':4,'access_code':'','enabled':'on'})
 for _ in range(12):status,body=login(participant,'wrong')
 assert status==429
 print('PASS: admin-only code management, personal view, no admin/private data, ID tampering, writes denied, rotation, disable, CSRF and rate limit')
finally:
 server.terminate();server.wait(timeout=5);db.close();shutil.rmtree(data)
