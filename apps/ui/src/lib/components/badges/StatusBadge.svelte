<script>
  /**
	 * @type {string}
	 */
   export let status;
</script>
<span
  class="badge badge-lg rounded uppercase"
  class:text-green-500={status === 'healthy'}
  class:text-yellow-400={status === 'degraded'}
  class:text-red-500={status === 'stopped'}
>
  {status}
</span>