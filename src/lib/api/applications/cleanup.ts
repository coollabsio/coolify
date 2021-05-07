import { execShellAsync } from "$lib/common"
import { docker } from "$lib/docker"

export async function deleteSameDeployments (configuration) {
    await (await docker.engine.listServices()).filter(r => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application').map(async s => {
      const running = JSON.parse(s.Spec.Labels.configuration)
      if (running.repository.id === configuration.repository.id && running.repository.branch === configuration.repository.branch) {
        await execShellAsync(`docker stack rm ${s.Spec.Labels['com.docker.stack.namespace']}`)
      }
    })
  }