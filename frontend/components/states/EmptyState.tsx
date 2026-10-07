import type { LucideIcon } from 'lucide-react';
import { Inbox } from 'lucide-react';
import type * as React from 'react';
import { cn } from '@/lib/utils';

export function EmptyState({
  message,
  description,
  icon: Icon = Inbox,
  action,
  className,
}: {
  message: string;
  description?: string;
  icon?: LucideIcon;
  action?: React.ReactNode;
  className?: string;
}) {
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center gap-2 rounded-md border border-dashed px-6 py-10 text-center',
        className,
      )}
    >
      <Icon aria-hidden="true" className="size-6 text-muted-foreground/60" />
      <p className="text-sm font-medium text-foreground">{message}</p>
      {description ? <p className="max-w-sm text-xs text-muted-foreground">{description}</p> : null}
      {action ? <div className="mt-2">{action}</div> : null}
    </div>
  );
}
