#!/usr/bin/env python3
# Terminbuchung over HTTP with synthetic data and a local fake SMTP server: settings, public booking, bot checks, mails, manage link, rebook, cancel, admin views and access control.
import os,tempfile,subprocess,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,time,socket,pathlib,shutil,sqlite3,datetime,threading,email,email.policy,base64
root=pathlib.Path(__file__).resolve().parents[1];data=tempfile.mkdtemp(prefix='training-booking-http-')
env={**os.environ,'TRAINING_DATA_DIR':data,'TRAINING_LOCAL_TEST':'1'}
subprocess.run(['php',str(root/'tests/seed.php')],env=env,check=True,stdout=subprocess.DEVNULL)
db=sqlite3.connect(data+'/training.sqlite')
mails=[];auth=[]
def smtp_session(conn):
 f=conn.makefile('rb');w=lambda t:conn.sendall((t+'\r\n').encode())
 w('220 fake ESMTP')
 while True:
  line=f.readline().decode().rstrip('\r\n')
  if not line:return
  cmd=line.split(' ')[0].upper()
  if cmd=='EHLO':w('250-fake');w('250 AUTH PLAIN LOGIN')
  elif cmd=='AUTH':auth.append(base64.b64decode(line.split(' ')[2]).split(b'\0'));w('235 ok')
  elif cmd in('MAIL','RCPT'):w('250 ok')
  elif cmd=='DATA':
   w('354 go');raw=b''
   while True:
    l=f.readline()
    if l==b'.\r\n':break
    raw+=l[1:] if l.startswith(b'..') else l
   mails.append(email.message_from_bytes(raw,policy=email.policy.default));w('250 queued')
  elif cmd=='QUIT':w('221 bye');return
  else:w('500 ?')
smtp=socket.socket();smtp.bind(('127.0.0.1',0));smtp.listen();smtp_port=smtp.getsockname()[1]
def smtp_loop():
 while True:
  try:c,_=smtp.accept()
  except OSError:return
  with c:smtp_session(c)
threading.Thread(target=smtp_loop,daemon=True).start()
sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root/'public')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
admin,participant,anon,anon2,anon3,mailclient=client(),client(),client(),client(),client(),client();base=f'http://127.0.0.1:{port}'
def req(c,path='/',fields=None):
 try:r=c.open(urllib.request.Request(base+path,urllib.parse.urlencode(fields,doseq=True).encode() if fields is not None else None))
 except urllib.error.HTTPError as e:r=e
 return r.status,r.read().decode('utf-8-sig')
def token(body):return re.search(r'name="csrf" value="([a-f0-9]+)"',body).group(1)
def post(c,path,fields):
 _,body=req(c,path);return req(c,path,{'csrf':token(body),**fields})
def text(m):return m.get_body(preferencelist=('plain',)).get_content()
def wait_mails(n):
 for _ in range(100):
  if len(mails)>=n:return
  time.sleep(.05)
 raise AssertionError(f'expected {n} mails, got {len(mails)}')
def link(m):return re.search(r'http://127\.0\.0\.1:\d+(/\?page=buchung&t=[A-Za-z0-9_-]{43})',text(m)).group(1)
day=(datetime.date.today()+datetime.timedelta(days=10)).isoformat();later=(datetime.date.today()+datetime.timedelta(days=20)).isoformat()
try:
 for _ in range(50):
  try:_,body=req(admin,'/?page=setup');break
  except urllib.error.URLError:time.sleep(.1)
 req(admin,'/?page=setup',{'csrf':token(body),'action':'setup','email':'sascha.mathis@ffvgs.de','setup_code':'TEST-SETUP-ONLY','password':'Synthetic-admin-pass','password_confirmation':'Synthetic-admin-pass'})
 # Off by default, management needs a login.
 status,body=req(anon,'/?page=termine');assert status==200 and 'nicht freigeschaltet' in body and 'Termine buchen' in body
 _,body=req(anon,'/');assert 'Zu den Terminen' not in body
 for path in ['/?page=bookings','/?page=booking-event','/?page=settings','/?page=settings-mail','/?page=booking-export&id=1']:
  status,body=req(anon,path);assert 'Admin-Anmeldung' in body and '<details class="login-menu" open>' in body and 'Buchbare Termine' not in body,path
 status,body=post(anon,'/',{'action':'booking_event_save','id':0,'title':'Hack','date':day,'start':'19:00','capacity':5});assert status==401
 # Navigation: Unterlagen, Importprüfung and Zugang moved under Einstellungen.
 _,body=req(admin,'/?page=settings');nav=body.split('<main>')[0]
 assert 'Buchungen' in nav and 'Einstellungen' in nav and 'Importprüfung' not in nav and 'Unterlagen' not in nav and 'page=account' not in nav
 for page in ['settings','settings-mail','documents','import','account']:
  _,body=req(admin,'/?page='+page);assert 'aria-label="Einstellungen"' in body and f'href="/?page={page}" aria-current="page"' in body,page
 # Mail settings: password stored encrypted, test mail via the fake server.
 status,body=post(admin,'/?page=settings-mail',{'action':'settings_mail','settings_version':1,'mail_from_name':'Ausbildung Test','mail_from':'ausbildung@example.org','mail_reply_to':'leitung@example.org','smtp_host':'127.0.0.1','smtp_port':smtp_port,'smtp_security':'none','smtp_user':'postfach','smtp_password':'Smtp-Secret-1','mail_signature':'Grüße'})
 assert 'E-Mail-Einstellungen gespeichert' in body and 'Smtp-Secret-1' not in body and 'Eingerichtet' in body
 assert 'Smtp-Secret-1' not in str(db.execute('SELECT * FROM app_settings').fetchall()+db.execute('SELECT * FROM audit').fetchall())
 status,body=post(admin,'/?page=settings-mail',{'action':'settings_mail_test','test_to':'sascha@example.org'});assert 'Testmail an sascha@example.org versendet' in body
 wait_mails(1);assert mails[0]['Subject']=='Testmail der Ausbildungsverwaltung' and auth[-1][1:]==[b'postfach',b'Smtp-Secret-1'] and str(mails[0]['Reply-To'])=='leitung@example.org'
 # Saving without password keeps it.
 post(admin,'/?page=settings-mail',{'action':'settings_mail','settings_version':2,'mail_from_name':'Ausbildung Test','mail_from':'ausbildung@example.org','smtp_host':'127.0.0.1','smtp_port':smtp_port,'smtp_security':'none','smtp_user':'postfach','mail_signature':'Grüße'})
 post(admin,'/?page=settings-mail',{'action':'settings_mail_test','test_to':'sascha@example.org'});wait_mails(2);assert auth[-1][2]==b'Smtp-Secret-1'
 # Remote unencrypted SMTP is refused.
 post(admin,'/?page=settings-mail',{'action':'settings_mail','settings_version':3,'mail_from':'ausbildung@example.org','smtp_host':'smtp.example.org','smtp_port':25,'smtp_security':'none','smtp_user':'postfach','mail_signature':''})
 status,body=post(admin,'/?page=settings-mail',{'action':'settings_mail_test','test_to':'sascha@example.org'});assert 'nur an einen lokalen Mailserver' in body
 post(admin,'/?page=settings-mail',{'action':'settings_mail','settings_version':4,'mail_from_name':'Ausbildung Test','mail_from':'ausbildung@example.org','smtp_host':'127.0.0.1','smtp_port':smtp_port,'smtp_security':'none','smtp_user':'postfach','mail_signature':'Grüße'})
 # Booking settings: code required needs a code.
 bs={'action':'settings_booking','booking_enabled':'on','booking_code_required':'on','booking_close_hours':0,'booking_cancel_hours':0,'booking_max_per_email':3,'booking_optin_minutes':30,'booking_rate_per_hour':10,'booking_rate_codes':10,'booking_retention_days':90,'booking_department_mode':'list','booking_privacy_text':'Daten nur für diesen Termin.','booking_notify_enabled':'on','booking_notify_email':'leitung@example.org'}
 status,body=post(admin,'/?page=settings',{**bs,'settings_version':5});assert 'zuerst einen Buchungscode' in body and 'value="leitung@example.org"' in body
 status,body=post(admin,'/?page=settings',{**bs,'settings_version':5,'booking_code':'Buchen-2026'});assert 'Der neue Buchungscode gilt ab sofort' in body and 'Code festgelegt' in body
 # Events.
 status,body=post(admin,'/?page=booking-event',{'action':'booking_event_save','id':0,'title':'Atemschutz <Übung>','date':day,'start':'19:00','end':'21:30','location':'Gerätehaus','capacity':2,'description':'Bitte PSA mitbringen.','visible':'on'})
 assert 'Termin gespeichert' in body and 'Atemschutz &lt;Übung&gt;' in body and '0 / 2' in body
 post(admin,'/?page=booking-event',{'action':'booking_event_save','id':0,'title':'Ersatztermin','date':later,'start':'18:00','capacity':5,'visible':'on'})
 post(admin,'/?page=booking-event',{'action':'booking_event_save','id':0,'title':'Geheimer Entwurf','date':later,'start':'18:00','capacity':5})
 status,body=post(admin,'/?page=booking-event',{'action':'booking_event_save','id':0,'title':'Fehler','date':day,'start':'19:00','end':'18:00','capacity':5,'location':'Behalten'})
 assert 'nach dem Beginn' in body and 'value="Behalten"' in body
 # Public list and form.
 _,body=req(anon,'/');assert 'Zu den Terminen' in body and 'Anmeldung Teilnehmer 2-Jahres-Ausbildung' in body
 # Start page lists bookable events directly; the admin login sits closed behind the header icon.
 assert 'Atemschutz &lt;Übung&gt;' in body and 'Geheimer Entwurf' not in body and '<details class="login-menu">' in body
 _,body=req(anon,'/?page=termine');assert 'Atemschutz &lt;Übung&gt;' in body and 'Ersatztermin' in body and 'Geheimer Entwurf' not in body and 'Noch 2 frei' in body
 status,_=req(anon,'/?page=termin&id=3');assert status==404
 _,form=req(anon,'/?page=termin&id=1');assert 'name="website"' in form and 'Buchungscode' in form and 'Selters' in form and 'Andere Feuerwehr' in form and 'Daten nur für diesen Termin.' in form
 entry={'action':'book','event_id':1,'name':'Erika <b>Beispiel</b>','department':'Selters','email':'erika@example.org','booking_code':'Buchen-2026','privacy':'on','website':''}
 status,body=req(anon,'/?page=termin&id=1',{'csrf':token(form),**entry});assert 'nicht angenommen' in body and 'value="Erika &lt;b&gt;Beispiel&lt;/b&gt;"' in body
 for c in [anon2,anon3]:req(c,'/?page=termin&id=1')
 time.sleep(3.2)
 status,body=req(anon,'/?page=termin&id=1',{'csrf':token(form),**entry,'website':'http://spam'});assert 'nicht angenommen' in body
 status,body=req(anon,'/?page=termin&id=1',{'csrf':token(form),**entry,'booking_code':'falsch'});assert 'Buchungscode stimmt nicht' in body
 status,body=req(anon,'/?page=termin&id=1',{'csrf':token(form),**{k:v for k,v in entry.items() if k!='privacy'}});assert 'Datenschutzhinweis' in body
 assert db.execute('SELECT COUNT(*) FROM bookings').fetchone()[0]==0
 status,body=req(anon,'/?page=termin&id=1',{'csrf':token(form),**entry});assert status==200 and 'Buchung bestätigt' in body and 'erika@example.org' in body and 'Buchung jetzt ansehen' in body
 wait_mails(4)
 confirm=[m for m in mails if str(m['To'])=='erika@example.org'][0];notify=[m for m in mails if str(m['To'])=='leitung@example.org'][0]
 assert confirm['Subject'].startswith('Buchung bestätigt: Atemschutz <Übung>') and 'Bitte PSA mitbringen.' in text(confirm) and 'Gerätehaus' in text(confirm)
 ics=[p for p in confirm.iter_attachments() if p.get_content_type()=='text/calendar'][0].get_content()
 assert 'BEGIN:VEVENT' in ics and 'SUMMARY:Atemschutz <Übung>' in ics
 assert notify['Subject']=='Neue Buchung: Atemschutz <Übung>' and 'Belegung: 1 von 2' in text(notify)
 erika=link(confirm)
 assert erika.split('t=')[1] not in str(db.execute('SELECT * FROM bookings').fetchall())
 # Code entered once is remembered in the session; the second form does not ask again.
 _,form=req(anon,'/?page=termin&id=2');assert 'name="booking_code"' not in form
 # Other fire brigade via free text.
 _,form2=req(anon2,'/?page=termin&id=1')
 status,body=req(anon2,'/?page=termin&id=1',{'csrf':token(form2),**entry,'name':'Max Muster','email':'max@example.org','department':'__other','department_other':'FF Nachbarort'})
 assert 'Buchung bestätigt' in body and db.execute("SELECT department FROM bookings WHERE email='max@example.org'").fetchone()[0]=='FF Nachbarort'
 # Full now.
 _,form3=req(anon3,'/?page=termin&id=1');assert 'Ausgebucht' in form3 and 'name="action" value="book"' not in form3
 status,body=req(anon3,'/?page=termin&id=1',{'csrf':token(req(anon3,'/')[1]),**entry,'email':'tina@example.org','name':'Tina Test'});assert 'ausgebucht' in body or 'Ausgebucht' in body
 # Manage link works without session or code; GET never changes anything.
 status,body=req(mailclient,erika);assert status==200 and 'Erika &lt;b&gt;Beispiel&lt;/b&gt;' in body and 'Umbuchen' in body and 'Ersatztermin' in body and 'Geheimer Entwurf' not in body
 status,body=req(mailclient,erika,{'csrf':token(body),'action':'booking_rebook','t':erika.split('t=')[1],'target':2})
 assert 'Umgebucht auf „Ersatztermin“' in body and db.execute('SELECT event_id FROM bookings WHERE email=?',('erika@example.org',)).fetchone()[0]==2
 wait_mails(7)
 rebooked=[m for m in mails if m['Subject'].startswith('Umbuchung bestätigt')][0];erika2=link(rebooked)
 assert erika2!=erika and 'gilt nicht mehr' in text(rebooked)
 status,body=req(mailclient,erika);assert status==404 and 'ungültig oder abgelaufen' in body
 _,body=req(anon3,'/?page=termine');assert 'Noch 1 frei' in body
 # Cancel via the new link.
 _,body=req(mailclient,erika2)
 status,body=req(mailclient,erika2,{'csrf':token(body),'action':'booking_cancel','t':erika2.split('t=')[1]});assert 'storniert' in body and 'Storniert' in body and 'Umbuchen' not in body
 wait_mails(9);assert any(m['Subject'].startswith('Stornierung: Ersatztermin') for m in mails)
 status,body=req(mailclient,erika2,{'csrf':token(req(mailclient,'/')[1]),'action':'booking_cancel','t':erika2.split('t=')[1]});assert 'bereits storniert' in body
 # Admin view, print list, CSV, manual add and cancel.
 _,body=req(admin,'/?page=booking-event&id=1');assert 'Max Muster' in body and 'FF Nachbarort' in body and '1 / 2' in body and 'Teilnehmende (1)' in body
 status,body=req(admin,'/?page=booking-export&id=1');assert status==200 and '"Max Muster";"FF Nachbarort";max@example.org' in body
 status,body=post(admin,'/?page=booking-event&id=1',{'action':'booking_admin_add','event_id':1,'name':'Nachgemeldet Person','department':'Maroth','email':'nach@example.org','send_mail':'on'})
 assert 'eingetragen' in body and '2 / 2' in body
 bid=db.execute("SELECT id FROM bookings WHERE email='max@example.org'").fetchone()[0]
 status,body=post(admin,'/?page=booking-event&id=1',{'action':'booking_admin_cancel','id':bid});assert 'storniert' in body and '1 / 2' in body
 # Event cancellation mails everyone left.
 n=len(mails);status,body=post(admin,'/?page=booking-event&id=1',{'action':'booking_event_cancel','id':1});assert '1 Teilnehmende' in body and 'abgesagt' in body
 wait_mails(n+1);assert mails[-1]['Subject'].startswith('Termin abgesagt') and str(mails[-1]['To'])=='nach@example.org'
 _,body=req(anon,'/?page=termine');assert 'Atemschutz' not in body
 # Participants never see booking administration.
 post(admin,'/?page=account',{'action':'participant_access','access_version':1,'access_code':'Common-test-2026','enabled':'on'})
 _,body=req(participant,'/?page=participant-login')
 req(participant,'/?page=participant-login',{'csrf':token(body),'action':'participant_login','first_name':'Alex','last_name':'Beispiel','access_code':'Common-test-2026'})
 for path in ['/?page=bookings','/?page=booking-event&id=1','/?page=settings']:
  status,_=req(participant,path);assert status==403,path
 status,body=req(participant,'/?page=termine');assert status==200 and 'Ersatztermin' in body
 # Mails sent and listed, no failures.
 _,body=req(admin,'/?page=settings-mail');assert 'Fehlgeschlagen: 0' in body
 assert db.execute("SELECT COUNT(*) FROM mail_outbox WHERE status<>'sent'").fetchone()[0]==0
 print('PASS: settings tabs, SMTP settings and test mail, booking code, bot checks, booking, mails with calendar, full events, manage link, rebook, cancel, admin list/CSV/add/cancel, event cancel and access control')
finally:
 server.terminate();server.wait(timeout=5);smtp.close();db.close();shutil.rmtree(data)
