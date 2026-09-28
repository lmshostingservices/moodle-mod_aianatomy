import sys, subprocess, json, concurrent.futures, urllib.request
names = {}
for line in open('parts_list_e.txt'):
    p = line.rstrip('\n').split('\t')
    if len(p) == 2 and p[0].startswith(('FMA','BP')):
        names.setdefault(p[1].strip().lower(), p[0])
R='https://raw.githubusercontent.com/Kevin-Mattheus-Moerman/BodyParts3D/main/assets/BodyParts3D_data/stl/'
def head(fid):
    try:
        req = urllib.request.Request(R + fid + '.stl', method='HEAD')
        with urllib.request.urlopen(req, timeout=30) as r:
            return fid, int(r.headers.get('Content-Length', 0))
    except Exception as e:
        return fid, 0
def check(wanted):
    ids = {w: names.get(w.lower()) for w in wanted}
    with concurrent.futures.ThreadPoolExecutor(16) as ex:
        res = dict(ex.map(head, [i for i in ids.values() if i]))
    return {w: (i, res.get(i, 0) if i else None) for w, i in ids.items()}
if __name__ == '__main__':
    wanted = [l.strip() for l in open(sys.argv[1]) if l.strip()]
    for w, (i, sz) in check(wanted).items():
        print(f"{w}\t{i}\t{sz}")
