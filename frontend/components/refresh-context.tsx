'use client';

import * as React from 'react';

const RefreshContext = React.createContext<{
  autoRefresh: boolean;
  setAutoRefresh: (v: boolean) => void;
}>({ autoRefresh: true, setAutoRefresh: () => {} });

export function RefreshProvider({ children }: { children: React.ReactNode }) {
  const [autoRefresh, setAutoRefresh] = React.useState(true);
  const value = React.useMemo(() => ({ autoRefresh, setAutoRefresh }), [autoRefresh]);
  return <RefreshContext.Provider value={value}>{children}</RefreshContext.Provider>;
}

export function useAutoRefresh() {
  return React.useContext(RefreshContext);
}

/** Interval polling TanStack Query; `false` saat auto refresh dijeda. */
export function usePollInterval(ms: number): number | false {
  const { autoRefresh } = useAutoRefresh();
  return autoRefresh ? ms : false;
}
