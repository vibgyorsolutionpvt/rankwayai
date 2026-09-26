import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StudioSubnav from '@/Components/Studio/StudioSubnav';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import { confirmAsk } from '@/Components/ConfirmProvider';
import { Head, Link, router, useForm } from '@inertiajs/react';

const money = (n, currency = 'INR') => {
    const v = Number(n || 0);
    if (currency === 'INR') return `₹${v.toLocaleString('en-IN')}`;
    return `${currency} ${v.toLocaleString()}`;
};

export default function Index({
    workspace,
    quotations = [],
    itineraries = [],
    leads = [],
    defaults = {},
}) {
    const form = useForm({
        title: '',
        itinerary_id: '',
        crm_lead_id: '',
        customer_name: '',
        customer_email: '',
        customer_phone: '',
        currency: defaults.currency || 'INR',
        travellers: defaults.travellers || 2,
        tax_percent: defaults.tax_percent ?? 5,
        status: 'draft',
    });

    const onLeadChange = (id) => {
        form.setData('crm_lead_id', id);
        const lead = leads.find((l) => String(l.id) === String(id));
        if (!lead) return;
        form.setData('customer_name', lead.name || form.data.customer_name);
        form.setData('customer_email', lead.email || form.data.customer_email);
        form.setData('customer_phone', lead.phone || form.data.customer_phone);
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <h2 className="font-display text-2xl font-bold leading-tight text-ink">
                        Business Studio
                    </h2>
                </div>
            }
        >
            <Head title="Quotations" />
            <div className="atlas-shell space-y-4">
                <StudioSubnav active="quotations" />
                <form
                    className="atlas-panel grid gap-3 p-4 md:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('studio.quotations.store'));
                    }}
                >
                    <div className="md:col-span-2">
                        <h3 className="font-display text-lg font-bold text-ink">New quotation</h3>
                        <p className="text-sm text-ink-muted">
                            Generate from an itinerary or start blank — then edit line items, PDF &amp;
                            share.
                        </p>
                    </div>
                    <div>
                        <InputLabel value="From itinerary" />
                        <div className="mt-1">
                            <SelectMenu
                                value={form.data.itinerary_id}
                                onChange={(v) => form.setData('itinerary_id', v)}
                                options={[
                                    { value: '', label: 'Blank quotation' },
                                    ...itineraries.map((i) => ({
                                        value: String(i.id),
                                        label: i.title,
                                        meta: `${i.destination || '—'} · ${i.duration_days || '?'}D`,
                                    })),
                                ]}
                            />
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Link CRM lead" />
                        <div className="mt-1">
                            <SelectMenu
                                value={form.data.crm_lead_id}
                                onChange={onLeadChange}
                                options={[
                                    { value: '', label: 'No lead' },
                                    ...leads.map((l) => ({
                                        value: String(l.id),
                                        label: l.name,
                                        meta: l.company || l.email || '',
                                    })),
                                ]}
                            />
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Title" />
                        <TextInput
                            className="mt-1 w-full"
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.target.value)}
                            placeholder="Auto from itinerary if empty"
                        />
                    </div>
                    <div>
                        <InputLabel value="Customer name" />
                        <TextInput
                            className="mt-1 w-full"
                            value={form.data.customer_name}
                            onChange={(e) => form.setData('customer_name', e.target.value)}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <PrimaryButton processing={form.processing}>Create quotation</PrimaryButton>
                    </div>
                </form>

                <section className="atlas-panel overflow-hidden">
                    <div className="border-b border-line px-4 py-3">
                        <h3 className="font-display text-base font-bold text-ink">Your quotations</h3>
                    </div>
                    {quotations.length === 0 ? (
                        <p className="px-4 py-8 text-sm text-ink-muted">No quotations yet.</p>
                    ) : (
                        <ul className="divide-y divide-line">
                            {quotations.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                                >
                                    <div className="min-w-0">
                                        <div className="font-semibold text-ink">{item.title}</div>
                                        <div className="text-xs text-ink-muted">
                                            {item.number}
                                            {item.customer_name ? ` · ${item.customer_name}` : ''}
                                            {item.destination ? ` · ${item.destination}` : ''}
                                            {' · '}
                                            {money(item.total, item.currency)}
                                            {' · '}
                                            <span className="uppercase">{item.status}</span>
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Link
                                            href={route('studio.quotations.edit', item.id)}
                                            className="rounded-md border border-line bg-white px-3 py-1.5 text-xs font-semibold text-ink hover:bg-mist"
                                        >
                                            Edit
                                        </Link>
                                        <a
                                            href={route('studio.quotations.pdf', item.id)}
                                            className="rounded-md border border-line bg-white px-3 py-1.5 text-xs font-semibold text-ink hover:bg-mist"
                                        >
                                            PDF
                                        </a>
                                        {item.status === 'sent' || item.status === 'accepted' ? (
                                            <a
                                                href={item.share_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="rounded-md border border-line bg-white px-3 py-1.5 text-xs font-semibold text-ink hover:bg-mist"
                                            >
                                                Share
                                            </a>
                                        ) : null}
                                        <button
                                            type="button"
                                            className="rounded-md border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50"
                                            onClick={async () => {
                                                if (
                                                    !(await confirmAsk({
                                                        title: 'Delete quotation?',
                                                        message: 'This cannot be undone.',
                                                    }))
                                                ) {
                                                    return;
                                                }
                                                router.delete(route('studio.quotations.destroy', item.id));
                                            }}
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
