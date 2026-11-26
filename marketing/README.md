# Marketing

Material for Coolify release announcements.

| Path | Content |
| --- | --- |
| `release-video/` | Scripts that make a release update video, and `PROMPT.md`, the instructions for an AI agent |
| `releases/<version>/video.json` | The spec of the video of a release: screenshots, slides, highlight boxes |
| `releases/<version>/contact-sheet.jpg` | All slides of the video in one image. Use it to check an older video without the video file |

Videos (`*.mp4`) and the `build/` directories are not in git (see the root `.gitignore`), because they make the repository large. To get the video of an older release again, run the three scripts with its `video.json` (see `release-video/PROMPT.md`).

To make the video of a new release, give an AI agent this prompt:

> Read `marketing/release-video/PROMPT.md` and make the release update video for v4.8.
