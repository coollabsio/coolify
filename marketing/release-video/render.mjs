// Joins the slides into an MP4 with fades, and writes a contact sheet for review.
// Usage: node marketing/release-video/render.mjs <spec.json>
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const specPath = path.resolve(process.argv[2]);
const spec = JSON.parse(fs.readFileSync(specPath, 'utf8'));
const dir = path.dirname(specPath);
const slides = path.join(dir, 'build', 'slides');
const durations = spec.slides.map((s) => s.seconds ?? { title: 4, list: 8, outro: 5 }[s.type] ?? 5);
const fade = 0.6;

const inputs = [];
const filters = [];
durations.forEach((t, i) => {
  inputs.push('-loop', '1', '-t', String(t), '-i', path.join(slides, String(i).padStart(2, '0') + '.png'));
  filters.push(`[${i}:v]format=yuv420p,fps=30,setsar=1[v${i}]`);
});
let prev = 'v0';
let offset = 0;
for (let i = 1; i < durations.length; i++) {
  offset += durations[i - 1] - fade;
  filters.push(`[${prev}][v${i}]xfade=transition=fade:duration=${fade}:offset=${offset.toFixed(2)}[x${i}]`);
  prev = `x${i}`;
}
const video = path.join(dir, `coolify-${spec.version}-update.mp4`);
execFileSync('ffmpeg', ['-loglevel', 'error', '-y', ...inputs, '-filter_complex', filters.join(';'), '-map', `[${prev}]`,
  '-c:v', 'libx264', '-crf', '18', '-preset', 'medium', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', video], { stdio: 'inherit' });

// Contact sheet: every slide in a 4-column grid, for a quick visual check.
const files = fs.readdirSync(slides).filter((f) => f.endsWith('.png')).sort();
const cols = 4;
const layout = files.map((_, i) => `${(i % cols) * 480}_${Math.floor(i / cols) * 270}`).join('|');
execFileSync('ffmpeg', ['-loglevel', 'error', '-y', ...files.flatMap((f) => ['-i', path.join(slides, f)]), '-filter_complex',
  files.map((_, i) => `[${i}:v]scale=480:270[s${i}]`).join(';') + ';' + files.map((_, i) => `[s${i}]`).join('') +
  `xstack=inputs=${files.length}:layout=${layout}:fill=black`, '-q:v', '4', path.join(dir, 'contact-sheet.jpg')], { stdio: 'inherit' });
console.log(video);
