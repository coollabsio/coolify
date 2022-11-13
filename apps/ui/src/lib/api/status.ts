import {asyncSleep } from '$lib/common'

export let numberOfGetStatus = 0;
export let status: any = {};
export let noInitialStatus: any = {
  applications: false,
  services: false,
  databases: false
};

export async function refreshStatusApplications() {
		noInitialStatus.applications = false;
		numberOfGetStatus = 0;
		for (const application of applications) {
			status[application.id] = 'loading';
			getStatus(application, true);
		}
	}
export async function refreshStatusServices() {
		noInitialStatus.services = false;
		numberOfGetStatus = 0;
		for (const service of services) {
			status[service.id] = 'loading';
			getStatus(service, true);
		}
	}
export async function refreshStatusDatabases() {
		noInitialStatus.databases = false;
		numberOfGetStatus = 0;
		for (const database of databases) {
			status[database.id] = 'loading';
			getStatus(database, true);
		}
	}


export async function getStatus(resources: any, force: boolean = false) {
  const { id, buildPack, dualCerts, type } = resources;
  if (buildPack && applications.length + filtered.otherApplications.length > 10 && !force) {
    noInitialStatus.applications = true;
    return;
  }
  if (type && services.length + filtered.otherServices.length > 10 && !force) {
    noInitialStatus.services = true;
    return;
  }
  if (databases.length + filtered.otherDatabases.length > 10 && !force) {
    noInitialStatus.databases = true;
    return;
  }
  if (status[id] && !force) return status[id];
  while (numberOfGetStatus > 1) {
    await asyncSleep(getRndInteger(100, 500));
  }
  try {
    numberOfGetStatus++;
    let isRunning = false;
    let isDegraded = false;
    if (buildPack) {
      const response = await get(`/applications/${id}/status`);
      if (response.length === 0) {
        isRunning = false;
      } else if (response.length === 1) {
        isRunning = response[0].status.isRunning;
      } else {
        let overallStatus = false;
        for (const oneStatus of response) {
          if (oneStatus.status.isRunning) {
            overallStatus = true;
          } else {
            isDegraded = true;
            break;
          }
        }
        if (overallStatus) {
          isRunning = true;
        } else {
          isRunning = false;
        }
      }
    } else if (typeof dualCerts !== 'undefined') {
      const response = await get(`/services/${id}/status`);
      if (Object.keys(response).length === 0) {
        isRunning = false;
      } else {
        let overallStatus = false;
        for (const oneStatus of Object.keys(response)) {
          if (response[oneStatus].status.isRunning) {
            overallStatus = true;
          } else {
            isDegraded = true;
            break;
          }
        }
        if (overallStatus) {
          isRunning = true;
        } else {
          isRunning = false;
        }
      }
    } else {
      const response = await get(`/databases/${id}/status`);
      isRunning = response.isRunning;
    }

    if (isRunning) {
      status[id] = 'running';
      return 'running';
    } else if (isDegraded) {
      status[id] = 'degraded';
      return 'degraded';
    } else {
      status[id] = 'stopped';
      return 'stopped';
    }
  } catch (error) {
    status[id] = 'error';
    return 'error';
  } finally {
    status = { ...status };
    numberOfGetStatus--;
  }
}