<script>
	import SmCard from "../cards/SmCard.svelte";
  import {slide} from "svelte/transition";
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
  {#if thing.visible}
    <div transition:slide|local>
      <SmCard {thing} url={chooseUrl(thing, url)} {kind}/>
    </div>
  {/if}
{/each}
