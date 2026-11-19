<script>
  import { nodeById, planOf } from '../../deploy-canvas.js';
  import { curve, cv, inPoint, outPoint, removeEdge } from './state.svelte.js';

  // The lines: connections (click one to remove it), every resource line into
  // Deploy, the result pill's line, and the line being dragged.
  const lines = $derived.by(() => {
    const flow = cv.flow;
    const runState = cv.runState;
    const out = [];
    for (const edge of flow.edges) {
      const a = nodeById(edge.from, flow);
      const b = nodeById(edge.to, flow);
      if (!a || !b) continue;
      const st = runState[edge.to]?.state;
      const cls = st === 'running' ? 'flow' : runState[edge.from]?.state === 'done' && st ? 'live' : null;
      // Start's connection to the first Server cannot be removed.
      const removable = !(edge.from === 'start' && edge.to === 'server');
      out.push({ d: curve(outPoint(a), inPoint(b)), cls, edge: removable ? edge : null });
    }
    const plan = planOf(flow);
    const st = plan.deploy && runState[plan.deploy.id]?.state;
    for (const { end } of plan.lines) {
      out.push({ d: curve(outPoint(end), inPoint(plan.deploy)), cls: st === 'running' ? 'flow' : st === 'done' ? 'live' : null, edge: null });
    }
    const after = cv.result && nodeById(cv.result.after, flow);
    if (after) {
      const a = outPoint(after);
      out.push({ d: curve(a, { x: a.x + 72, y: a.y }), cls: 'live', edge: null });
    }
    return out;
  });
  const dragged = $derived(cv.temp ?? cv.pendingLine);
</script>

<svg class="cv-edges">
  {#each lines as line}
    {#if line.edge}
      <!-- Pointer only, like the rest of the canvas. -->
      <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_static_element_interactions -->
      <path class="hit" d={line.d} onclick={() => removeEdge(line.edge)}><title>Click to remove this connection</title></path>
    {/if}
    <path class={line.cls} d={line.d} />
  {/each}
  {#if dragged}
    <path class="temp" d={curve(dragged.a, dragged.b)} />
  {/if}
</svg>
