"""HTTP integration checks against the dummy localhost WordPress only."""
import urllib.request, urllib.parse, urllib.error, http.cookiejar, html, re, uuid
from html.parser import HTMLParser
BASE='http://127.0.0.1:18741'
cookies=http.cookiejar.CookieJar()
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
checks=0
class Forms(HTMLParser):
    def __init__(self, text):
        super().__init__(); self.forms=[]; self.current=None; self.feed(text)
    def handle_starttag(self, tag, attrs):
        attrs=dict(attrs)
        if tag=='form': self.current={'action':attrs.get('action',''),'fields':{}}; self.forms.append(self.current)
        if tag=='input' and self.current is not None and attrs.get('name'):
            self.current['fields'][attrs['name']]=attrs.get('value','')
    def handle_endtag(self, tag):
        if tag=='form': self.current=None

def request(path, data=None, raw=None, content_type=None):
    url=path if path.startswith(BASE) else BASE+path
    assert url.startswith(BASE+'/'), 'Only local QA requests allowed'
    payload=raw if raw is not None else (urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None)
    headers={'Content-Type':content_type} if content_type else {}
    try:
        with client.open(urllib.request.Request(url,data=payload,headers=headers),timeout=45) as response:
            return response.status, response.read().decode('utf-8',errors='replace')
    except urllib.error.HTTPError as err: return err.code, err.read().decode('utf-8',errors='replace')
def check(ok,name):
    global checks
    if not ok: raise AssertionError(name)
    checks+=1; print('PASS:',name)
def form(text, action):
    return next(f for f in Forms(text).forms if f['fields'].get('wkel_outreach_action')==action or f['fields'].get('action')==action)
request('/wp-login.php')
status,text=request('/wp-login.php',{'log':'wkelqa','pwd':'DummyQA-only-DoNotUseInProduction-140','wp-submit':'Log In','redirect_to':BASE+'/wp-admin/','testcookie':'1'})
check(status==200 and 'wp-admin-bar' in text,'dummy admin login')
path='/wp-admin/admin.php?page=wkel_outreach'
status,text=request(path)
check(status==200 and 'Bulk sending is disabled' in text,'Outreach menu and page')
settings=form(text,'settings')['fields']; settings.update(enabled='1',dry_run='1'); request(path,settings)
_,text=request(path)
add=form(text,'add')['fields']; add.update(email='http-'+uuid.uuid4().hex+'@example.invalid',name='HTTP Dummy',organisation='HTTP QA')
status,text=request(path,add)
check(status==200 and 'Lead staged. No email sent.' in text,'manual draft add over HTTP')
check('HTTP QA \u00e2' not in text and 'HTTP QA \u2014' in text,'new lead title has correct encoding')
lead_id=re.search(r'name="lead_id"[^>]*value="(\d+)"',text).group(1)
invalid=dict(add); invalid['_wpnonce']='invalid'; status,error=request(path,invalid)
check('Lead staged. No email sent.' not in error and ('expired' in error or 'Are you sure' in error or status >= 400), 'invalid nonce blocks admin mutation')
_,text=request(path+'&lead_id='+lead_id)
save=form(text,'save_template')['fields']; save.update(template_name='HTTP QA approved',subject='Hello {{first_name}}',html='<div style="padding:20px"><h2>Hello {{full_name}}</h2><p>{{organisation}}</p><a href="{{unsubscribe_url}}">Unsubscribe</a></div>')
_,text=request(path,save); check('Template saved as draft' in text,'template save is draft')
approve=form(text,'approve')['fields']; template_id=approve['template_id']; _,text=request(path,approve)
check('Template approved.' in text,'explicit template approval')
preview=form(text,'preview')['fields']; preview.update(lead_id=lead_id,template_id=template_id)
_,text=request(path,preview)
check('Subject: Hello HTTP' in text and 'srcdoc=' in text and 'Send this email' in text,'formatted merged preview plus explicit Send')
send=form(text,'send')['fields']; _,sent=request(path,send)
check('Dry run completed. No email was sent.' in sent and 'dry_run_complete' in sent,'explicit Send records dry run')
_,replay=request(path,send); check('Preview has expired' in replay,'repeated Send cannot replay token')
_,text=request(path,preview)
srcdoc=html.unescape(re.search(r'srcdoc="([^"]+)"',text).group(1))
url=html.unescape(re.search(r'href="([^"]+)"',srcdoc).group(1))
check('contact=' in url and 'email=' not in url and 'example.invalid' not in url,'signed unsubscribe URL omits recipient email')
_,optout=request(url)
check('You have been opted out' in optout and '<script' not in optout and 'Unsubscribe - WKEL Dummy connect' in optout,'signed unsubscribe works without analytics scripts')
_,blocked=request(path,form(text,'send')['fields']); check('Send blocked' in blocked,'opt-out after preview blocks send')
_,fallback=request('/unsubscribe/')
fallback_form=Forms(fallback).forms[0]['fields']; fallback_form['email']='fallback-'+uuid.uuid4().hex+'@example.invalid'
_,optout=request('/unsubscribe/',fallback_form)
check('You have been opted out' in optout,'manual fallback unsubscribe works')
_,text=request(path)
import_fields=form(text,'wkel_import_campaign_contacts')['fields']
boundary='WKELDummy'+uuid.uuid4().hex
parts=[]
for name,value in import_fields.items(): parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n')
csv=f'email,name,organisation,campaign\nhttp-csv-{uuid.uuid4().hex}@example.invalid,CSV Dummy,CSV HTTP QA,http-qa\nnot-an-email,Dummy,Dummy,http-qa\n{add["email"]},Opted Out,Dummy,http-qa\n'
parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="wkel_campaign_csv"; filename="dummy.csv"\r\nContent-Type: text/csv\r\n\r\n{csv}\r\n--{boundary}--\r\n')
_,text=request('/wp-admin/admin-post.php',raw=''.join(parts).encode(),content_type='multipart/form-data; boundary='+boundary)
check('Import complete: 1 contacts tracked, 2 rows skipped.' in text,'real CSV upload skips invalid and opted-out rows')
export_url=html.unescape(re.search(r'href="([^"]*action=wkel_export_suppression_csv[^"]*)"',text).group(1))
_,csv_export=request(export_url)
check(add['email'] in csv_export and 'Unsubscribed At' in csv_export,'suppression CSV export includes dummy opt-out')
_,text=request('/wp-admin/admin.php?page=wkel_all_leads')
check('value="resend_email"' not in text,'bulk send action absent from lead list')
nonce=next(f['fields']['_wpnonce'] for f in Forms(text).forms if '_wpnonce' in f['fields'])
status,blocked=request('/wp-admin/admin.php?page=wkel_all_leads',{'_wpnonce':nonce,'action':'resend_email','lead[]':lead_id})
check('Bulk sending is disabled' in blocked, 'forged bulk resend rejected by backend')
_,insights=request('/wp-admin/admin.php?page=wkel_insights')
check('Not configured' in insights and '356866720' not in insights and 'wkdigital.com.au' not in insights,'Insights has no misleading static properties')
print(f'RESULT: {checks} HTTP checks passed; localhost only and delivery guard active.')
