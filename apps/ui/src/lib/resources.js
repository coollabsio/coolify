import { get } from '$lib/api';

export const loadResources = async function({}) {
  try {
    const data = await get('/resources');
    return { props: {...data} };
  } catch (error) {
    console.log(error);
    return {};
  }
}