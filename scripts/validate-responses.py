#!/usr/bin/env python3
"""
Calls the GET operations of Luna's generated OpenAPI document against a running server (default: the contract test server on :8766 with
the seed loaded, see CLAUDE.md) and validates each response body against the schema the document declares for it. Reports every place where
the document does not describe what the API actually returns.

  python3 scripts/validate-responses.py [base url] [path to api-docs.json]
"""
import json, re, sys, urllib.request, urllib.error

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8766'
DOC = json.load(open(sys.argv[2] if len(sys.argv) > 2 else 'storage/api-docs/api-docs.json'))
SCHEMAS = DOC['components']['schemas']


def tokens():
    out = {}
    for name, uid in (('staff', 9002), ('user', 9001), ('dev', 9007)):
        req = urllib.request.Request(f'{BASE}/test/login/{uid}', method='POST')
        out[name] = json.load(urllib.request.urlopen(req))['token']
    return out


def validate(value, schema, path, errors, depth=0, extra_ok=False):
    if depth > 30 or schema is None:
        return
    if '$ref' in schema:
        return validate(value, SCHEMAS[schema['$ref'].split('/')[-1]], path, errors, depth + 1, extra_ok)
    # Members of an allOf each describe part of the object, so they cannot forbid the properties of the other members
    for sub in schema.get('allOf', []):
        validate(value, sub, path, errors, depth + 1, extra_ok=True)
    if 'oneOf' in schema or 'anyOf' in schema:
        options = schema.get('oneOf') or schema.get('anyOf')
        if value is None and schema.get('nullable'):
            return
        results = []
        for option in options:
            e = []
            validate(value, option, path, e, depth + 1)
            results.append(e)
        if not any(not e for e in results):
            errors.append(f'{path}: matches none of the {len(options)} alternatives ({results[0][0] if results[0] else ""})')
        return
    if value is None:
        if not schema.get('nullable') and schema.get('type') is not None:
            errors.append(f'{path}: null but the schema is not nullable ({schema.get("type")})')
        return
    t = schema.get('type')
    py = {'string': str, 'integer': int, 'number': (int, float), 'boolean': bool, 'array': list, 'object': dict}
    if t in py:
        ok = isinstance(value, py[t]) and not (t in ('integer', 'number') and isinstance(value, bool))
        if t == 'integer' and isinstance(value, float) and value.is_integer():
            ok = True
        if not ok:
            errors.append(f'{path}: expected {t}, got {type(value).__name__} ({json.dumps(value)[:40]})')
            return
    if 'enum' in schema and value not in schema['enum']:
        errors.append(f'{path}: {json.dumps(value)[:40]} is not one of {schema["enum"]}')
    if t == 'object' or ('properties' in schema and isinstance(value, dict)):
        if not isinstance(value, dict):
            return
        props = schema.get('properties', {})
        for r in schema.get('required', []):
            if r not in value:
                errors.append(f'{path}: missing required property {r}')
        for k, v in value.items():
            if k in props:
                validate(v, props[k], f'{path}.{k}', errors, depth + 1)
            elif schema.get('additionalProperties') is False and not extra_ok:
                errors.append(f'{path}: unexpected property {k}')
            elif isinstance(schema.get('additionalProperties'), dict):
                validate(v, schema['additionalProperties'], f'{path}.{k}', errors, depth + 1)
    if t == 'array' and isinstance(value, list):
        for i, item in enumerate(value[:20]):
            validate(item, schema.get('items'), f'{path}[{i}]', errors, depth + 1)


# Values for path parameters that exist in the contract seed
PARAMS = {'id': '1', 'username': 'TestUser', 'key': 'dev_role_label', 'type': 'finished-posts', 'provider': 'deviantart', 'uuid': '', 'entryid': '1',
          'user_id': '9001', 'cutieMarkId': '900001', 'token_id': '1', 'appearance': '1', 'user': '9001', 'asset': 'x'}
QUERIES = {
    '/appearances': 'guide=pony', '/appearances/full': 'guide=pony', '/appearances/pinned': 'guide=pony', '/appearances/autocomplete': 'q=twi',
    '/color-guide/major-changes': 'guide=pony', '/show': 'types[]=episode&types[]=movie&order=series', '/posts': 'showId=1&kind=request',
    '/appearances/{id}/palette': 'format=json', '/appearances/{id}/image': 'type=preview&format=svg', '/user-prefs/me': '',
    '/users/{id}/contributions/{type}': '', '/users/{id}/preferences/{key}': '', '/tags/{id}': '',
}
SKIP = {'/about/sleep', '/sanctum/csrf-cookie', '/users/oauth/signin/{provider}', '/generated/api-docs.json', '/generated/api-docs.json/asset/{asset}',
        '/api/oauth2-callback', '/users/email/verify/{id}/{hash}', '/appearances/{id}/sprite'}


def main():
    tk = tokens()
    problems = 0
    checked = 0
    for path, item in sorted(DOC['paths'].items()):
        op = item.get('get')
        if not op or path in SKIP:
            continue
        url = path
        for name in re.findall(r'\{(\w+)\}', path):
            url = url.replace('{' + name + '}', PARAMS.get(name, '1'))
        q = QUERIES.get(path, '')
        for token_name in (None, 'staff', 'dev'):
            headers = {'Accept': 'application/json'}
            if token_name:
                headers['Authorization'] = 'Bearer ' + tk[token_name]
            req = urllib.request.Request(BASE + url + ('?' + q if q else ''), headers=headers)
            try:
                resp = urllib.request.urlopen(req)
                status, body, ctype = resp.status, resp.read(), resp.headers.get('Content-Type', '')
            except urllib.error.HTTPError as e:
                status, body, ctype = e.code, e.read(), e.headers.get('Content-Type', '')
            declared = op.get('responses', {}).get(str(status))
            label = f'GET {path} [{token_name or "guest"}] -> {status}'
            if declared is None:
                if status >= 500 or status == 422 and token_name is None:
                    print(f'{label}: status not documented')
                    problems += 1
                continue
            content = declared.get('content', {})
            if 'json' not in ctype or not content:
                continue
            schema = next((c.get('schema') for ct, c in content.items() if 'json' in ct), None)
            try:
                value = json.loads(body)
            except ValueError:
                print(f'{label}: body is not JSON')
                problems += 1
                continue
            errors = []
            validate(value, schema, '$', errors)
            checked += 1
            if errors:
                problems += len(errors)
                print(label)
                for e in errors[:8]:
                    print('   ', e)
                if len(errors) > 8:
                    print(f'    ... {len(errors) - 8} more')
            if status == 200 and token_name is None:
                break
    print(f'\nChecked {checked} responses, {problems} problems')
    return 1 if problems else 0


sys.exit(main())
