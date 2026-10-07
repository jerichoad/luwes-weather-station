'use client';

import { CloudSun, Gauge, LayoutGrid, Radio, Settings2 } from 'lucide-react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { cn } from '@/lib/utils';

const navItems = [
  { title: 'Overview', href: '/', icon: LayoutGrid },
  { title: 'Devices', href: '/manage/devices', icon: Radio },
  { title: 'Sensors', href: '/manage/sensors', icon: Gauge },
];

export function AppSidebar() {
  const pathname = usePathname();

  return (
    <aside className="hidden w-60 shrink-0 flex-col border-r bg-card lg:flex">
      <div className="flex h-14 items-center gap-2 border-b px-4">
        <span className="inline-flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">
          <CloudSun aria-hidden="true" className="size-4.5" />
        </span>
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold text-foreground">Luwes Weather</p>
          <p className="truncate text-[11px] text-muted-foreground">Station Monitoring</p>
        </div>
      </div>

      <nav className="flex flex-1 flex-col gap-1 p-3">
        {navItems.map((item) => {
          const active = item.href === '/' ? pathname === '/' : pathname.startsWith(item.href);
          const Icon = item.icon;
          return (
            <Link
              key={item.href}
              href={item.href}
              className={cn(
                'flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                active ? 'bg-navy-50 text-navy-700' : 'text-muted-foreground hover:bg-muted hover:text-foreground',
              )}
            >
              <Icon aria-hidden="true" className="size-4" />
              {item.title}
            </Link>
          );
        })}
      </nav>

      <div className="border-t p-3">
        <div className="flex items-center gap-2 rounded-md bg-navy-50 px-3 py-2 text-xs text-navy-700">
          <Settings2 aria-hidden="true" className="size-3.5 shrink-0" />
          <span>Dashboard viewer — read only</span>
        </div>
      </div>
    </aside>
  );
}
