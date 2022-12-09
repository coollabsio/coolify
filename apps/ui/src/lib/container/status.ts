//
// Maps Container ID x Operation Status
//
// Example response of $status => {'123asdf': 'degraded', '124asdf': 'running'}

import { writable, get as getStore } from 'svelte/store';
import { get } from '$lib/api';

export let containerStatus = writable({});

let PERMITED_STATUS = ['loading', 'running', 'healthy', 'building', 'degraded', 'stopped', 'error'];

// refreshStatus([{id}])
export async function refreshStatus(list: Array<any>) {
  for (const item of list) {
    setStatus(item.id, 'loading');
    getStatus(item, true);
  }
}

export async function getStatus(resource: any, force: boolean = false) {
  const { id, buildPack, dualCerts, engine, simpleDockerfile } = resource;
  let newStatus = 'stopped';

  // Already set and we're not forcing
  if (getStore(containerStatus)[id] && !force) return getStore(containerStatus)[id];

  try {
    if (buildPack || simpleDockerfile) { // Application
      const response = await get(`/applications/${id}/status`);
      newStatus = parseApplicationsResponse(response);
    } else if (typeof dualCerts !== 'undefined') { // Service
      const response = await get(`/services/${id}/status`);
      newStatus = parseServiceResponse(response);
    } else if (typeof engine !== 'undefined') { // Destination/Server
      const response = await get(`/destinations/${id}/status`);
      newStatus = response.isRunning ? 'running' : 'stopped';
    } else { // Database
      const response = await get(`/databases/${id}/status`);
      newStatus = response.isRunning ? 'running' : 'stopped';
    }
  } catch (error) {
    newStatus = 'error';
  }

  setStatus(id, newStatus);
  // console.log("GOT:", id, newStatus)
  return newStatus
}

const setStatus = (thingId, newStatus) => {
  if (!PERMITED_STATUS.includes(newStatus))
    throw (`Change to ${newStatus} is not permitted. Try: ${PERMITED_STATUS.join(', ')}`);
  containerStatus.update(n => Object.assign(n, { thingId: newStatus }));
};

// -- Response Parsing

function parseApplicationsResponse(list: Array<any>) {
  if (list.length === 0) return 'stopped';
  if (list.length === 1) return list[0].status.isRunning ? 'running' : 'stopped';
  return allWorking(list.map((el: any) => el.status.isRunning))
}

function parseServiceResponse(response: any) {
  if (Object.keys(response).length === 0) return 'stopped';
  let list = Object.keys(response).map((el) => el.status.isRunning)
  return allWorking(list) ? 'running' : 'degraded'
}

function allWorking(list: Array<any>) {
  return list.reduce((acum: boolean, res: boolean) => acum && res) ? 'running' : 'degraded';
}
