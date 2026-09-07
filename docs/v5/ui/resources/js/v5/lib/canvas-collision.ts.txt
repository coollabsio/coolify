export type CanvasNodeBounds = {
    id: string;
    x: number;
    y: number;
    width: number;
    height: number;
};

export type CanvasNodePosition = {
    x: number;
    y: number;
};

export function resolveCanvasNodeLayout(nodes: CanvasNodeBounds[], gap: number): CanvasNodeBounds[] {
    return nodes.reduce<CanvasNodeBounds[]>((settledNodes, node) => {
        const position = resolveCanvasNodePosition(node, settledNodes, gap);

        return [...settledNodes, { ...node, ...position }];
    }, []);
}

export function resolveCanvasNodePosition(
    node: CanvasNodeBounds,
    nodes: CanvasNodeBounds[],
    gap: number,
): CanvasNodePosition {
    const otherNodes = nodes.filter((otherNode) => otherNode.id !== node.id);
    let position = { x: node.x, y: node.y };

    for (let attempt = 0; attempt < 50; attempt += 1) {
        const collision = otherNodes.find((otherNode) => canvasNodesOverlap({ ...node, ...position }, otherNode, gap));

        if (!collision) {
            return position;
        }

        position = closestCanvasNodePosition(node, collision, gap, otherNodes, position);
    }

    return position;
}

function closestCanvasNodePosition(
    node: CanvasNodeBounds,
    collision: CanvasNodeBounds,
    gap: number,
    otherNodes: CanvasNodeBounds[],
    targetPosition: CanvasNodePosition,
): CanvasNodePosition {
    const candidates = [
        { x: targetPosition.x, y: collision.y - node.height - gap },
        { x: collision.x + collision.width + gap, y: targetPosition.y },
        { x: targetPosition.x, y: collision.y + collision.height + gap },
        { x: collision.x - node.width - gap, y: targetPosition.y },
    ].sort((firstCandidate, secondCandidate) => {
        const firstDistance = canvasDistance(firstCandidate, targetPosition);
        const secondDistance = canvasDistance(secondCandidate, targetPosition);

        return firstDistance - secondDistance;
    });

    return (
        candidates.find((candidate) =>
            otherNodes.every((otherNode) => !canvasNodesOverlap({ ...node, ...candidate }, otherNode, gap)),
        ) ?? candidates[0]
    );
}

function canvasNodesOverlap(node: CanvasNodeBounds, otherNode: CanvasNodeBounds, gap: number): boolean {
    return (
        node.x < otherNode.x + otherNode.width + gap &&
        node.x + node.width + gap > otherNode.x &&
        node.y < otherNode.y + otherNode.height + gap &&
        node.y + node.height + gap > otherNode.y
    );
}

function canvasDistance(firstPosition: CanvasNodePosition, secondPosition: CanvasNodePosition): number {
    return Math.hypot(firstPosition.x - secondPosition.x, firstPosition.y - secondPosition.y);
}
