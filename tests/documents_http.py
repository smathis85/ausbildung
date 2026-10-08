#!/usr/bin/env python3
import os,tempfile,subprocess,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,time,socket,pathlib,shutil,sqlite3,uuid
root=pathlib.Path(__file__).resolve().parents[1];data=tempfile.mkdtemp(prefix='training-documents-http-')
env={**os.environ,'TRAINING_DATA_DIR':data,'TRAINING_LOCAL_TEST':'1'}
subprocess.run(['php',str(root/'tests/seed.php')],env=env,check=True)
sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
server=subprocess.Popen(['php','-d','upload_max_filesize=1M','-d','post_max_size=2M','-S',f'127.0.0.1:{port}','-t',str(root/'public')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
base=f'http://127.0.0.1:{port}'
def req(c,path='/',fields=None,files=None,headers=None,raw=False):
 body=None;h=dict(headers or {})
 if files is not None:
  b=uuid.uuid4().hex;parts=[]
  for k,v in (fields or {}).items():parts.append(f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
  for k,(name,content) in files.items():parts.append(f'--{b}\r\nContent-Disposition: form-data; name="{k}"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+content+b'\r\n')
  body=b''.join(parts)+f'--{b}--\r\n'.encode();h['Content-Type']='multipart/form-data; boundary='+b
 elif fields is not None:body=urllib.parse.urlencode(fields,doseq=True).encode()
 try:r=c.open(urllib.request.Request(base+path,body,h))
 except urllib.error.HTTPError as e:r=e
 content=r.read();return r.status,(content if raw else content.decode('utf-8-sig')),r.headers
def token(body):return re.search(r'name="csrf" value="([a-f0-9]+)"',body).group(1)
def post(c,path,fields,**kw):
 _,body,_=req(c,path);return req(c,path,{'csrf':token(body),**fields},**kw)
db=sqlite3.connect(data+'/training.sqlite')
primary,second,participant,anon=client(),client(),client(),client()
try:
 for _ in range(50):
  try:req(anon,'/');break
  except urllib.error.URLError:time.sleep(.1)
 post(primary,'/?page=setup',{'action':'setup','email':'sascha.mathis@ffvgs.de','setup_code':'TEST-SETUP-ONLY','password':'Synthetic-admin-pass','password_confirmation':'Synthetic-admin-pass'})
 _,body,_=req(primary,'/?page=account');assert 'Weiteren Admin anlegen' in body and 'Hauptadmin' in body
 # Second administrator: one-time code shown once, own password, own session.
 _,body,_=post(primary,'/?page=account',{'action':'admin_create','name':'Zweiter <Admin>','email':'Zweiter@Example.org'})
 code=re.search(r'class="setup-code">([A-F0-9-]+)<',body).group(1);assert 'Zweiter &lt;Admin&gt;' in body
 _,body,_=req(primary,'/?page=account');assert code not in body and 'Einrichtung offen' in body
 assert code not in str(db.execute('SELECT * FROM accounts').fetchall()+db.execute('SELECT * FROM audit').fetchall())
 _,body,_=req(anon,'/');assert 'Admin-Zugang mit Einrichtungscode aktivieren' in body
 _,body,_=post(anon,'/?page=setup',{'action':'setup','email':'zweiter@example.org','setup_code':'00000-00000-00000-00000','password':'Second-admin-pass','password_confirmation':'Second-admin-pass'});assert 'Einrichtungscode oder Zugangsdaten ungültig' in body
 _,body,_=post(second,'/?page=setup',{'action':'setup','email':'zweiter@example.org','setup_code':code.lower().replace('-',''),'password':'Second-admin-pass','password_confirmation':'Second-admin-pass'})
 assert 'Ausbildungsübersicht' in body,body[:500]
 _,body,_=req(second,'/?page=account');assert 'Weiteren Admin anlegen' not in body and 'name="action" value="admin_remove"' not in body and 'zweiter@example.org' in body
 _,body,_=post(second,'/?page=account',{'action':'admin_create','name':'Dritter','email':'dritter@example.org'});assert 'nur der Hauptadministrator' in body
 _,body,_=post(second,'/?page=account',{'action':'admin_remove','id':1});assert 'nur der Hauptadministrator' in body
 _,body,_=post(anon,'/',{'action':'login','email':'zweiter@example.org','password':'Second-admin-pass'});assert 'Ausbildungsübersicht' in body
 _,body,_=post(anon,'/',{'action':'login','email':'sascha.mathis@ffvgs.de','password':'Second-admin-pass'});assert 'Anmeldung nicht möglich' in body
 _,body,_=post(anon,'/?page=setup',{'action':'setup','email':'zweiter@example.org','setup_code':code,'password':'Other-admin-pass1','password_confirmation':'Other-admin-pass1'});assert 'ungültig' in body
 anon=client()
 # Uploads: admins only, type and size checked, stored outside public/.
 pdf=b'%PDF-1.4\n% synthetic test document\n'+bytes(range(256))*40
 status,body,_=req(participant,'/?page=documents');assert 'Admin-Anmeldung' in body
 _,body,_=post(second,'/?page=documents',{'action':'document_upload','title':'Merkblatt <Atemschutz>','description':'Für alle','visible':'on'},files={'document':('Merkblatt Atemschutz ä.pdf',pdf)})
 assert 'Unterlage hochgeladen' in body and 'Merkblatt &lt;Atemschutz&gt;' in body
 _,body,_=post(primary,'/?page=documents',{'action':'document_upload','title':'Intern'},files={'document':('intern.docx',b'PK synthetic')})
 assert 'Unterlage hochgeladen' in body
 _,body,_=post(primary,'/?page=documents',{'action':'document_upload'},files={'document':('boese.php',b'<?php echo 1;')});assert 'Dateityp ist nicht erlaubt' in body
 _,body,_=post(primary,'/?page=documents',{'action':'document_upload'},files={'document':('seite.html',b'<script>alert(1)</script>')});assert 'Dateityp ist nicht erlaubt' in body
 status,body,_=post(primary,'/?page=documents',{'action':'document_upload'},files={'document':('gross.pdf',b'x'*1500000)});assert 'zu groß' in body,body[:300]
 status,body,_=post(primary,'/?page=documents',{'action':'document_upload'},files={'document':('riesig.pdf',b'x'*2500000)});assert status==413 and 'zu groß' in body
 docs=db.execute('SELECT id,stored_name,visible,original_name FROM documents ORDER BY id').fetchall();assert len(docs)==2 and docs[0][3]=='Merkblatt Atemschutz ä.pdf' and docs[1][2]==0
 stored=pathlib.Path(data)/'documents'/docs[0][1];assert stored.read_bytes()==pdf and oct(stored.stat().st_mode)[-3:]=='600'
 assert not list((root/'public').rglob(docs[0][1]))
 # Participants see released documents only and can view or download them.
 _,body,_=post(primary,'/?page=account',{'action':'participant_access','access_version':1,'access_code':'Common-test-2026','enabled':'on'})
 _,body,_=post(participant,'/?page=participant-login',{'action':'participant_login','first_name':'Alex','last_name':'Beispiel','access_code':'Common-test-2026'})
 assert 'Unterlagen' in body and 'Merkblatt &lt;Atemschutz&gt;' in body and 'Intern' not in body and 'Ansehen' in body
 status,content,headers=req(participant,f'/?page=document&id={docs[0][0]}',raw=True)
 assert status==200 and content==pdf and headers['Content-Type']=='application/pdf' and headers['Content-Disposition'].startswith('inline;') and "filename*=UTF-8''Merkblatt%20Atemschutz%20%C3%A4.pdf" in headers['Content-Disposition']
 assert "default-src 'none'" in headers['Content-Security-Policy'] and headers['X-Content-Type-Options']=='nosniff'
 status,content,headers=req(participant,f'/?page=document&id={docs[0][0]}&download=1',raw=True);assert headers['Content-Disposition'].startswith('attachment;') and content==pdf
 status,content,headers=req(participant,f'/?page=document&id={docs[0][0]}',headers={'Range':'bytes=10-19'},raw=True);assert status==206 and content==pdf[10:20] and headers['Content-Range']==f'bytes 10-19/{len(pdf)}'
 status,content,_=req(participant,f'/?page=document&id={docs[0][0]}',headers={'Range':'bytes=-5'},raw=True);assert status==206 and content==pdf[-5:]
 status,_,_=req(participant,f'/?page=document&id={docs[0][0]}',headers={'Range':'bytes=999999-'},raw=True);assert status==416
 status,body,_=req(participant,f'/?page=document&id={docs[1][0]}');assert status==404 and 'PK synthetic' not in body
 status,body,_=req(participant,'/?page=document&id=999');assert status==404
 status,body,_=req(participant,'/?page=documents');assert status==403
 status,body,_=post(participant,'/?page=me',{'action':'document_delete','id':docs[0][0]});assert status==403
 status,content,headers=req(primary,f'/?page=document&id={docs[1][0]}',raw=True);assert status==200 and headers['Content-Disposition'].startswith('attachment;')
 status,body,_=req(anon,f'/?page=document&id={docs[0][0]}');assert '%PDF' not in body and 'Admin-Anmeldung' in body
 # Changing release and deleting take effect immediately.
 _,body,_=req(primary,'/?page=documents');version=re.search(r'name="id" value="%d"><input type="hidden" name="version" value="(\d+)"'%docs[0][0],body).group(1)
 _,body,_=post(primary,'/?page=documents',{'action':'document_update','id':docs[0][0],'version':version,'title':'Merkblatt neu','description':''});assert 'Unterlage gespeichert' in body
 status,_,_=req(participant,f'/?page=document&id={docs[0][0]}');assert status==404
 _,body,_=post(primary,'/?page=documents',{'action':'document_update','id':docs[0][0],'version':version,'title':'Veraltet','visible':'on'});assert 'inzwischen geändert' in body
 _,body,_=post(primary,'/?page=documents',{'action':'document_delete','id':docs[0][0]});assert 'Unterlage gelöscht' in body and not stored.exists()
 # Removing an administrator ends their session at once.
 _,body,_=post(primary,'/?page=account',{'action':'admin_remove','id':2});assert 'entfernt' in body
 _,body,_=req(second,'/?page=documents');assert 'Admin-Anmeldung' in body
 print('PASS: second admin with one-time code, primary-only admin management, uploads with type/size checks, participant view/download/range, hidden docs, delete and session revocation')
finally:
 server.terminate();server.wait(timeout=5);db.close();shutil.rmtree(data)
