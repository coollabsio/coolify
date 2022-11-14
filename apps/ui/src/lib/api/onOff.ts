import { post } from '$lib/api';
import { errorNotification } from "$lib/common";

export async function stopThing(thing, what) {
  const sure = confirm('Stop?');
  if (!sure) return;
  try {
    await post(`/${what}/${thing.id}/stop`, {});
    return true;
  } catch (error) {
    errorNotification(error);
    return false;
  }
}
export async function startThing(thing, what) {
  try {
    // @TODO: make start default on api / create a default restart route too (Applications will not handle this)
    await post(`/${what}/${thing.id}/start`, {});
    return true;
  } catch (error) {
    errorNotification(error);
    return false;
  } 
}