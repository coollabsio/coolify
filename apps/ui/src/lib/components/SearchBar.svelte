<script lang="ts">
  export let placeholder:string;
  export let filter:string;
</script>

<input placeholder={placeholder} bind:value={filter}/>

<style>
  input{
    border-radius: 32px;
    padding: 12px 24px;
    width: 240px;
    margin-right: 16px;
    line-height: 1em;
    height: 2rem;
  }
</style>
