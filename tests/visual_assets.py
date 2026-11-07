"""Check SVG links, local template assets and text contrast in both palettes."""
from pathlib import Path
import re
import json
import subprocess
import xml.etree.ElementTree as ET

root=Path(__file__).resolve().parents[1]
theme=root/'app/styles/uii_modern/theme'
source=root/'app/ext/uii/portal/styles/all/template/visuals.html'
sprite=ET.fromstring(source.read_text())
ids={node.get('id') for node in sprite.iter() if node.get('id')}
assert len(ids)==len([node for node in sprite.iter() if node.get('id')]), 'Duplicate SVG ids'
for directory in [root/'app/styles/uii_modern/template',root/'app/ext/uii/portal/styles/all/template']:
    for path in directory.rglob('*.html'):
        text=path.read_text()
        for target in re.findall(r'<use href="#([^"]+)"',text):
            if target=='icon-{nav_groups.items.ICON}':
                catalog=json.loads(subprocess.check_output(['bash','scripts/php-local.sh','-r',"require 'app/ext/uii/portal/service/navigation.php'; echo json_encode(\\uii\\portal\\service\\navigation::catalog());"],cwd=root,text=True))
                assert all('icon-'+item[1] in ids for item in catalog.values()),'Unknown catalog icon'
            else:
                assert target in ids,(path,target)
        for filename in re.findall(r'\{T_THEME_PATH\}/([\w./-]+)',text):
            assert (theme/filename).is_file(),(path,filename)
for filename in ['portal.css',str(root/'app/ext/uii/portal/styles/all/theme/acp.css')]:
    text=(theme/filename).read_text()
    assert text.count('{')==text.count('}'), 'Unbalanced CSS '+filename
print('PASS SVG symbols, internal references, local asset paths and CSS blocks')
css=(theme/'portal.css').read_text().split('/* Shared palette and vector system:')[1]
def luminance(color):
    rgb=[int(color[i:i+2],16)/255 for i in (1,3,5)]
    rgb=[n/12.92 if n<=.04045 else ((n+.055)/1.055)**2.4 for n in rgb]
    return sum(n*w for n,w in zip(rgb,[.2126,.7152,.0722]))
def contrast(a,b):
    a,b=sorted([luminance(a),luminance(b)])
    return (b+.05)/(a+.05)
for theme_name,pattern in [('light',r':root\s*\{([^}]+)\}'),('dark',r'html\[data-theme=dark\]\s*\{([^}]+)\}')]:
    tokens=dict(re.findall(r'(--[\w-]+):([^;]+);',re.search(pattern,css)[1]))
    ratios=[]
    for foreground in ['--text','--muted','--accent']:
        for background in ['--bg','--surface','--accent-soft']:
            ratio=contrast(tokens[foreground],tokens[background]);ratios.append(ratio)
            assert ratio>=4.5,(theme_name,foreground,background,ratio)
    assert contrast(tokens['--on-accent'],tokens['--accent'])>=4.5
    print(f'PASS {theme_name} text contrast: min {min(ratios):.2f}:1')
