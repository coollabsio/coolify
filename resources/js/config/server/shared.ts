import { Bot, ChartSpline, SettingsIcon, Unplug } from 'lucide-vue-next';
import { route } from '@/route';
import { CustomBreadcrumbItem } from '@/types/BreadcrumbsType';
import { SidebarNavItem } from '@/types/SidebarNavItemType';

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

export const getServerSidebarNavItems = (serverUuid: string): SidebarNavItem[] => [
  {
    title: 'General',
    icon: SettingsIcon,
    href: route('next_server', serverUuid),
  },
  {
    title: 'Connection',
    icon: Unplug,
    href: route('next_server_connection', serverUuid),
  },
  {
    title: 'Automations',
    icon: Bot,
    href: route('next_server_automations', serverUuid),
  },
  {
    title: 'Metrics',
    icon: ChartSpline,
    href: route('next_server', serverUuid),
  }
];