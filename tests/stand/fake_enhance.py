"""Fake Enhance API for the local stand: in-memory state, HTTPS, request log.

Control endpoints (not part of Enhance):
  POST /__reset {master}            start over with an empty master organisation
  POST /__seed  {...}               add customers, logins, members, subscriptions
  POST /__fail  {method, path, status, times}
                                    answer the next <times> matching requests with <status>
  POST /__drop_subscription {org, sub}
                                    remove a subscription (Enhance answers 404 afterwards)
  POST /__suspend_subscription {org, sub, suspended}
  GET  /__state                     whole state, including the request log
"""
import json
import re
import ssl
import sys
import threading
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit

LOCK = threading.Lock()
STATE = {}


def reset(master):
    STATE.clear()
    STATE.update({
        'master': master,
        'orgs': {master: {'id': master, 'name': 'Master', 'parentId': None, 'ownerId': '', 'isSuspended': False}},
        'members': {master: []},
        'logins': [],
        'subs': {},
        'websites': {},
        'next_sub': 100,
        'failures': [],
        'requests': [],
        'recoveries': [],
    })


reset('00000000-0000-4000-8000-000000000001')


def new_id():
    return str(uuid.uuid4())


def owner_email(org):
    owner = org.get('ownerId') or ''
    for member in STATE['members'].get(org['id'], []):
        if member['id'] == owner:
            for login in STATE['logins']:
                if login['id'] == member['loginId']:
                    return login['email']
    return ''


def org_view(org):
    view = dict(org)
    view['ownerEmail'] = owner_email(org)
    view['status'] = 'active'
    return view


class Handler(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def log_message(self, *args):
        pass

    def reply(self, status, body=None):
        raw = b'' if body is None else json.dumps(body).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def body(self):
        length = int(self.headers.get('Content-Length') or 0)
        raw = self.rfile.read(length) if length else b''
        try:
            return json.loads(raw) if raw else {}
        except ValueError:
            return {}

    def do_GET(self):
        self.route('GET')

    def do_POST(self):
        self.route('POST')

    def do_PUT(self):
        self.route('PUT')

    def do_PATCH(self):
        self.route('PATCH')

    def do_DELETE(self):
        self.route('DELETE')

    def route(self, method):
        url = urlsplit(self.path)
        query = {k: v[0] for k, v in parse_qs(url.query).items()}
        body = self.body()
        with LOCK:
            status, payload = self.dispatch(method, url.path, query, body)
        self.reply(status, payload)

    def dispatch(self, method, path, query, body):
        s = STATE
        if path.startswith('/__'):
            return self.control(method, path, body)
        s['requests'].append({'method': method, 'path': path, 'query': query, 'body': body})
        for fail in s['failures']:
            if fail['times'] > 0 and fail['method'] == method and re.fullmatch(fail['path'], path):
                fail['times'] -= 1
                return fail['status'], {'code': 'injected', 'message': 'injected failure'}
        if not path.startswith('/api/'):
            return 404, {'code': 'not_found'}
        p = path[4:]
        m = s['master']

        if method == 'GET' and p == '/version':
            return 200, '12.26.3'
        if method == 'GET' and p == '/servers/groups':
            return 200, {'items': [], 'total': 0}

        # logins
        if p == '/logins' and method == 'GET':
            realm = query.get('realmId')
            items = [l for l in s['logins'] if realm is None or l['realmId'] == realm]
            return 200, page(items, query)
        if p == '/logins' and method == 'POST':
            if any(l['email'].lower() == body.get('email', '').lower() and l['realmId'] == m for l in s['logins']):
                return 409, {'code': 'conflict', 'message': 'login exists'}
            login = {'id': new_id(), 'email': body['email'], 'realmId': m, 'name': body.get('name', ''), 'password': body.get('password', '')}
            s['logins'].append(login)
            return 201, {'id': login['id']}
        if p == '/login/password-recovery' and method == 'PUT':
            s['recoveries'].append(body.get('email', ''))
            return 200, None

        # customers of the master organisation
        mm = re.fullmatch(r'/orgs/([^/]+)/customers', p)
        if mm and mm.group(1) == m:
            if method == 'GET':
                items = [org_view(o) for o in s['orgs'].values() if o.get('parentId') == m]
                return 200, page(items, query)
            if method == 'POST':
                org = {'id': new_id(), 'name': body.get('name', ''), 'parentId': m, 'ownerId': '', 'isSuspended': False}
                s['orgs'][org['id']] = org
                s['members'][org['id']] = []
                return 201, {'id': org['id']}
        mm = re.fullmatch(r'/orgs/([^/]+)/customers/([^/]+)/subscriptions', p)
        if mm and mm.group(1) == m:
            org_id = mm.group(2)
            if org_id not in s['orgs']:
                return 404, {'code': 'not_found'}
            if method == 'GET':
                return 200, page(list(s['subs'].get(org_id, {}).values()), query)
            if method == 'POST':
                s['next_sub'] += 1
                sub = {'id': s['next_sub'], 'planId': int(body.get('planId', 0)), 'subscriberId': org_id,
                       'friendlyName': body.get('friendlyName', ''), 'status': 'active', 'isSuspended': False}
                s['subs'].setdefault(org_id, {})[str(sub['id'])] = sub
                return 201, {'id': sub['id']}

        # organisations
        mm = re.fullmatch(r'/orgs/([^/]+)', p)
        if mm:
            org = s['orgs'].get(mm.group(1))
            if not org:
                return 404, {'code': 'not_found'}
            if method == 'GET':
                return 200, org_view(org)
            if method == 'PATCH':
                for key in ('name', 'isSuspended'):
                    if key in body:
                        org[key] = body[key]
                return 200, None
        mm = re.fullmatch(r'/orgs/([^/]+)/owner', p)
        if mm and method == 'PUT':
            s['orgs'][mm.group(1)]['ownerId'] = body.get('memberId', '')
            return 200, None
        mm = re.fullmatch(r'/orgs/([^/]+)/members', p)
        if mm:
            org_id = mm.group(1)
            if org_id not in s['orgs']:
                return 404, {'code': 'not_found'}
            if method == 'GET':
                return 200, page(s['members'][org_id], query)
            if method == 'POST':
                member = {'id': new_id(), 'loginId': body.get('loginId', ''), 'roles': body.get('roles', [])}
                s['members'][org_id].append(member)
                if 'Owner' in member['roles'] and not s['orgs'][org_id].get('ownerId'):
                    s['orgs'][org_id]['ownerId'] = member['id']
                return 201, {'id': member['id']}
        mm = re.fullmatch(r'/orgs/([^/]+)/members/([^/]+)', p)
        if mm:
            members = s['members'].get(mm.group(1), [])
            member = next((x for x in members if x['id'] == mm.group(2)), None)
            if not member:
                return 404, {'code': 'not_found'}
            if method == 'GET':
                return 200, member
            if method == 'DELETE':
                members.remove(member)
                return 200, None
        mm = re.fullmatch(r'/orgs/([^/]+)/members/([^/]+)/sso', p)
        if mm and method == 'GET':
            return 200, 'https://panel.example.test/sso/' + mm.group(2)

        # subscriptions of a customer
        mm = re.fullmatch(r'/orgs/([^/]+)/subscriptions/([^/]+)', p)
        if mm:
            sub = s['subs'].get(mm.group(1), {}).get(mm.group(2))
            if not sub:
                return 404, {'code': 'not_found'}
            if method == 'GET':
                return 200, sub
            if method == 'PATCH':
                for key in ('planId', 'isSuspended'):
                    if key in body:
                        sub[key] = body[key]
                return 200, None
            if method == 'DELETE':
                if query.get('force') == 'true':
                    del s['subs'][mm.group(1)][mm.group(2)]
                else:
                    sub['status'] = 'deleted'
                return 200, None

        # websites
        mm = re.fullmatch(r'/orgs/([^/]+)/websites', p)
        if mm:
            sites = s['websites'].setdefault(mm.group(1), [])
            if method == 'GET':
                sub = query.get('subscriptionId')
                return 200, page([w for w in sites if sub is None or str(w['subscriptionId']) == sub], query)
            if method == 'POST':
                site = {'id': new_id(), 'subscriptionId': int(body.get('subscriptionId', 0)),
                        'domain': {'domain': body.get('domain', '')}}
                sites.append(site)
                return 201, {'id': site['id']}
        mm = re.fullmatch(r'/orgs/([^/]+)/websites/([^/]+)', p)
        if mm and method == 'DELETE':
            sites = s['websites'].get(mm.group(1), [])
            s['websites'][mm.group(1)] = [w for w in sites if w['id'] != mm.group(2)]
            return 200, None

        return 404, {'code': 'not_found', 'message': 'not handled by the fake: ' + method + ' ' + p}

    def control(self, method, path, body):
        s = STATE
        if path == '/__reset':
            reset(body.get('master', s['master']))
            return 200, {'ok': True}
        if path == '/__state':
            return 200, s
        if path == '/__fail':
            s['failures'].append({'method': body['method'], 'path': body['path'], 'status': body.get('status', 500), 'times': body.get('times', 1)})
            return 200, {'ok': True}
        if path == '/__drop_subscription':
            s['subs'].get(body['org'], {}).pop(str(body['sub']), None)
            return 200, {'ok': True}
        if path == '/__suspend_subscription':
            s['subs'][body['org']][str(body['sub'])]['isSuspended'] = bool(body.get('suspended', True))
            return 200, {'ok': True}
        if path == '/__seed':
            for org in body.get('customers', []):
                org = {'parentId': s['master'], 'ownerId': '', 'isSuspended': False, **org}
                s['orgs'][org['id']] = org
                s['members'].setdefault(org['id'], [])
            for login in body.get('logins', []):
                s['logins'].append({'realmId': s['master'], 'name': '', 'password': '', **login})
            for org_id, members in body.get('members', {}).items():
                s['members'].setdefault(org_id, []).extend(members)
            for org_id, subs in body.get('subscriptions', {}).items():
                for sub in subs:
                    sub = {'subscriberId': org_id, 'status': 'active', 'isSuspended': False, 'friendlyName': '', **sub}
                    s['subs'].setdefault(org_id, {})[str(sub['id'])] = sub
            return 200, {'ok': True}
        return 404, {'code': 'not_found'}


def page(items, query):
    offset = int(query.get('offset', 0))
    limit = int(query.get('limit', 100))
    return {'items': items[offset:offset + limit], 'total': len(items)}


def main():
    port, cert, key = int(sys.argv[1]), sys.argv[2], sys.argv[3]
    server = ThreadingHTTPServer(('127.0.0.1', port), Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(cert, key)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    print('ready', flush=True)
    server.serve_forever()


if __name__ == '__main__':
    main()
