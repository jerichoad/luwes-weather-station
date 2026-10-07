import { AppHeader } from '@/components/AppHeader';
import { AppSidebar } from '@/components/AppSidebar';

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-screen w-full bg-background">
      <AppSidebar />
      <div className="flex min-w-0 flex-1 flex-col">
        <AppHeader />
        <main className="flex-1 px-3 py-6 sm:px-4 md:px-5 lg:px-6 xl:px-8">{children}</main>
      </div>
    </div>
  );
}
