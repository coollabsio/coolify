// Renders 1920x1080 slide PNGs from a release spec and the screenshots of shoot.mjs.
// Usage: node marketing/release-video/slides.mjs <spec.json>
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const specPath = path.resolve(process.argv[2]);
const spec = JSON.parse(fs.readFileSync(specPath, 'utf8'));
const build = path.join(path.dirname(specPath), 'build');
const shots = path.join(build, 'shots');
const out = path.join(build, 'slides');
fs.rmSync(out, { recursive: true, force: true });
fs.mkdirSync(out, { recursive: true });

const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const font = fs.readFileSync(path.join(repo, 'resources/fonts/geist-sans-variable.woff2')).toString('base64');
const v = spec.version;

const css = `@font-face{font-family:Geist;src:url(data:font/woff2;base64,${font}) format('woff2');font-weight:100 900}
*{margin:0;box-sizing:border-box}
body{width:1920px;height:1080px;background:radial-gradient(1200px 700px at 80% -10%,#3b1d75 0%,#0b0b10 55%);font-family:Geist,sans-serif;color:#fff;overflow:hidden}
.k{display:inline-block;font-size:24px;font-weight:600;color:#c4a6ff;background:#6b16ed33;border:1px solid #8b5cf680;padding:6px 16px;border-radius:999px;letter-spacing:.5px}
h1{font-size:58px;font-weight:800;letter-spacing:-0.5px;margin-top:16px}
p.s{font-size:30px;color:#c9c9d6;margin-top:12px;line-height:1.35;max-width:1700px}
.wrap{position:absolute;left:50%;transform:translateX(-50%);top:222px;width:1600px;height:830px;border-radius:18px;overflow:hidden;border:1px solid #ffffff22;box-shadow:0 30px 80px #000c}
.wrap img{position:absolute;display:block;max-width:none}
.box{position:absolute;border:4px solid #fbbf24;border-radius:10px;box-shadow:0 0 0 9999px #00000070,0 0 30px #fbbf2499}
.head{position:absolute;left:100px;top:56px;right:100px}
.brand{position:absolute;right:60px;top:40px;font-size:24px;color:#ffffff88;font-weight:700}
.c{display:flex;flex-direction:column;justify-content:center;height:100%;padding:0 160px}
ul{margin-top:40px;font-size:36px;line-height:1.75;color:#e4e4ee;list-style:none}
li:before{content:"›  ";color:#a78bfa;font-weight:800}`;

// Screenshot coordinates (box) use a 1920x1080 basis, whatever the PNG resolution is.
// The main sidebar ends at x=272 in that basis; the crop always hides it.
const W = 1600, H = 830, SIDEBAR = 272;
function featureSlide(s) {
  const img = 'data:image/png;base64,' + fs.readFileSync(path.join(shots, s.shot + '.png')).toString('base64');
  const f = W / 1920;
  const [x, y, w, h] = s.box;
  const z = Math.max(1.17, Math.min(1.7, (W * 0.9) / (w * f), (H * 0.75) / (h * f)));
  const sc = f * z;
  let ox = Math.max(0, Math.min(1920 * sc - W, (x + w / 2) * sc - W / 2));
  ox = Math.min(Math.max(ox, SIDEBAR * sc), 1920 * sc - W);
  const oy = Math.max(0, Math.min(1080 * sc - H, (y + h / 2) * sc - H / 2 + (s.shiftDown ?? 0)));
  return `<div class="brand">Coolify ${v}</div>
<div class="head"><div class="k">${s.tag}</div><h1>${s.title}</h1></div>
<div class="wrap"><img src="${img}" style="width:${1920 * sc}px;height:${1080 * sc}px;left:${-ox}px;top:${-oy}px">
<div class="box" style="left:${x * sc - ox - 6}px;top:${y * sc - oy - 6}px;width:${w * sc + 12}px;height:${h * sc + 12}px"></div></div>`;
}

function slideBody(s) {
  if (s.type === 'title') {
    return `<div class="c"><div class="k" style="width:fit-content">Product update</div>
<h1 style="font-size:120px;margin-top:30px">Coolify ${v}</h1><p class="s" style="font-size:44px;margin-top:24px">${s.subtitle}</p></div>`;
  }
  if (s.type === 'list') {
    return `<div class="c"><div class="k" style="width:fit-content">${s.tag}</div><ul>${s.items.map((i) => `<li>${i}</li>`).join('')}</ul></div>`;
  }
  if (s.type === 'outro') {
    return `<div class="c" style="align-items:center;text-align:center"><h1 style="font-size:96px">${s.title}</h1>
<p class="s" style="font-size:40px;margin-top:28px">${s.lines.join('<br>')}</p>
<p class="s" style="margin-top:60px;font-size:34px;color:#ffffff99">coolify.io</p></div>`;
  }
  return featureSlide(s);
}

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1920, height: 1080 } });
for (const [i, s] of spec.slides.entries()) {
  await page.setContent(`<html><head><style>${css}</style></head><body>${slideBody(s)}</body></html>`);
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(300);
  await page.screenshot({ path: path.join(out, String(i).padStart(2, '0') + '.png') });
}
await browser.close();
console.log(spec.slides.length, 'slides in', out);
