"""Plans (and with --apply performs, via git mv) the file / folder moves of the refactor."""
import json, os, re, subprocess, sys
here = os.path.dirname(os.path.abspath(__file__)); root = os.path.dirname(os.path.dirname(here))
d = json.load(open(os.path.join(here, 'terminology.json')))
C, NS, P, F = d['classes'], d['namespaces'], d['pages'], d['folders']
def ns_or_class(w): return C.get(w, NS.get(w, w))
def php_path(rel):
    parts = rel.split('/'); out = []
    for i, p in enumerate(parts):
        if i == len(parts) - 1:
            base, ext = os.path.splitext(p); out.append(C.get(base, base) + ext)
        elif i >= 1:  # keep the root folder (app, tests, database/...)
            out.append(NS.get(p, C.get(p, p)))
        else:
            out.append(p)
    return '/'.join(out)
def js_piece(seg):
    m = re.match(r'^([^.]+)(\..*)?$', seg); base, ext = m.group(1), m.group(2) or ''
    return (ns_or_class(base) if base[:1].isupper() else F.get(base, base)) + ext
def js_path(rel):
    if rel.startswith('resources/js/pages/'):
        name = rel[len('resources/js/pages/'):-len('.tsx')] if rel.endswith('.tsx') else None
        if name in P: return 'resources/js/pages/' + P[name] + '.tsx'
    parts = rel.split('/')
    return '/'.join(parts[:2] + [js_piece(p) for p in parts[2:]])
moves = []
for top in ['app', 'tests', 'database/seeders']:
    for dp, dn, fn in os.walk(os.path.join(root, top)):
        for f in fn:
            if f.endswith('.php'):
                rel = os.path.relpath(os.path.join(dp, f), root)
                new = php_path(rel)
                if new != rel: moves.append((rel, new))
for dp, dn, fn in os.walk(os.path.join(root, 'resources/js')):
    for f in fn:
        rel = os.path.relpath(os.path.join(dp, f), root); new = js_path(rel)
        if new != rel: moves.append((rel, new))
if '--apply' in sys.argv:
    for a, b in moves:
        os.makedirs(os.path.join(root, os.path.dirname(b)), exist_ok=True)
        subprocess.run(['git', 'mv', a, b], cwd=root, check=True)
    # remove now-empty folders
    for top in ['app', 'tests', 'database/seeders', 'resources/js']:
        for dp, dn, fn in sorted(os.walk(os.path.join(root, top)), key=lambda x: -len(x[0])):
            if not os.listdir(dp): os.rmdir(dp)
for a, b in moves: print(f'{a}  ->  {b}')
print(len(moves), 'moves')
