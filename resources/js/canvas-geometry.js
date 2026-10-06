/**
 * Geometry helpers shared by the cluster firewall canvas and the dashboard map.
 * Rectangles are `{ x, y, width, height }` in canvas coordinates.
 */

/**
 * Returns the points where the line between two rectangle centers leaves the
 * source and enters the destination, so lines touch the closest sides.
 */
export function closestRectConnectionPoints(source, destination) {
    const sourceCenter = {
        x: source.x + source.width / 2,
        y: source.y + source.height / 2,
    };
    const destinationCenter = {
        x: destination.x + destination.width / 2,
        y: destination.y + destination.height / 2,
    };
    const delta = {
        x: destinationCenter.x - sourceCenter.x,
        y: destinationCenter.y - sourceCenter.y,
    };

    if (delta.x === 0 && delta.y === 0) {
        return {
            x1: source.x + source.width,
            y1: sourceCenter.y,
            x2: destination.x,
            y2: destinationCenter.y,
        };
    }

    const sourceScale = 1 / Math.max(
        Math.abs(delta.x) / (source.width / 2),
        Math.abs(delta.y) / (source.height / 2),
    );
    const destinationScale = 1 / Math.max(
        Math.abs(delta.x) / (destination.width / 2),
        Math.abs(delta.y) / (destination.height / 2),
    );

    return {
        x1: sourceCenter.x + delta.x * sourceScale,
        y1: sourceCenter.y + delta.y * sourceScale,
        x2: destinationCenter.x - delta.x * destinationScale,
        y2: destinationCenter.y - delta.y * destinationScale,
    };
}

/**
 * Places a popover next to its anchor while keeping it inside the scrolled viewport.
 */
export function anchoredPopoverPosition(anchor, viewport, size) {
    const gap = 12;
    const minimumLeft = viewport.scrollLeft + gap;
    const maximumLeft = viewport.scrollLeft + viewport.width - size.width - gap;
    const minimumTop = viewport.scrollTop + gap;
    const maximumTop = viewport.scrollTop + viewport.height - size.height - gap;
    const preferredLeft = anchor.x + gap + size.width <= viewport.scrollLeft + viewport.width
        ? anchor.x + gap
        : anchor.x - size.width - gap;
    const preferredTop = anchor.y + gap + size.height <= viewport.scrollTop + viewport.height
        ? anchor.y + gap
        : anchor.y - size.height - gap;

    return {
        left: Math.max(minimumLeft, Math.min(preferredLeft, Math.max(minimumLeft, maximumLeft))),
        top: Math.max(minimumTop, Math.min(preferredTop, Math.max(minimumTop, maximumTop))),
    };
}

/**
 * Returns the zoom level that shows content of the given size inside the viewport,
 * rounded down to whole percents and kept between the minimum and maximum.
 */
export function fitZoomToViewport(size, viewport, { minimum = 0.5, maximum = 1 } = {}) {
    if (!size.width || !size.height || !viewport.width || !viewport.height) {
        return maximum;
    }

    const zoom = Math.min(maximum, viewport.width / size.width, viewport.height / size.height);

    return Math.max(minimum, Math.floor(zoom * 100) / 100);
}

/*
 * Path commands: `{ command: 'M' | 'L' | 'Q' | 'C', values: number[] }` with
 * absolute coordinates, so a route can be translated, sampled, and rendered.
 */

function roundCoordinate(value) {
    return Math.round(value * 100) / 100;
}

/** SVG path data for a list of path commands. */
export function pathCommandsToString(commands) {
    return commands
        .map(({ command, values }) => `${command} ${values.map(roundCoordinate).join(' ')}`)
        .join(' ');
}

/** Moves every point of a path by `dx`, `dy`. */
export function translatePathCommands(commands, dx, dy) {
    return commands.map(({ command, values }) => ({
        command,
        values: values.map((value, index) => value + (index % 2 ? dy : dx)),
    }));
}

/**
 * Connects two ports with horizontal tangents at both ends: a straight line
 * when they are level (less than 1px apart), otherwise a cubic Bezier curve
 * with its control points halfway across.
 */
export function horizontalConnectorCommands(from, to) {
    if (Math.abs(to.y - from.y) < 1) {
        return [{ command: 'L', values: [to.x, to.y] }];
    }
    const half = (to.x - from.x) / 2;

    return [{ command: 'C', values: [from.x + half, from.y, to.x - half, to.y, to.x, to.y] }];
}

/** SVG path data for `horizontalConnectorCommands`. */
export function bezierPath(from, to) {
    return pathCommandsToString([{ command: 'M', values: [from.x, from.y] }, ...horizontalConnectorCommands(from, to)]);
}

/**
 * Path commands for an orthogonal polyline whose corners are rounded with
 * quadratic arcs. The radius shrinks on short segments.
 */
export function roundedPolylineCommands(points, radius) {
    if (points.length < 2) {
        return [];
    }
    const towards = (from, to, distance) => {
        const length = Math.hypot(to.x - from.x, to.y - from.y) || 1;

        return { x: from.x + ((to.x - from.x) / length) * distance, y: from.y + ((to.y - from.y) / length) * distance };
    };
    const commands = [{ command: 'M', values: [points[0].x, points[0].y] }];
    for (let index = 1; index < points.length - 1; index++) {
        const previous = points[index - 1];
        const corner = points[index];
        const next = points[index + 1];
        const before = Math.min(radius, Math.hypot(corner.x - previous.x, corner.y - previous.y) / 2);
        const after = Math.min(radius, Math.hypot(next.x - corner.x, next.y - corner.y) / 2);
        const start = towards(corner, previous, before);
        const end = towards(corner, next, after);
        commands.push(
            { command: 'L', values: [start.x, start.y] },
            { command: 'Q', values: [corner.x, corner.y, end.x, end.y] },
        );
    }
    const last = points[points.length - 1];
    commands.push({ command: 'L', values: [last.x, last.y] });

    return commands;
}

/**
 * Samples a path: every segment is split into `steps` parts. A move starts a
 * new subpath; its point is included so the samples keep both ends.
 */
export function samplePathCommands(commands, steps = 16) {
    const points = [];
    let current = { x: 0, y: 0 };
    for (const { command, values } of commands) {
        if (command === 'M') {
            current = { x: values[0], y: values[1] };
            points.push({ ...current, move: true });
            continue;
        }
        const start = current;
        for (let step = 1; step <= steps; step++) {
            const t = step / steps;
            const u = 1 - t;
            if (command === 'L') {
                points.push({ x: start.x + (values[0] - start.x) * t, y: start.y + (values[1] - start.y) * t });
            } else if (command === 'Q') {
                points.push({
                    x: u * u * start.x + 2 * u * t * values[0] + t * t * values[2],
                    y: u * u * start.y + 2 * u * t * values[1] + t * t * values[3],
                });
            } else {
                points.push({
                    x: u ** 3 * start.x + 3 * u * u * t * values[0] + 3 * u * t * t * values[2] + t ** 3 * values[4],
                    y: u ** 3 * start.y + 3 * u * u * t * values[1] + 3 * u * t * t * values[3] + t ** 3 * values[5],
                });
            }
        }
        current = { x: values[values.length - 2], y: values[values.length - 1] };
    }

    return points;
}

/** Distance from a point to a rectangle, 0 when the point is inside or on it. */
export function distanceToRect(point, rect) {
    const dx = Math.max(rect.x - point.x, 0, point.x - (rect.x + rect.width));
    const dy = Math.max(rect.y - point.y, 0, point.y - (rect.y + rect.height));

    return Math.hypot(dx, dy);
}
