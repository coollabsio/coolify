# Make a release update video

Use these instructions to make a short product update video for a Coolify release. The video shows the main new features. Each feature gets one slide with a short title and a screenshot of the real UI. A yellow box marks the feature in the screenshot.

The v4.7 video is the reference: `marketing/releases/v4.7/video.json` and `marketing/releases/v4.7/contact-sheet.jpg`. Read both before you start.

## Requirements

- A running Coolify dev instance with the release code (see "Development Environment" in `AGENTS.md`). The main checkout serves `https://<host>:8000`.
- `ffmpeg` on the host.
- Playwright Chromium: `npm install && npx playwright install chromium`.

## Steps

1. Find the features.
   - Read the section of the release in `CHANGELOG.md`, or the `.changes/` files when the release is not out yet.
   - Select 8 to 12 features that a user can see in the UI. Prefer `Added` and the large `Changed` items. Put one breaking change that users must know in the outro.
   - Do not feature new one-click service templates. The maintainer removed the Orbit slide from v4.7.
   - Put features that you cannot show in a screenshot on the "Also in" list slide (6 items at most).

2. Prepare the dev instance.
   - Run the pending migrations: `./scripts/dev exec php artisan migrate --force`.
   - Make sure that the pages show real data, not empty states. For example, start a resource, run a scan, or create a record. Use the UI, or tinker as `www-data` (`./scripts/dev exec su -s /bin/sh www-data -c "php artisan tinker --execute '...'"`). Tinker as root makes root-owned SSH files, and then pages that use SSH show empty results.
   - Make sure that the servers show as reachable, because a red `Unavailable` status looks bad in a video.

3. Write the spec.
   - Copy `marketing/releases/v4.7/video.json` to `marketing/releases/<version>/video.json`. Change `version`, `baseUrl`, `shots`, and `slides`.
   - Find the URLs with `./scripts/dev exec php artisan route:list --except-vendor`. The UUIDs in the URLs come from the dev database.
   - Shot steps: `{"click": "text"}` (buttons first, then list options, then links), `{"clickSelector": "css"}`, `{"fill": ["css", "value"]}`, `{"scrollTo": "text"}`, `{"wait": ms}`.
   - Slide types: `title` (with `subtitle`), feature (with `tag`, `title`, `shot`, `box`), `list` (with `tag`, `items`), `outro` (with `title`, `lines`). Optional: `seconds`, and `shiftDown` (pixels) to move the crop down.
   - `box` is `[x, y, width, height]` in a 1920x1080 basis, whatever the size of the screenshot PNG. The slide zooms to the box and always hides the main sidebar (x < 272).

4. Take the screenshots: `node marketing/release-video/shoot.mjs marketing/releases/<version>/video.json [shot,shot]`.
   - The script logs in with `test@example.com` / `password`. Set `COOLIFY_URL`, `COOLIFY_EMAIL`, or `COOLIFY_PASSWORD` to use other values.
   - The script fails a shot on an HTTP error. Also look at each PNG in `build/shots/`: a page can show an error, an empty state, or a closed dialog with HTTP 200. If a page is broken, fix the bug or tell the user. Do not put a broken page in the video.
   - Some dialogs do not open in headless Chromium (for example, the private key edit dialog in v4.7). Put that feature on the list slide.

5. Render the slides: `node marketing/release-video/slides.mjs marketing/releases/<version>/video.json`.
   - Look at the slides in `build/slides/`. Adjust each `box` until it surrounds the feature and does not cut text. Render again.

6. Render the video: `node marketing/release-video/render.mjs marketing/releases/<version>/video.json`.
   - This writes `coolify-<version>-update.mp4` (not in git) and `contact-sheet.jpg` (in git).
   - Read `contact-sheet.jpg` for a last check. Give the user the path of the MP4.

7. Commit only `video.json` and `contact-sheet.jpg`. Never commit the MP4 or `build/`.

## Style rules (feedback from the maintainer)

- Readable for humans: one short title for each slide, no description lines. Long text is too much to read in 5 seconds.
- Use the Geist font of the app (`slides.mjs` embeds it). Fallback fonts make letters collide.
- Take screenshots at 2x scale (`shoot.mjs` does this). Low-resolution screenshots look blurry after the zoom.
- Do not show half of the sidebar. The crop in `slides.mjs` hides it.
- Use the dark theme, 1920x1080, about 5 seconds for each feature slide, 0.6-second fades, no audio. A full video is about 60 to 75 seconds.
