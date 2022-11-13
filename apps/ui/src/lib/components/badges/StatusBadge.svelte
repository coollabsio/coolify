<script type="ts">
  export let status:string;
  $: klass = `badge-status-${status}`;
</script>
<span class="badge badge-lg rounded uppercase {klass}">
  {status}
</span>