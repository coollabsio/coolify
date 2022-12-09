import type { FastifyRequest } from 'fastify';
import { errorHandler, prisma, executeCommand } from '../../../../lib/common';
import os from 'node:os';
import osu from 'node-os-utils';


export async function listServers(request: FastifyRequest) {
    try {
        const userId = request.user.userId;
        const teamId = request.user.teamId;
        let servers = await prisma.destinationDocker.findMany({ where: { teams: { some: { id: teamId === '0' ? undefined : teamId } } }, distinct: ['remoteIpAddress', 'engine'] })
        servers = servers.filter((server) => {
            if (server.remoteEngine) {
                if (server.remoteVerified) {
                    return server
                }
            } else {
                return server
            }
        })
        return {
            servers
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
const mappingTable = [
    ['K total memory', 'totalMemoryKB'],
    ['K used memory', 'usedMemoryKB'],
    ['K active memory', 'activeMemoryKB'],
    ['K inactive memory', 'inactiveMemoryKB'],
    ['K free memory', 'freeMemoryKB'],
    ['K buffer memory', 'bufferMemoryKB'],
    ['K swap cache', 'swapCacheKB'],
    ['K total swap', 'totalSwapKB'],
    ['K used swap', 'usedSwapKB'],
    ['K free swap', 'freeSwapKB'],
    ['non-nice user cpu ticks', 'nonNiceUserCpuTicks'],
    ['nice user cpu ticks', 'niceUserCpuTicks'],
    ['system cpu ticks', 'systemCpuTicks'],
    ['idle cpu ticks', 'idleCpuTicks'],
    ['IO-wait cpu ticks', 'ioWaitCpuTicks'],
    ['IRQ cpu ticks', 'irqCpuTicks'],
    ['softirq cpu ticks', 'softIrqCpuTicks'],
    ['stolen cpu ticks', 'stolenCpuTicks'],
    ['pages paged in', 'pagesPagedIn'],
    ['pages paged out', 'pagesPagedOut'],
    ['pages swapped in', 'pagesSwappedIn'],
    ['pages swapped out', 'pagesSwappedOut'],
    ['interrupts', 'interrupts'],
    ['CPU context switches', 'cpuContextSwitches'],
    ['boot time', 'bootTime'],
    ['forks', 'forks']
];
function parseFromText(text) {
    var data = {};
    var lines = text.split(/\r?\n/);
    for (const line of lines) {
        for (const [key, value] of mappingTable) {
            if (line.indexOf(key) >= 0) {
                const values = line.match(/[0-9]+/)[0];
                data[value] = parseInt(values, 10);
            }
        }
    }
    return data;
}
export async function showUsage(request: FastifyRequest) {
    const { id } = request.params;
    let { remoteEngine } = request.query
    remoteEngine = remoteEngine === 'true' ? true : false
    if (remoteEngine) {
        const { stdout: stats } = await executeCommand({ sshCommand: true, dockerId: id, command: `vmstat -s` })
        const { stdout: disks } = await executeCommand({ sshCommand: true, shell: true, dockerId: id, command: `df -m / --output=size,used,pcent|grep -v 'Used'| xargs` })
        const { stdout: cpus } = await executeCommand({ sshCommand: true, dockerId: id, command: `nproc --all` })
        const { stdout: cpuUsage } = await executeCommand({ sshCommand: true, shell: true, dockerId: id, command: `echo $[100-$(vmstat 1 2|tail -1|awk '{print $15}')]` })
        const parsed: any = parseFromText(stats)
        return {
            usage: {
                uptime: parsed.bootTime / 1024,
                memory: {
                    totalMemMb: parsed.totalMemoryKB / 1024,
                    usedMemMb: parsed.usedMemoryKB / 1024,
                    freeMemMb: parsed.freeMemoryKB / 1024,
                    usedMemPercentage: (parsed.usedMemoryKB / parsed.totalMemoryKB) * 100,
                    freeMemPercentage: (parsed.totalMemoryKB - parsed.usedMemoryKB) / parsed.totalMemoryKB * 100
                },
                cpu: {
                    load: [0, 0, 0],
                    usage: cpuUsage,
                    count: cpus
                },
                disk: {
                    totalGb: (disks.split(' ')[0] / 1024).toFixed(1),
                    usedGb: (disks.split(' ')[1] / 1024).toFixed(1),
                    freeGb: (disks.split(' ')[0] - disks.split(' ')[1]).toFixed(1),
                    usedPercentage: disks.split(' ')[2].replace('%', ''),
                    freePercentage: 100 - disks.split(' ')[2].replace('%', '')
                }

            }
        }
    } else {
        try {
            return {
                usage: {
                    uptime: os.uptime(),
                    memory: await osu.mem.info(),
                    cpu: {
                        load: os.loadavg(),
                        usage: await osu.cpu.usage(),
                        count: os.cpus().length
                    },
                    disk: await osu.drive.info('/')
                }

            };
        } catch ({ status, message }) {
            return errorHandler({ status, message })
        }
    }


}