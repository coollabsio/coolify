import { Bot, ChartSpline, Server, SettingsIcon, Unplug } from 'lucide-vue-next';
import { route } from '@/route';
import { CustomBreadcrumbItem } from '@/types/BreadcrumbsType';
import { SidebarNavItem } from '@/types/SidebarNavItemType';
import { Server as ServerType } from '@/types/ServerType';

export const getServerBreadcrumbs = (serverName: string, serverUuid: string): CustomBreadcrumbItem[] => [
  {
    label: 'Dashboard',
    href: route('next_dashboard')
  },
  {
    label: 'Servers',
    href: route('next_servers')
  },
  {
    label: serverName,
    href: route('next_server', serverUuid)
  }
];

export const getServerSidebarNavItems = (server: ServerType): SidebarNavItem[] => [
  {
    title: 'General',
    icon: SettingsIcon,
    href: route('next_server', server.uuid),
  },
  {
    title: 'Connection',
    icon: Unplug,
    href: route('next_server_connection', server.uuid),
  },
  {
    title: "Proxy",
    icon: Server,
    href: route('next_server_proxy', server.uuid),
    indicator: server.proxy.status,
  },
  {
    title: 'Automations',
    icon: Bot,
    href: route('next_server_automations', server.uuid),
  }
];