<script>
	import SmCard from "../cards/SmCard.svelte";
  export let things;
  export let url;
  export let kind;
  let chooseUrl = function(thing, url){
    if(kind === 'app')
      return( (typeof(thing.type) === 'undefined' ?  '/applications/' : '/services/') + thing.id);
    return url+thing.id
  }
</script>
{#each things as thing}
  <SmCard {thing} url={chooseUrl(thing, url)} {kind}/>
{/each}