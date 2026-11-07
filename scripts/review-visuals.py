"""Render vector artwork in both actual portal palettes without a running database."""
from pathlib import Path
import re
import subprocess
import xml.etree.ElementTree as ET

root=Path(__file__).resolve().parents[1]
raw=(root/'app/ext/uii/portal/styles/all/template/visuals.html').read_text()
css=(root/'app/styles/uii_modern/theme/portal.css').read_text().split('/* Shared palette and vector system:')[1]
ns={'s':'http://www.w3.org/2000/svg'}
ET.register_namespace('',ns['s'])
art=['workspace','cases','tasks','team','admin','settings','journal','form']
icons=['home','tasks','file','clock','inspect','archive','team','account','settings','journal','search','arrow','moon','sun']
parts=['<svg xmlns="http://www.w3.org/2000/svg" width="1080" height="1040" viewBox="0 0 1080 1040">']
for row,theme in enumerate(['light','dark']):
    block=re.search(r':root\s*\{([^}]+)\}' if theme=='light' else r'html\[data-theme=dark\]\s*\{([^}]+)\}',css)[1]
    tokens=dict(re.findall(r'(--[\w-]+):([^;]+);',block))
    defs=ET.fromstring(raw).find('s:defs',ns)
    for element in defs.iter():
        if element.get('id'): element.set('id',theme+'-'+element.get('id'))
        if element.get('href','').startswith('#'): element.set('href','#'+theme+'-'+element.get('href')[1:])
        cls=element.get('class','')
        if cls:
            rules=re.search(r'\.'+re.escape(cls)+r'\{([^}]+)\}',css)
            if rules:
                style=re.sub(r'var\((--[\w-]+)\)',lambda match:tokens[match[1]],rules[1])
                element.set('style',style)
    parts.append(ET.tostring(defs,encoding='unicode'))
    y=row*520
    parts.append(f'<rect y="{y}" width="1080" height="520" fill="{tokens["--bg"]}"/>')
    for i,name in enumerate(art):
        x=20+(i%4)*265;cy=y+20+(i//4)*190
        parts.append(f'<rect x="{x}" y="{cy}" width="250" height="178" rx="16" fill="{tokens["--surface"]}"/><svg x="{x}" y="{cy}" width="250" height="140" viewBox="0 0 250 140"><use href="#{theme}-art-{name}"/></svg><text x="{x+18}" y="{cy+160}" fill="{tokens["--text"]}" font-family="sans-serif" font-size="14">{name}</text>')
    for i,name in enumerate(icons):
        parts.append(f'<svg x="{30+i*45}" y="{y+420}" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="{tokens["--text"]}" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><use href="#{theme}-icon-{name}"/></svg>')
parts.append('</svg>')
output=root/'runtime/visual-review.svg'
output.write_text(''.join(parts))
subprocess.run(['rsvg-convert',str(output),'-o',str(output.with_suffix('.png'))],check=True)
print(output.with_suffix('.png'))
