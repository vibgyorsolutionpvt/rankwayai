import BrandName from '@/Components/BrandName';
import { Head } from '@inertiajs/react';

const money = (n, currency = 'INR') => {
    const v = Number(n || 0);
    if (currency === 'INR') return `₹${v.toLocaleString('en-IN', { maximumFractionDigits: 2 })}`;
    return `${currency} ${v.toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
};

export default function Public({ quotation, pdf_url }) {
    const primary = quotation.styles?.primary_color || '#0E9F90';
    const secondary = quotation.styles?.secondary_color || '#0B1220';
    const accent = quotation.styles?.accent_color || '#F59E0B';

    return (
        <div className="min-h-screen bg-slate-50">
            <Head title={quotation.title || quotation.number} />

            <header
                className="px-4 py-12 text-white"
                style={{ background: `linear-gradient(145deg, ${secondary}, ${primary})` }}
            >
                <div className="mx-auto max-w-2xl">
                    {quotation.logo_url ? (
                        <img
                            src={quotation.logo_url}
                            alt=""
                            className="mb-4 h-10 w-auto object-contain"
                        />
                    ) : (
                        <div className="mb-2 text-sm font-semibold opacity-90">
                            {quotation.company_name}
                        </div>
                    )}
                    <div className="text-xs uppercase tracking-[0.16em] opacity-80">
                        {quotation.number}
                    </div>
                    <h1 className="mt-1 font-display text-3xl font-bold">{quotation.title}</h1>
                    {quotation.trip_title || quotation.destination ? (
                        <p className="mt-2 text-sm opacity-90">
                            {quotation.trip_title || quotation.destination}
                            {quotation.duration_days
                                ? ` · ${quotation.duration_days} days`
                                : ''}
                            {quotation.travellers ? ` · ${quotation.travellers} travellers` : ''}
                        </p>
                    ) : null}
                    <div className="mt-6 flex flex-wrap gap-2">
                        <a
                            href={pdf_url}
                            className="rounded-md px-4 py-2 text-sm font-bold text-ink"
                            style={{ background: accent }}
                        >
                            Download PDF
                        </a>
                        {quotation.whatsapp_url ? (
                            <a
                                href={quotation.whatsapp_url}
                                target="_blank"
                                rel="noreferrer"
                                className="rounded-md border border-white/40 px-4 py-2 text-sm font-semibold text-white hover:bg-white/10"
                            >
                                WhatsApp
                            </a>
                        ) : null}
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-2xl space-y-6 px-4 py-8">
                <section className="rounded-xl border border-slate-200 bg-white p-5">
                    <div className="text-xs font-bold uppercase tracking-wide text-slate-500">
                        Prepared for
                    </div>
                    <div className="mt-1 text-lg font-semibold text-slate-900">
                        {quotation.customer_name || 'Valued customer'}
                    </div>
                    {quotation.customer_company ? (
                        <div className="text-sm text-slate-600">{quotation.customer_company}</div>
                    ) : null}
                    {quotation.valid_until ? (
                        <div className="mt-2 text-xs text-slate-500">
                            Valid until {quotation.valid_until}
                        </div>
                    ) : null}
                </section>

                <section className="overflow-hidden rounded-xl border border-slate-200 bg-white">
                    <table className="w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-3 font-semibold">Description</th>
                                <th className="px-4 py-3 text-right font-semibold">Qty</th>
                                <th className="px-4 py-3 text-right font-semibold">Amount</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {(quotation.line_items || []).map((item, i) => (
                                <tr key={i}>
                                    <td className="px-4 py-3 text-slate-800">{item.description}</td>
                                    <td className="px-4 py-3 text-right text-slate-600">{item.qty}</td>
                                    <td className="px-4 py-3 text-right font-medium text-slate-900">
                                        {money(item.amount, quotation.currency)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <div className="space-y-1 border-t border-slate-100 px-4 py-4 text-sm">
                        <div className="flex justify-between text-slate-600">
                            <span>Subtotal</span>
                            <span>{money(quotation.subtotal, quotation.currency)}</span>
                        </div>
                        {Number(quotation.discount_amount) > 0 ? (
                            <div className="flex justify-between text-slate-600">
                                <span>Discount</span>
                                <span>-{money(quotation.discount_amount, quotation.currency)}</span>
                            </div>
                        ) : null}
                        <div className="flex justify-between text-slate-600">
                            <span>Tax ({quotation.tax_percent}%)</span>
                            <span>{money(quotation.tax_amount, quotation.currency)}</span>
                        </div>
                        <div
                            className="flex justify-between pt-2 text-base font-bold"
                            style={{ color: primary }}
                        >
                            <span>Total</span>
                            <span>{money(quotation.total, quotation.currency)}</span>
                        </div>
                    </div>
                </section>

                {(quotation.inclusions || []).length > 0 ? (
                    <section className="rounded-xl border border-slate-200 bg-white p-5">
                        <h2 className="font-display text-lg font-bold" style={{ color: primary }}>
                            Inclusions
                        </h2>
                        <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-700">
                            {quotation.inclusions.map((line, i) => (
                                <li key={i}>{line}</li>
                            ))}
                        </ul>
                    </section>
                ) : null}

                {(quotation.exclusions || []).length > 0 ? (
                    <section className="rounded-xl border border-slate-200 bg-white p-5">
                        <h2 className="font-display text-lg font-bold" style={{ color: primary }}>
                            Exclusions
                        </h2>
                        <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-700">
                            {quotation.exclusions.map((line, i) => (
                                <li key={i}>{line}</li>
                            ))}
                        </ul>
                    </section>
                ) : null}

                {quotation.payment_terms ? (
                    <section className="rounded-xl border border-slate-200 bg-white p-5">
                        <h2 className="font-display text-lg font-bold" style={{ color: primary }}>
                            Payment terms
                        </h2>
                        <p className="mt-2 whitespace-pre-wrap text-sm text-slate-700">
                            {quotation.payment_terms}
                        </p>
                    </section>
                ) : null}

                {quotation.notes ? (
                    <section className="rounded-xl border border-slate-200 bg-white p-5">
                        <h2 className="font-display text-lg font-bold" style={{ color: primary }}>
                            Notes
                        </h2>
                        <p className="mt-2 whitespace-pre-wrap text-sm text-slate-700">
                            {quotation.notes}
                        </p>
                    </section>
                ) : null}

                <footer className="pb-10 text-center text-xs text-slate-400">
                    <BrandName /> · Quotation for {quotation.company_name}
                </footer>
            </main>
        </div>
    );
}
