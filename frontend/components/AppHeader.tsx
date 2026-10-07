'use client';

import { useIsFetching, useQueryClient } from '@tanstack/react-query';
import { CloudSun } from 'lucide-react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import * as React from 'react';
import { RefreshIndicator } from '@/components/RefreshIndicator';
import { useAutoRefresh } from '@/components/refresh-context';
import { cn } from '@/lib/utils';

const mobileNav = [
  { title: 'Overview', href: '/' },
  { title: 'Devices', href: '/manage/devices' },
  { title: 'Sensors', href: '/manage/sensors' },
];

function useLastUpdated(): Date | null {
  const queryClient = useQueryClient();
  const [last, setLast] = React.useState<Date | null>(null);

  React.useEffect(() => {
    const cache = queryClient.getQueryCache();
    const compute = () => {
      const max = cache.getAll().reduce((acc, q) => Math.max(acc, q.state.dataUpdatedAt), 0);
      setLast(max > 0 ? new Date(max) : null);
    };
    compute();
    return cache.subscribe(compute);
  }, [queryClient]);

  return last;
}

export function AppHeader() {
  const pathname = usePathname();
  const queryClient = useQueryClient();
  const isFetching = useIsFetching() > 0;
  const lastUpdated = useLastUpdated();
  const { autoRefresh, setAutoRefresh } = useAutoRefresh();

  return (
    <header className="sticky top-0 z-20 border-b bg-card/90 backdrop-blur">
      <div className="flex h-14 items-center justify-between gap-3 px-3 sm:px-4 md:px-5 lg:px-6 xl:px-8">
        <div className="flex min-w-0 items-center gap-3">
          <Link href="/" className="inline-flex items-center gap-2 lg:hidden">
            <span className="inline-flex size-7 items-center justify-center rounded-lg bg-primary text-primary-foreground">
              <CloudSun aria-hidden="true" className="size-4" />
            </span>
            <span className="sr-only">Overview</span>
          </Link>
          <nav aria-label="Navigasi utama" className="flex gap-1 overflow-x-auto lg:hidden">
            {mobileNav.map((item) => {
              const active = item.href === '/' ? pathname === '/' : pathname.startsWith(item.href);
              return (
                <Link
                  key={item.href}
                  href={item.href}
                  className={cn(
                    'rounded-md px-2.5 py-1 text-xs font-semibold whitespace-nowrap',
                    active ? 'bg-navy-50 text-navy-700' : 'text-muted-foreground hover:bg-muted',
                  )}
                >
                  {item.title}
                </Link>
              );
            })}
          </nav>
        </div>
        <RefreshIndicator
          lastUpdatedAt={lastUpdated}
          isFetching={isFetching}
          autoRefresh={autoRefresh}
          onToggleAutoRefresh={() => setAutoRefresh(!autoRefresh)}
          onRefresh={() => queryClient.refetchQueries({ type: 'active' })}
        />
      </div>
    </header>
  );
}
