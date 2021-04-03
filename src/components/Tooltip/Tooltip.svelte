<script>
  export let position = "bottom";
  export let label;
  export let size;
</script>

<span
  aria-label="{label}"
  data-microtip-position="{position}"
  data-microtip-size="{size}"
  role="tooltip"
>
<slot></slot>
</span>
