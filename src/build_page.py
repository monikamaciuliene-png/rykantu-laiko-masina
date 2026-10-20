"""Builds page.html from src/: template.html + items.js + datasets.js + climate.json + pl.json, image aspect ratios from img/."""
import json, os, pathlib, subprocess
here = pathlib.Path(__file__).resolve().parent; root = here.parent
t = (here / "template.html").read_text(encoding="utf8")
ar = {}
for f in sorted(os.listdir(root / "img")):
    if not f.endswith(".jpg"): continue
    out = subprocess.run(["sips", "-g", "pixelWidth", "-g", "pixelHeight", str(root / "img" / f)], capture_output=True, text=True).stdout
    w = int(out.split("pixelWidth:")[1].split()[0]); h = int(out.split("pixelHeight:")[1].split()[0])
    ar["img/" + f] = round(w / h, 3)
pl = json.loads((here / "pl.json").read_text(encoding="utf8"))
t = t.replace("/*__CLIMATE__*/[]", (here / "climate.json").read_text().strip())
t = t.replace("/*__AR__*/{}", json.dumps(ar))
t = t.replace("/*__PL__*/{}", json.dumps(pl, ensure_ascii=False, separators=(",", ":")))
t = t.replace("/*__DATASETS__*/", (here / "datasets.js").read_text(encoding="utf8").rstrip("\n"))
t = t.replace("/*__ITEMS__*/", (here / "items.js").read_text(encoding="utf8").rstrip("\n"))
(root / "page.html").write_text(t, encoding="utf8")
print("page.html built", len(t))
