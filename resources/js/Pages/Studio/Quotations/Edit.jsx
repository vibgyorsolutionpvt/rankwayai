import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';
import { toast } from '@/Components/ToastProvider';
import { Head, Link, router, useForm } from '@inertiajs/react';

const money = (n, currency = 'INR') => {
    const v = Number(n || 0);
    if (currency === 'INR') return `₹${v.toLocaleString('en-IN', { maximumFractionDigits: 2 })}`;
    return `${currency} ${v.toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
};

function emptyLine() {
    return { description: '', qty: 1, unit_price: 0 };
}

export default function Edit({ workspace, quotation, leads = [] }) {
    const form = useForm({
        title: quotation.title || '',
        status: quotation.status || 'draft',
        customer_name: quotation.customer_name || '',
        customer_email: quotation.customer_email || '',
        customer_phone: quotation.customer_phone || '',
        customer_company: quotation.customer_company || '',
        trip_title: quotation.trip_title || '',
        destination: quotation.destination || '',
        travellers: quotation.travellers || 1,
        duration_days: quotation.duration_days || '',
        currency: quotation.currency || 'INR',
        line_items: (quotation.line_items || []).length
            ? quotation.line_items.map((i) => ({
                  description: i.description || '',
                  qty: i.qty ?? 1,
                  unit_price: i.unit_price ?? 0,
              }))
            : [emptyLine()],
        discount_amount: quotation.discount_amount ?? 0,
        tax_percent: quotation.tax_percent ?? 0,
        payment_terms: quotation.payment_terms || '',
        valid_until: quotation.valid_until || '',
        notes: quotation.notes || '',
        inclusions_text: quotation.inclusions_text || '',
        exclusions_text: quotation.exclusions_text || '',
        is_public: quotation.is_public !== false,
        crm_lead_id: quotation.crm_lead_id ? String(quotation.crm_lead_id) : '',
    });

    const setLine = (index, patch) => {
        form.setData(
            'line_items',
            form.data.line_items.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        );
    };

    const liveTotals = (() => {
        const subtotal = (form.data.line_items || []).reduce((sum, line) => {
            const qty = Number(line.qty || 0);
            const unit = Number(line.unit_price || 0);
            return sum + qty * unit;
        }, 0);
        const discount = Math.min(subtotal, Math.max(0, Number(form.data.discount_amount || 0)));
        const taxable = Math.max(0, subtotal - discount);
        const taxPercent = Math.max(0, Math.min(100, Number(form.data.tax_percent || 0)));
        const tax = taxable * (taxPercent / 100);
        return {
            subtotal,
            discount,
            tax,
            total: taxable + tax,
        };
    })();

    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(quotation.share_url);
            toast.success('Share link copied');
        } catch {
            toast.error('Could not copy link');
        }
    };

    const onLeadChange = (id) => {
        const lead = leads.find((l) => String(l.id) === String(id));
        form.setData('crm_lead_id', id);
        if (!lead) return;
        form.setData('customer_name', lead.name || form.data.customer_name);
        form.setData('customer_email', lead.email || form.data.customer_email);
        form.setData('customer_phone', lead.phone || form.data.customer_phone);
        form.setData('customer_company', lead.company || form.data.customer_company);
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        <Link href={route('studio.quotations.index')} className="hover:text-ink">
                            Quotations
                        </Link>{' '}
                        / Edit
                    </div>
                    <h2 className="font-display text-2xl font-bold text-ink">{quotation.number}</h2>
                </div>
            }
        >
            <Head title={quotation.title} />
            <div className="atlas-shell grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
                <form
                    className="atlas-panel space-y-4 p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('studio.quotations.update', quotation.id));
                    }}
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <InputLabel value="Title *" />
                            <TextInput
                                className="mt-1 w-full"
                                value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                                required
                            />
                        </div>
                        <div>
                            <InputLabel value="Status" />
                            <div className="mt-1">
                                <SelectMenu
                                    value={form.data.status}
                                    onChange={(v) => form.setData('status', v)}
                                    options={[
                                        { value: 'draft', label: 'Draft' },
                                        { value: 'sent', label: 'Sent (public)' },
                                        { value: 'accepted', label: 'Accepted' },
                                        { value: 'declined', label: 'Declined' },
                                    ]}
                                />
                            </div>
                        </div>
                        <div>
                            <InputLabel value="CRM lead" />
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
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="sm:col-span-2 font-display text-sm font-bold text-ink">
                            Customer
                        </div>
                        <div>
                            <InputLabel value="Name" />
                            <TextInput
                                className="mt-1 w-full"
                                value={form.data.customer_name}
                                onChange={(e) => form.setData('customer_name', e.target.value)}
                            />
                        </div>
                        <div>
                            <InputLabel value="Company" />
                            <TextInput
                                className="mt-1 w-full"
                                value={form.data.customer_company}
                                onChange={(e) => form.setData('customer_company', e.target.value)}
                            />
                        </div>
                        <div>
                            <InputLabel value="Email" />
                            <TextInput
                                type="email"
                                className="mt-1 w-full"
                                value={form.data.customer_email}
                                onChange={(e) => form.setData('customer_email', e.target.value)}
                            />
                        </div>
                        <div>
                            <InputLabel value="Phone / WhatsApp" />
                            <TextInput
                                className="mt-1 w-full"
                                value={form.data.customer_phone}
                                onChange={(e) => form.setData('customer_phone', e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="sm:col-span-2 font-display text-sm font-bold text-ink">Trip</div>
                        <div>
                            <InputLabel value="Trip title" />
                            <TextInput
                                className="mt-1 w-full"
                                value={form.data.trip_title}
                                onChange={(e) => form.setData('trip_title', e.target.value)}
                            />
                        </div>
                        <div>
                            <InputLabel value="Destination" />
                            <TextInput
                                className="mt-1 w-full"
                                value={form.data.destination}
                                onChange={(e) => form.setData('destination', e.target.value)}
                            />
                        </div>
                        <div>
                            <InputLabel value="Travellers" />
                            <TextInput
                                type="number"
                                min="1"
                                className="mt-1 w-full"
                                value={form.data.travellers}
                                onChange={(e) => form.setData('travellers', e.target.value)}
                            />
                        </div>
                        <div>
                            <InputLabel value="Duration (days)" />
                            <TextInput
                                type="number"
                                min="1"
                                className="mt-1 w-full"
                                value={form.data.duration_days}
                                onChange={(e) => form.setData('duration_days', e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="space-y-2">
                        <div className="flex items-center justify-between">
                            <div className="font-display text-sm font-bold text-ink">Line items</div>
                            <button
                                type="button"
                                className="text-xs font-semibold text-violet-700 hover:underline"
                                onClick={() =>
                                    form.setData('line_items', [...form.data.line_items, emptyLine()])
                                }
                            >
                                + Add line
                            </button>
                        </div>
                        {(form.data.line_items || []).map((line, index) => (
                            <div
                                key={index}
                                className="grid gap-2 rounded-lg border border-line p-3 sm:grid-cols-[1fr_80px_110px_36px]"
                            >
                                <TextInput
                                    className="w-full"
                                    placeholder="Description"
                                    value={line.description}
                                    onChange={(e) => setLine(index, { description: e.target.value })}
                                />
                                <TextInput
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    className="w-full"
                                    value={line.qty}
                                    onChange={(e) => setLine(index, { qty: e.target.value })}
                                />
                                <TextInput
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    className="w-full"
                                    value={line.unit_price}
                                    onChange={(e) => setLine(index, { unit_price: e.target.value })}
                                />
                                <button
                                    type="button"
                                    className="text-xs font-semibold text-rose-600"
                                    onClick={() =>
                                        form.setData(
                                            'line_items',
                                            form.data.line_items.filter((_, i) => i !== index),
                                        )
                                    }
                                    aria-label="Remove line"
                                >
                                    ✕
                                </button>
                            </div>
                        ))}
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div>
                                <InputLabel value="Discount" />
                                <TextInput
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    className="mt-1 w-full"
                                    value={form.data.discount_amount}
                                    onChange={(e) => form.setData('discount_amount', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel value="Tax %" />
                                <TextInput
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    className="mt-1 w-full"
                                    value={form.data.tax_percent}
                                    onChange={(e) => form.setData('tax_percent', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel value="Currency" />
                                <TextInput
                                    className="mt-1 w-full"
                                    value={form.data.currency}
                                    onChange={(e) => form.setData('currency', e.target.value)}
                                    maxLength={3}
                                />
                            </div>
                        </div>
                        <div className="grid gap-2 rounded-lg border border-line bg-mist/40 p-3 sm:grid-cols-4">
                            <div>
                                <div className="text-[10px] font-bold uppercase text-ink-muted">
                                    Subtotal
                                </div>
                                <div className="font-semibold">
                                    {money(liveTotals.subtotal, form.data.currency)}
                                </div>
                            </div>
                            <div>
                                <div className="text-[10px] font-bold uppercase text-ink-muted">
                                    Discount
                                </div>
                                <div className="font-semibold">
                                    {money(liveTotals.discount, form.data.currency)}
                                </div>
                            </div>
                            <div>
                                <div className="text-[10px] font-bold uppercase text-ink-muted">Tax</div>
                                <div className="font-semibold">
                                    {money(liveTotals.tax, form.data.currency)}
                                </div>
                            </div>
                            <div>
                                <div className="text-[10px] font-bold uppercase text-ink-muted">
                                    Total
                                </div>
                                <div className="font-semibold text-emerald-800">
                                    {money(liveTotals.total, form.data.currency)}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Valid until" />
                            <TextInput
                                type="date"
                                className="mt-1 w-full"
                                value={form.data.valid_until}
                                onChange={(e) => form.setData('valid_until', e.target.value)}
                            />
                        </div>
                        <div className="flex items-end pb-1">
                            <Toggle
                                checked={!!form.data.is_public}
                                onChange={(v) => form.setData('is_public', v)}
                                label="Public share link"
                            />
                        </div>
                        <div className="sm:col-span-2">
                            <InputLabel value="Payment terms" />
                            <textarea
                                className="mt-1 w-full rounded-md border-line text-sm"
                                rows={2}
                                value={form.data.payment_terms}
                                onChange={(e) => form.setData('payment_terms', e.target.value)}
                            />
                        </div>
                        <div>
                            <InputLabel value="Inclusions (one per line)" />
                            <textarea
                                className="mt-1 w-full rounded-md border-line text-sm"
                                rows={4}
                                value={form.data.inclusions_text}
                                onChange={(e) => form.setData('inclusions_text', e.target.value)}
                            />
                        </div>
                        <div>
                            <InputLabel value="Exclusions (one per line)" />
                            <textarea
                                className="mt-1 w-full rounded-md border-line text-sm"
                                rows={4}
                                value={form.data.exclusions_text}
                                onChange={(e) => form.setData('exclusions_text', e.target.value)}
                            />
                        </div>
                        <div className="sm:col-span-2">
                            <InputLabel value="Notes" />
                            <textarea
                                className="mt-1 w-full rounded-md border-line text-sm"
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                            />
                        </div>
                    </div>

                    <PrimaryButton processing={form.processing}>Save quotation</PrimaryButton>
                </form>

                <aside className="space-y-4">
                    <section className="atlas-panel space-y-3 p-4">
                        <div className="font-display text-sm font-bold text-ink">Share &amp; export</div>
                        <p className="break-all text-xs text-ink-muted">{quotation.share_url}</p>
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={copyLink}
                                className="rounded-md border border-line bg-white px-3 py-1.5 text-xs font-semibold hover:bg-mist"
                            >
                                Copy link
                            </button>
                            <a
                                href={route('studio.quotations.pdf', quotation.id)}
                                className="rounded-md border border-line bg-white px-3 py-1.5 text-xs font-semibold hover:bg-mist"
                            >
                                Download PDF
                            </a>
                            <a
                                href={quotation.whatsapp_url}
                                target="_blank"
                                rel="noreferrer"
                                className="rounded-md border border-line bg-white px-3 py-1.5 text-xs font-semibold hover:bg-mist"
                            >
                                WhatsApp
                            </a>
                            <a
                                href={quotation.email_url}
                                className="rounded-md border border-line bg-white px-3 py-1.5 text-xs font-semibold hover:bg-mist"
                            >
                                Email
                            </a>
                            {(quotation.status === 'sent' || quotation.status === 'accepted') &&
                            quotation.is_public ? (
                                <a
                                    href={quotation.share_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="rounded-md border border-line bg-white px-3 py-1.5 text-xs font-semibold hover:bg-mist"
                                >
                                    Open public
                                </a>
                            ) : (
                                <p className="w-full text-xs text-ink-muted">
                                    Set status to <strong>Sent</strong> to publish the share link.
                                </p>
                            )}
                        </div>
                        <div className="text-xs text-ink-muted">
                            Views: {quotation.analytics?.views ?? 0} · Downloads:{' '}
                            {quotation.analytics?.downloads ?? 0}
                        </div>
                    </section>
                    <section className="atlas-panel space-y-2 p-4">
                        <div className="font-display text-sm font-bold text-ink">Actions</div>
                        <button
                            type="button"
                            className="w-full rounded-md border border-line bg-white px-3 py-2 text-left text-xs font-semibold hover:bg-mist"
                            onClick={() =>
                                router.post(route('studio.quotations.duplicate', quotation.id))
                            }
                        >
                            Duplicate
                        </button>
                    </section>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
