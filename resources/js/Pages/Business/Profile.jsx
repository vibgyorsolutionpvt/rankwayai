import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import BusinessProfilePanel from '@/Pages/Settings/BusinessProfilePanel';
import { Head } from '@inertiajs/react';

export default function Profile({
    workspace,
    activeWorkspace,
    businessTypes = [],
}) {
    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        {workspace?.name || 'Business'}
                    </div>
                    <div className="flex items-center gap-1.5">
                        <h2 className="font-display text-2xl font-bold text-ink">
                            Business Profile
                        </h2>
                        <HelpGuide help={HELP.workspaces} />
                    </div>
                </div>
            }
        >
            <Head title="Business Profile" />

            <div className="atlas-shell">
                <BusinessProfilePanel
                    activeWorkspace={activeWorkspace}
                    businessTypes={businessTypes}
                />
            </div>
        </AuthenticatedLayout>
    );
}
