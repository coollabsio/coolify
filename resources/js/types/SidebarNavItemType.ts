import { Component } from 'vue';

export interface SidebarNavItem {
  title: string;
  icon: Component;
  href: string;
  indicator?: string;
}
