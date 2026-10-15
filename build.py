"""Builds index.html for GitHub Pages from page.html (the same page body the claude.ai artifact uses)."""
import pathlib
here = pathlib.Path(__file__).parent
src = (here / "page.html").read_text(encoding="utf-8")
cut = src.index("</style>") + len("</style>")
head, body = src[:cut], src[cut:]
head = head.replace('<meta name="claude:viewport-fit-sides" content="cover">\n', "")
html = f"""<!doctype html>
<html lang="lt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="description" content="Rykantų laiko mašina: Rykantų paveldas nuo 1619 m. iki šių dienų. Archyvų dokumentai, tekstai, gyventojų balsai ir renginiai.">
<meta property="og:title" content="Rykantų laiko mašina">
<meta property="og:description" content="Rykantų paveldas nuo 1619 m. iki šių dienų. Rykantų pašto, Piliečių mokslo asociacijos, VILNIUS TECH ir Participio projektas.">
<style>img{{max-width:100%}}[hidden]{{display:none!important}}</style>
{head}
</head>
<body>
{body.strip()}
</body>
</html>
"""
(here / "index.html").write_text(html, encoding="utf-8")
print("index.html built")
