// Reads an iTerm2 .itermcolors file (an XML plist) into the four colors of the Custom theme.
// A regular expression reads the plist, so a file pasted into a one-line field (line breaks
// removed) also works, and node:test can run it without a DOM.

const toHex = ({ red, green, blue }) => `#${[red, green, blue]
    .map((channel) => Math.round(Math.min(1, Math.max(0, channel)) * 255).toString(16).padStart(2, '0'))
    .join('')}`;

const mix = (from, to, amount) => ({
    red: from.red + (to.red - from.red) * amount,
    green: from.green + (to.green - from.green) * amount,
    blue: from.blue + (to.blue - from.blue) * amount,
});

// Rough relative luminance, only to know which of two colors is darker.
const luminance = ({ red, green, blue }) => 0.2126 * red + 0.7152 * green + 0.0722 * blue;

const BLACK = { red: 0, green: 0, blue: 0 };

/**
 * @param {string} xml The content of an .itermcolors file.
 * @returns {{sidebar: string, selected: string, accent: string, background: string} | null}
 *   null when the text has no "Background Color" and "Foreground Color".
 */
export function parseItermColors(xml) {
    const colors = {};
    for (const [, name, body] of String(xml ?? '').matchAll(/<key>\s*([^<]+?)\s*<\/key>\s*<dict>([\s\S]*?)<\/dict>/g)) {
        const component = (channel) => {
            const match = body.match(new RegExp(`<key>\\s*${channel} Component\\s*</key>\\s*<(?:real|integer)>\\s*([^<]+?)\\s*</(?:real|integer)>`));
            const value = match ? Number(match[1]) : NaN;
            return Number.isFinite(value) ? value : null;
        };
        const color = { red: component('Red'), green: component('Green'), blue: component('Blue') };
        if (Object.values(color).every((value) => value !== null)) {
            colors[name] = color;
        }
    }

    const background = colors['Background Color'];
    const foreground = colors['Foreground Color'];
    if (!background || !foreground) {
        return null;
    }
    const isDark = luminance(background) <= luminance(foreground);

    return {
        // A darker shade of the background: toward black on a dark theme, a little toward the text on a light one.
        sidebar: toHex(isDark ? mix(background, BLACK, 0.25) : mix(background, foreground, 0.06)),
        selected: toHex(colors['Selection Color'] ?? mix(background, foreground, 0.16)),
        accent: toHex(colors['Ansi 4 Color'] ?? colors['Ansi 12 Color'] ?? foreground),
        background: toHex(background),
    };
}
