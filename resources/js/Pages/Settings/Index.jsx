import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import PrimaryButton from '@/Components/PrimaryButton';
import ProvidersPanel from '@/Pages/Settings/ProvidersPanel';
import WorkspacePanel from '@/Pages/Settings/WorkspacePanel';
import { Head, router } from '@inertiajs/react';

const TABS = [
    { id: 'providers', label: 'Providers', help: HELP.integrations },
    { id: 'workspace', label: 'Workspace', help: HELP.workspaces },
    { id: 'account', label: 'Account', help: HELP.settings },
    { id: 'billing', label: 'Billing', help: HELP.billing },
];

function formatMoney(amount, currency) {
    const n = Number(amount || 0);
    if (currency === 'INR') {
        return `₹${n.toLocaleString('en-IN')}`;
    }
    if (amount == null) {
        return '—';
    }
    return `$${n}`;
}

export default function Index({
    tab = 'providers',
    provider_category = 'social',
    configure_provider = null,
    workspace,
    categories = {},
    integrations = [],
    workspaces = [],
    activeWorkspace,
    members = [],
    roles = [],
    moduleCatalog = null,
    billing = {},
    account = {},
}) {
    const visitTab = (id) => {
        router.get(
            route('settings.index'),
            { tab: id },
            { preserveState: true, replace: true, preserveScroll: true },
        );
    };

    const activeHelp = TABS.find((t) => t.id === tab)?.help || HELP.settings;

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        {workspace?.name || 'Workspace'}
                    </div>
                    <div className="flex items-center gap-1.5">
                        <h2 className="font-display text-2xl font-bold text-ink">Settings</h2>
                        <HelpGuide help={activeHelp} />
                    </div>
                </div>
            }
        >
            <Head title="Settings" />

            <div className="atlas-shell space-y-4">
                <section className="inline-flex flex-wrap gap-0.5 rounded-lg border border-line bg-mist/80 p-1">
                    {TABS.map((t) => (
                        <button
                            key={t.id}
                            type="button"
                            onClick={() => visitTab(t.id)}
                            className={`rounded-md px-3.5 py-1.5 text-sm font-semibold transition ${
                                tab === t.id
                                    ? 'bg-white text-ink shadow-sm'
                                    : 'text-ink-muted hover:text-ink'
                            }`}
                        >
                            {t.label}
                        </button>
                    ))}
                </section>

                {tab === 'providers' ? (
                    <div className="space-y-3">
                        <div className="atlas-panel p-4">
                            <div className="flex items-center gap-1.5">
                                <div className="font-display text-lg font-bold text-ink">
                                    Connect your providers
                                </div>
                                <HelpGuide help={HELP.integrations} />
                            </div>
                            <p className="mt-1 text-sm text-ink-muted">
                                API keys for Social, WhatsApp/Email, RCS, and Google SEO. Tap ⓘ on
                                each card for where to get Client ID / secret / API key. Secrets
                                stay encrypted.
                            </p>
                        </div>
                        <ProvidersPanel
                            categories={categories}
                            integrations={integrations}
                            initialCategory={provider_category}
                            initialConfigure={configure_provider}
                        />
                    </div>
                ) : null}

                {tab === 'workspace' ? (
                    <WorkspacePanel
                        workspaces={workspaces}
                        activeWorkspace={activeWorkspace}
                        members={members}
                        roles={roles}
                        moduleCatalog={moduleCatalog}
                    />
                ) : null}

                {tab === 'account' ? (
                    <section className="atlas-panel overflow-hidden">
                        <div className="border-b border-line px-4 py-3.5">
                            <h3 className="font-display text-base font-bold text-ink">
                                Your account
                            </h3>
                            <p className="mt-0.5 text-sm text-ink-muted">
                                Profile and password for this login.
                            </p>
                        </div>
                        <div className="space-y-4 p-4">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                        Name
                                    </div>
                                    <div className="mt-1 font-semibold text-ink">
                                        {account.name || '—'}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                        Email
                                    </div>
                                    <div className="mt-1 font-semibold text-ink">
                                        {account.email || '—'}
                                    </div>
                                </div>
                            </div>
                            <PrimaryButton
                                type="button"
                                onClick={() => router.visit(route('profile.edit'))}
                            >
                                Edit profile & password
                            </PrimaryButton>
                        </div>
                    </section>
                ) : null}

                {tab === 'billing' ? (
                    <section className="atlas-panel overflow-hidden">
                        <div className="border-b border-line px-4 py-3.5">
                            <h3 className="font-display text-base font-bold text-ink">
                                Plan & billing
                            </h3>
                            <p className="mt-0.5 text-sm text-ink-muted">
                                Current plan for {workspace?.name}. Manage upgrades and AI credits
                                on the billing page.
                            </p>
                        </div>
                        <div className="space-y-4 p-4">
                            <div className="grid gap-3 sm:grid-cols-3">
                                <div>
                                    <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                        Plan
                                    </div>
                                    <div className="mt-1 font-display text-2xl font-bold capitalize text-ink">
                                        {billing.plan || 'free'}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                        Seats
                                    </div>
                                    <div className="mt-1 font-display text-2xl font-bold text-ink">
                                        {billing.seats ?? '—'}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                        Charge
                                    </div>
                                    <div className="mt-1 font-display text-2xl font-bold text-ink">
                                        {formatMoney(
                                            billing.mrr_amount,
                                            billing.billing_currency,
                                        )}
                                        <span className="ms-1 text-sm font-medium text-ink-muted">
                                            /{billing.billing_interval === 'year' ? 'yr' : 'mo'}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <PrimaryButton
                                type="button"
                                onClick={() => router.visit(route('billing.index'))}
                            >
                                Open billing
                            </PrimaryButton>
                        </div>
                    </section>
                ) : null}
            </div>
        </AuthenticatedLayout>
    );
}
